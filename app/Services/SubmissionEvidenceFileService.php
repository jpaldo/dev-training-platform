<?php

namespace App\Services;

use App\Models\SubmissionEvidence;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class SubmissionEvidenceFileService
{
    /** @var array<int, string> MIME types the browser may render inline; everything else gets the preview-unavailable page. */
    private const INLINE_MIME_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/quicktime', 'application/pdf',
        'text/plain', 'text/markdown', 'text/csv', 'application/json', 'application/xml', 'text/xml',
    ];

    /** @var array<int, string> Office MIME types whose text the preview-unavailable page can show. */
    private const OFFICE_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Abort with a 404 unless the evidence has a stored file that still exists.
     */
    public function ensureFileExists(SubmissionEvidence $evidence): void
    {
        abort_unless($evidence->disk && $evidence->path && Storage::disk($evidence->disk)->exists($evidence->path), 404);
    }

    /**
     * Serve the stored file inline when the browser can render it safely; otherwise show the preview-unavailable page,
     * whose download action points at the given URL (the evidence download route by default).
     */
    public function viewResponse(SubmissionEvidence $evidence, ?string $downloadUrl = null): BinaryFileResponse|StreamedResponse|Response
    {
        $this->ensureFileExists($evidence);

        $disk = Storage::disk($evidence->disk);
        $mime = $evidence->mime_type ?: $disk->mimeType($evidence->path) ?: 'application/octet-stream';
        $disposition = in_array(strtolower($mime), self::INLINE_MIME_TYPES, true) ? 'inline' : 'attachment';

        if ($disposition === 'inline' && str_starts_with(strtolower($mime), 'video/') && method_exists($disk, 'path')) {
            return response()->file($disk->path($evidence->path), [
                'Content-Type' => $mime,
                'Content-Disposition' => $this->contentDisposition('inline', $evidence),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if ($disposition === 'attachment') {
            return response()->view('evidence.preview-unavailable', [
                'evidence' => $evidence,
                'mime' => $mime,
                'downloadUrl' => $downloadUrl ?? route('training.evidence.download', $evidence),
                'preview' => method_exists($disk, 'path') ? $this->officePreview($disk->path($evidence->path), $mime) : null,
            ]);
        }

        $stream = $disk->readStream($evidence->path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $this->contentDisposition($disposition, $evidence),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Build a bounded JSON preview of the stored file based on its extension; unreadable files get a 422 message.
     */
    public function previewResponse(SubmissionEvidence $evidence): JsonResponse
    {
        $this->ensureFileExists($evidence);

        $disk = Storage::disk($evidence->disk);
        $filename = $evidence->original_filename ?: 'evidence';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            return match ($extension) {
                'csv' => response()->json($this->csvPreview($disk, $evidence->path)),
                'xlsx' => response()->json($this->xlsxPreview($disk, $evidence->path)),
                'docx' => response()->json($this->docxPreview($disk, $evidence->path)),
                'json' => response()->json($this->textPreview($disk, $evidence->path, true)),
                'txt', 'md', 'log', 'xml' => response()->json($this->textPreview($disk, $evidence->path)),
                default => response()->json(['kind' => 'text', 'content' => 'This file type uses its protected inline source.']),
            };
        } catch (Throwable) {
            return response()->json(['message' => 'Unable to preview this evidence.'], 422);
        }
    }

    private function contentDisposition(string $disposition, SubmissionEvidence $evidence): string
    {
        return $disposition.'; filename="'.addcslashes($evidence->original_filename ?: 'evidence', '"\\').'"';
    }

    /** @return array{kind: string, headers: array<int, string>, rows: array<int, array<int, string>>, truncated?: string} */
    private function csvPreview(object $disk, string $path): array
    {
        $contents = $this->boundedContents($disk, $path, 2_000_000);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false && count($rows) < 101) {
            $rows[] = array_map(static fn (?string $value): string => is_scalar($value) || $value === null ? (string) $value : '', array_slice($row, 0, 30));
        }
        fclose($stream);
        $truncated = count($rows) > 100 || strlen($contents) >= 2_000_000 ? 'Showing the first 100 rows.' : null;
        $headers = array_shift($rows) ?: [];

        return array_filter(['kind' => 'table', 'headers' => $headers, 'rows' => array_slice($rows, 0, 100), 'truncated' => $truncated], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array{kind: string, headers: array<int, string>, rows: array<int, array<int, string>>, truncated: ?string} */
    private function xlsxPreview(object $disk, string $path): array
    {
        $archivePath = method_exists($disk, 'path') ? $disk->path($path) : null;
        if (! is_string($archivePath) || ! class_exists('ZipArchive')) {
            throw new RuntimeException('XLSX preview is unavailable.');
        }
        $archive = new ZipArchive;
        abort_unless($archive->open($archivePath) === true, 422);
        $sharedStrings = [];
        if (($xml = $archive->getFromName('xl/sharedStrings.xml')) !== false) {
            $document = new DOMDocument;
            $document->loadXML(substr($xml, 0, 5_000_000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($document->getElementsByTagName('si') as $item) {
                $sharedStrings[] = trim($item->textContent);
            }
        }
        $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $archive->close();
        if (! is_string($sheetXml)) {
            throw new RuntimeException('Worksheet unavailable.');
        }
        $document = new DOMDocument;
        $document->loadXML(substr($sheetXml, 0, 10_000_000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $rows = [];
        foreach ($document->getElementsByTagName('row') as $rowNode) {
            if (count($rows) >= 101) {
                break;
            }
            $values = [];
            foreach ($rowNode->getElementsByTagName('c') as $cell) {
                $reference = (string) $cell->getAttribute('r');
                preg_match('/^([A-Z]+)/', $reference, $match);
                $column = isset($match[1]) ? $this->columnNumber($match[1]) : count($values);
                if ($column >= 30) {
                    continue;
                }
                while (count($values) < $column) {
                    $values[] = '';
                }
                $value = $cell->getElementsByTagName('v')->item(0)?->textContent ?? $cell->getElementsByTagName('t')->item(0)?->textContent ?? '';
                if ($cell->getAttribute('t') === 's' && isset($sharedStrings[(int) $value])) {
                    $value = $sharedStrings[(int) $value];
                }
                $values[$column] = trim($value);
            }
            $rows[] = array_pad(array_slice($values, 0, 30), max(1, min(30, count($values))), '');
        }
        $headers = array_shift($rows) ?: [];

        return ['kind' => 'workbook', 'headers' => $headers, 'rows' => array_slice($rows, 0, 100), 'truncated' => count($rows) >= 100 ? 'Showing the first 100 rows.' : null];
    }

    /** @return array{kind: string, content: string, truncated: ?string} */
    private function docxPreview(object $disk, string $path): array
    {
        $archivePath = method_exists($disk, 'path') ? $disk->path($path) : null;
        if (! is_string($archivePath) || ! class_exists('ZipArchive')) {
            throw new RuntimeException('DOCX preview is unavailable.');
        }
        $archive = new ZipArchive;
        abort_unless($archive->open($archivePath) === true, 422);
        $xml = $archive->getFromName('word/document.xml');
        $archive->close();
        if (! is_string($xml)) {
            throw new RuntimeException('Document content unavailable.');
        }
        $document = new DOMDocument;
        $document->loadXML(substr($xml, 0, 10_000_000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $content = trim(preg_replace('/\s+/', ' ', $document->textContent ?? '') ?: '');

        return ['kind' => 'text', 'content' => mb_substr($content, 0, 200_000), 'truncated' => strlen($content) > 200_000 ? 'Preview truncated for safety.' : null];
    }

    /** @return array{kind: string, content: string, truncated: ?string} */
    private function textPreview(object $disk, string $path, bool $prettyJson = false): array
    {
        $content = $this->boundedContents($disk, $path, 200_000);
        if ($prettyJson) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $content = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return ['kind' => 'text', 'content' => $content, 'truncated' => strlen($content) >= 200_000 ? 'Preview truncated for safety.' : null];
    }

    private function boundedContents(object $disk, string $path, int $limit): string
    {
        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), 404);
        $contents = stream_get_contents($stream, $limit + 1);
        fclose($stream);

        return is_string($contents) ? $contents : '';
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return max(0, $number - 1);
    }

    private function officePreview(string $path, string $mime): ?string
    {
        if (! class_exists('ZipArchive') || ! in_array(strtolower($mime), self::OFFICE_MIME_TYPES, true)) {
            return null;
        }

        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            return null;
        }

        $entry = str_contains(strtolower($mime), 'wordprocessingml') ? 'word/document.xml' : 'xl/sharedStrings.xml';
        $xml = $archive->getFromName($entry);
        $archive->close();
        if (! is_string($xml) || $xml === '') {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8')));

        return $text === '' ? null : mb_substr($text, 0, 200000);
    }
}
