<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionEvidenceRequest;
use App\Jobs\ExportActivityJob;
use App\Models\Activity;
use App\Models\EvidenceRequirement;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\GoogleDriveReadinessService;
use App\Services\SubmissionWorkflowService;
use App\Services\TrainingSyncCoordinator;
use App\Services\WeekProgressionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmissionEvidenceController extends Controller
{
    public function store(StoreSubmissionEvidenceRequest $request, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $submissionVersion, WeekProgressionService $progression, SubmissionWorkflowService $workflow, TrainingSyncCoordinator $syncs, GoogleDriveReadinessService $readiness): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->role === 'participant' && $readiness->enforced() && ! $readiness->isReady($user), 403, $readiness->message($user));
        $progression->ensureCanAccessActivity($user, $trainingWeek, $activity);
        $progression->ensureCanEditActivity($user, $trainingWeek, $activity);
        abort_unless($submissionVersion->submission->activity_id === $activity->id, 404);
        Gate::authorize('update', $submissionVersion);
        $workflow->ensureDraft($user, $submissionVersion);
        $data = $request->validated();
        $requirement = EvidenceRequirement::query()->whereKey($data['evidence_requirement_id'])->whereHas('activityRequirement', function (Builder $query) use ($activity, $user): void {
            $query->where('activity_id', $activity->id)->where(function (Builder $q) use ($user): void {
                $q->whereNull('participant_path')->orWhereIn('participant_path', $user->eligibleTrainingPaths());
            });
        })->firstOrFail();
        $type = $requirement->evidence_type;
        if ($type === 'text' && blank($data['content'] ?? null) && ! ($request->hasFile('file') || $request->hasFile('files'))) {
            throw ValidationException::withMessages(['content' => 'Text evidence is required.']);
        }
        if (in_array($type, ['link', 'url', 'video', 'repository'], true) && blank($data['url'] ?? null) && ! ($request->hasFile('file') || $request->hasFile('files'))) {
            throw ValidationException::withMessages(['url' => 'A URL is required for link evidence.']);
        }
        if (in_array($type, ['file', 'screenshot', 'asset'], true) && ! ($request->hasFile('file') || $request->hasFile('files'))) {
            throw ValidationException::withMessages(['file' => 'A file is required for this evidence.']);
        }
        $files = $request->file('files', []);
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }
        $stored = [];
        foreach ($files as $file) {
            $storedPath = $file->store('evidence/'.$user->id.'/'.$submissionVersion->id, 'local');
            try {
                $stored[] = SubmissionEvidence::create([
                    'submission_version_id' => $submissionVersion->id,
                    'evidence_requirement_id' => $requirement->id,
                    'evidence_type' => 'file',
                    'title' => $data['title'] ?? null,
                    'disk' => 'local',
                    'path' => $storedPath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'metadata' => ['submitted_by' => $user->id],
                ]);
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($storedPath);
                throw $exception;
            }
        }
        if ($stored === [] && ($type === 'text' || in_array($type, ['link', 'url', 'video', 'repository'], true))) {
            $stored[] = SubmissionEvidence::create([
                'submission_version_id' => $submissionVersion->id,
                'evidence_requirement_id' => $requirement->id,
                'evidence_type' => $type,
                'title' => $data['title'] ?? null,
                'content' => $type === 'text' ? ($data['content'] ?? null) : null,
                'url' => in_array($type, ['link', 'url', 'video', 'repository'], true) ? ($data['url'] ?? null) : null,
                'metadata' => ['submitted_by' => $user->id],
            ]);
        }
        ExportActivityJob::dispatch($submissionVersion->id);
        $syncs->request($user, 'evidence_added');

        if ($request->expectsJson()) {
            return response()->json(['uploaded' => true, 'evidence_ids' => collect($stored)->pluck('id')->values(), 'filenames' => collect($stored)->pluck('original_filename')->filter()->values(), 'status' => 'Uploaded']);
        }

        return redirect()->route('training.submission.edit', [$trainingWeek, $activity])->with('status', 'Evidence added.');
    }

    public function download(Request $request, SubmissionEvidence $submissionEvidence): mixed
    {
        Gate::authorize('view', $submissionEvidence);
        abort_unless($submissionEvidence->disk && $submissionEvidence->path && Storage::disk($submissionEvidence->disk)->exists($submissionEvidence->path), 404);

        return Storage::disk($submissionEvidence->disk)->download($submissionEvidence->path, $submissionEvidence->original_filename);
    }

    public function view(Request $request, SubmissionEvidence $submissionEvidence): mixed
    {
        Gate::authorize('view', $submissionEvidence);

        return $this->serveView($submissionEvidence);
    }

    public function sharedView(Request $request, SubmissionEvidence $submissionEvidence): mixed
    {
        return $this->serveView($submissionEvidence);
    }

    private function serveView(SubmissionEvidence $submissionEvidence): mixed
    {
        abort_unless($submissionEvidence->disk && $submissionEvidence->path && Storage::disk($submissionEvidence->disk)->exists($submissionEvidence->path), 404);

        $disk = Storage::disk($submissionEvidence->disk);
        $mime = $submissionEvidence->mime_type ?: $disk->mimeType($submissionEvidence->path) ?: 'application/octet-stream';
        $inlineMimes = [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp',
            'video/mp4', 'video/webm', 'video/quicktime', 'application/pdf',
            'text/plain', 'text/markdown', 'text/csv', 'application/json', 'application/xml', 'text/xml',
        ];
        $disposition = in_array(strtolower($mime), $inlineMimes, true) ? 'inline' : 'attachment';

        if ($disposition === 'inline' && str_starts_with(strtolower($mime), 'video/') && method_exists($disk, 'path')) {
            return response()->file($disk->path($submissionEvidence->path), [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.addcslashes($submissionEvidence->original_filename ?: 'evidence', '"\\').'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if ($disposition === 'attachment') {
            return response()->view('evidence.preview-unavailable', [
                'evidence' => $submissionEvidence,
                'mime' => $mime,
                'preview' => method_exists($disk, 'path') ? $this->officePreview($disk->path($submissionEvidence->path), $mime) : null,
            ]);
        }

        $stream = $disk->readStream($submissionEvidence->path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.addcslashes($submissionEvidence->original_filename ?: 'evidence', '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request, SubmissionEvidence $submissionEvidence): JsonResponse
    {
        Gate::authorize('view', $submissionEvidence);

        return $this->previewPayload($submissionEvidence);
    }

    public function sharedPreview(Request $request, SubmissionEvidence $submissionEvidence): JsonResponse
    {
        return $this->previewPayload($submissionEvidence);
    }

    private function previewPayload(SubmissionEvidence $submissionEvidence): JsonResponse
    {
        abort_unless($submissionEvidence->disk && $submissionEvidence->path && Storage::disk($submissionEvidence->disk)->exists($submissionEvidence->path), 404);

        $disk = Storage::disk($submissionEvidence->disk);
        $filename = $submissionEvidence->original_filename ?: 'evidence';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            return match ($extension) {
                'csv' => response()->json($this->csvPreview($disk, $submissionEvidence->path)),
                'xlsx' => response()->json($this->xlsxPreview($disk, $submissionEvidence->path)),
                'docx' => response()->json($this->docxPreview($disk, $submissionEvidence->path)),
                'json' => response()->json($this->textPreview($disk, $submissionEvidence->path, true)),
                'txt', 'md', 'log', 'xml' => response()->json($this->textPreview($disk, $submissionEvidence->path)),
                default => response()->json(['kind' => 'text', 'content' => 'This file type uses its protected inline source.']),
            };
        } catch (Throwable) {
            return response()->json(['message' => 'Unable to preview this evidence.'], 422);
        }
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

    /** @return array{kind: string, headers: array<int, string>, rows: array<int, array<int, string>>, truncated?: string} */
    private function xlsxPreview(object $disk, string $path): array
    {
        $archivePath = method_exists($disk, 'path') ? $disk->path($path) : null;
        if (! is_string($archivePath) || ! class_exists('ZipArchive')) {
            throw new \RuntimeException('XLSX preview is unavailable.');
        }
        $archive = new \ZipArchive;
        abort_unless($archive->open($archivePath) === true, 422);
        $sharedStrings = [];
        if (($xml = $archive->getFromName('xl/sharedStrings.xml')) !== false) {
            $document = new \DOMDocument;
            $document->loadXML(substr($xml, 0, 5_000_000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($document->getElementsByTagName('si') as $item) {
                $sharedStrings[] = trim($item->textContent);
            }
        }
        $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $archive->close();
        if (! is_string($sheetXml)) {
            throw new \RuntimeException('Worksheet unavailable.');
        }
        $document = new \DOMDocument;
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

    /** @return array{kind: string, content: string, truncated?: string} */
    private function docxPreview(object $disk, string $path): array
    {
        $archivePath = method_exists($disk, 'path') ? $disk->path($path) : null;
        if (! is_string($archivePath) || ! class_exists('ZipArchive')) {
            throw new \RuntimeException('DOCX preview is unavailable.');
        }
        $archive = new \ZipArchive;
        abort_unless($archive->open($archivePath) === true, 422);
        $xml = $archive->getFromName('word/document.xml');
        $archive->close();
        if (! is_string($xml)) {
            throw new \RuntimeException('Document content unavailable.');
        }
        $document = new \DOMDocument;
        $document->loadXML(substr($xml, 0, 10_000_000), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $content = trim(preg_replace('/\s+/', ' ', $document->textContent ?? '') ?: '');

        return ['kind' => 'text', 'content' => mb_substr($content, 0, 200_000), 'truncated' => strlen($content) > 200_000 ? 'Preview truncated for safety.' : null];
    }

    /** @return array{kind: string, content: string, truncated?: string} */
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
        if (! class_exists('ZipArchive') || ! in_array(strtolower($mime), [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true)) {
            return null;
        }

        $archive = new \ZipArchive;
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
