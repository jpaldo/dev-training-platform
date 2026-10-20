<?php

namespace App\Services;

use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class SubmissionEvidenceContextService
{
    public const MAX_TEXT_PER_FILE = 20000;

    public const MAX_TEXT_TOTAL = 60000;

    public const MAX_IMAGE_BYTES = 1048576;

    public const MAX_IMAGE_TOTAL_BYTES = 4194304;

    public const MAX_IMAGES = 4;

    /** @return array{items:array<int,array<string,mixed>>,statuses:array<int,array<string,mixed>>} */
    public function build(SubmissionVersion $version, string $model): array
    {
        $items = [];
        $statuses = [];
        $textBudget = self::MAX_TEXT_TOTAL;
        $imageCount = 0;
        $imageBytes = 0;

        foreach ($version->evidence()->orderBy('id')->get() as $evidence) {
            $result = $this->inspect($evidence, $model, $textBudget, $imageCount, $imageBytes);
            $items[] = $result['item'];
            $statuses[] = $result['status'];
        }

        return compact('items', 'statuses');
    }

    /** @return array{item:array<string,mixed>,status:array<string,mixed>} */
    private function inspect(SubmissionEvidence $evidence, string $model, int &$textBudget, int &$imageCount, int &$imageBytes): array
    {
        $name = (string) ($evidence->original_filename ?: $evidence->title ?: 'evidence');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = ['evidence_id' => $evidence->id, 'filename' => $name, 'mime_type' => $evidence->mime_type];
        $status = $base + ['status' => 'unsupported', 'strategy' => 'metadata_only'];
        $item = $base + ['status' => 'unsupported', 'strategy' => 'metadata_only'];

        if (blank($evidence->path) && filled($evidence->content) && $textBudget > 0) {
            $content = mb_substr((string) $evidence->content, 0, min(self::MAX_TEXT_PER_FILE, $textBudget));
            $textBudget -= strlen($content);
            $item = array_merge($item, ['status' => 'inspected', 'strategy' => 'stored_text', 'content' => $content]);
            $status = array_merge($status, ['status' => 'inspected', 'strategy' => 'stored_text', 'characters' => strlen($content)]);

            return compact('item', 'status');
        }

        if (! $evidence->path || ! Storage::disk($evidence->disk ?: 'local')->exists($evidence->path)) {
            $status['status'] = 'extraction_failed';
            $status['strategy'] = 'missing_file';

            return compact('item', 'status');
        }

        $disk = Storage::disk($evidence->disk ?: 'local');
        $size = (int) ($evidence->size_bytes ?: $disk->size($evidence->path));
        $textExtensions = ['txt', 'md', 'csv', 'json', 'log', 'xml'];
        if (in_array($extension, $textExtensions, true)) {
            if ($textBudget <= 0) {
                return $this->omitted($base, $item, 'context_limit');
            }
            $limit = min(self::MAX_TEXT_PER_FILE, $textBudget);
            $stream = $disk->readStream($evidence->path);
            $content = is_resource($stream) ? stream_get_contents($stream, $limit) : false;
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! is_string($content)) {
                $status['status'] = 'extraction_failed';
                $status['strategy'] = 'text_extraction';

                return compact('item', 'status');
            }
            $textBudget -= strlen($content);
            $item = array_merge($item, ['status' => 'inspected', 'strategy' => 'text_extraction', 'content' => $content]);
            $status = array_merge($status, ['status' => 'inspected', 'strategy' => 'text_extraction', 'characters' => strlen($content)]);

            return compact('item', 'status');
        }

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            if (! $this->supportsVision($model) || $imageCount >= self::MAX_IMAGES || $size > self::MAX_IMAGE_BYTES || $imageBytes + $size > self::MAX_IMAGE_TOTAL_BYTES) {
                $status['status'] = $size > self::MAX_IMAGE_BYTES ? 'omitted_due_to_context_limit' : 'unsupported';
                $status['strategy'] = 'image_multimodal';

                return compact('item', 'status');
            }
            $stream = $disk->readStream($evidence->path);
            $bytes = is_resource($stream) ? stream_get_contents($stream, self::MAX_IMAGE_BYTES + 1) : false;
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! is_string($bytes) || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                return $this->omitted($base, $item, 'context_limit');
            }
            $imageCount++;
            $imageBytes += strlen($bytes);
            $mime = $evidence->mime_type ?: 'image/png';
            $item = array_merge($item, ['status' => 'inspected', 'strategy' => 'image_multimodal', 'image_url' => 'data:'.$mime.';base64,'.base64_encode($bytes)]);
            $status = array_merge($status, ['status' => 'inspected', 'strategy' => 'image_multimodal']);

            return compact('item', 'status');
        }

        if ($extension === 'pdf') {
            try {
                $path = $disk->path($evidence->path);
            } catch (Throwable) {
                $status['strategy'] = 'pdf_text_extraction';

                return compact('item', 'status');
            }
            $text = $this->extractPdf($path, $textBudget);
            if ($text !== null) {
                $textBudget -= strlen($text);
                $item = array_merge($item, ['status' => 'inspected', 'strategy' => 'pdf_text_extraction', 'content' => $text]);
                $status = array_merge($status, ['status' => 'inspected', 'strategy' => 'pdf_text_extraction', 'characters' => strlen($text)]);
            } else {
                $status['status'] = 'extraction_failed';
                $status['strategy'] = 'pdf_text_extraction';
            }

            return compact('item', 'status');
        }

        if (in_array($extension, ['docx', 'xlsx'], true)) {
            try {
                $path = $disk->path($evidence->path);
            } catch (Throwable) {
                $status['strategy'] = $extension.'_text_extraction';

                return compact('item', 'status');
            }
            $text = $this->extractOfficeXml($path, $textBudget);
            if ($text !== null) {
                $textBudget -= strlen($text);
                $item = array_merge($item, ['status' => 'inspected', 'strategy' => $extension.'_text_extraction', 'content' => $text]);
                $status = array_merge($status, ['status' => 'inspected', 'strategy' => $extension.'_text_extraction', 'characters' => strlen($text)]);
            } else {
                $status['status'] = 'extraction_failed';
                $status['strategy'] = $extension.'_text_extraction';
            }

            return compact('item', 'status');
        }

        if (in_array($extension, ['mp4', 'mov', 'webm', 'm4v'], true)) {
            $status['status'] = 'unsupported';
            $status['strategy'] = 'video_not_processed';

            return compact('item', 'status');
        }

        return compact('item', 'status');
    }

    private function supportsVision(string $model): bool
    {
        return (bool) preg_match('/(?:gpt-4o|gpt-4\.1|gemini|claude-3|claude-4|qwen-vl|llama-.*vision)/i', $model);
    }

    private function extractPdf(string $path, int $budget): ?string
    {
        if ($budget <= 0 || ! function_exists('proc_open')) {
            return null;
        }
        $command = 'pdftotext -layout '.escapeshellarg($path).' -';
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        $output = stream_get_contents($pipes[1], min(self::MAX_TEXT_PER_FILE, $budget));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return $exit === 0 && is_string($output) && trim($output) !== '' ? $output : null;
    }

    private function extractOfficeXml(string $path, int $budget): ?string
    {
        if ($budget <= 0) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $parts = [];
        $collected = 0;
        $limit = min(self::MAX_TEXT_PER_FILE, $budget);
        for ($i = 0; $i < $zip->numFiles && $collected < $limit; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat || ! preg_match('/\.(xml|rels)$/i', $stat['name'])) {
                continue;
            }
            $xml = $zip->getFromIndex($i, min(100000, (int) $stat['size']));
            if (is_string($xml)) {
                $part = trim(strip_tags($xml));
                $parts[] = mb_substr($part, 0, max(0, $limit - $collected));
                $collected += strlen($parts[array_key_last($parts)]);
            }
        }
        $zip->close();
        $text = trim(implode("\n", $parts));

        return $text !== '' ? mb_substr($text, 0, $limit) : null;
    }

    private function omitted(array $base, array $item, string $reason): array
    {
        $status = $base + ['status' => 'omitted_due_to_context_limit', 'strategy' => $reason];
        $item += ['status' => 'omitted_due_to_context_limit', 'strategy' => $reason];

        return compact('item', 'status');
    }
}
