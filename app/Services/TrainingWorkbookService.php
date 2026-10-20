<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\SubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use ZipArchive;

class TrainingWorkbookService
{
    public function __construct(private readonly TrainingExportService $exports) {}

    public function forWeek(User $participant, int $weekNumber): string
    {
        $activities = Activity::query()->with('week')->whereHas('week', fn (Builder $q): Builder => $q->where('week_number', $weekNumber))->where('is_active', true)->orderBy('position')->get();
        $sheets = [];
        $usedNames = [];
        foreach ($activities as $activity) {
            $sheetName = $this->uniqueSheetName($activity->title, $usedNames);
            $usedNames[] = $sheetName;
            $sheets[] = [$sheetName, $this->activityRows($participant, $activity)];
        }
        $sheets[] = ['Daily Logs', $this->dailyLogRows($participant, $weekNumber)];

        return $this->xlsx($sheets);
    }

    /** Build the reporting-only Week 1 workbook for General Training logs. */
    public function forGeneralTraining(User $participant): string
    {
        return $this->xlsx([['Daily Logs', $this->generalDailyLogRows($participant)]]);
    }

    /** Build a single-sheet workbook from an existing tabular report. */
    public function fromCsv(string $sheetName, string $csv): string
    {
        $rows = [];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $this->xlsx([[$this->uniqueSheetName($sheetName, []), $rows]]);
    }

    private function activityRows(User $participant, Activity $activity): array
    {
        $requirements = $activity->requirements()->where('requirement_type', 'submission_field')->where(fn (Builder $q): Builder => $q->whereNull('participant_path')->orWhereIn('participant_path', $participant->eligibleTrainingPaths()))->orderBy('position')->get()->filter(fn (ActivityRequirement $r): bool => ! ($r->configuration['deprecated'] ?? false));
        $allRequirements = $activity->requirements()->where('requirement_type', 'submission_field')->where(fn (Builder $q): Builder => $q->whereNull('participant_path')->orWhereIn('participant_path', $participant->eligibleTrainingPaths()))->orderBy('position')->get();
        $fields = $requirements->mapWithKeys(fn (ActivityRequirement $r): array => [$r->configuration['key'] => $r->configuration['label'] ?? $r->title]);
        $labHeaders = $activity->week->week_number === 2 ? ['QA Validation Lab Record', 'Artifact Code', 'Artifact Name'] : [];
        $headers = ['Participant', 'QA Path', 'Week', 'Activity', 'Submission ID', 'Attempt', 'Submission Version', 'Submission Status', 'Submitted At', 'Participant Notes', ...$labHeaders, ...$fields->values()->all()];
        $submission = ActivitySubmission::query()
            ->where('user_id', $participant->id)
            ->where('activity_id', $activity->id)
            ->with(['activity.week', 'versions' => fn (Relation $query): Relation => $query->with(['validationSubmission'])->orderByDesc('version_number')])
            ->latest('attempt_number')
            ->first();
        $version = $submission?->versions->first(fn (SubmissionVersion $candidate): bool => $candidate->status !== 'draft')
            ?? $submission?->versions->first();
        $version?->setRelation('submission', $submission);
        if ($activity->week->week_number === 3) {
            return $this->weekThreeActivityRows($participant, $activity, $version);
        }
        $historicalFields = collect();
        if ($version) {
            $historicalFields = $allRequirements->filter(fn (ActivityRequirement $requirement): bool => ($requirement->configuration['deprecated'] ?? false) && array_key_exists($requirement->configuration['key'], $version->field_values ?? []))->mapWithKeys(fn (ActivityRequirement $requirement): array => [$requirement->configuration['key'] => $requirement->configuration['label'] ?? $requirement->title]);
        }
        $headers = array_merge($headers, $historicalFields->values()->all());
        $row = array_fill(0, count($headers), null);
        if ($version) {
            $values = [$participant->name, $participant->trainingPathLabel(), $activity->week->week_number, $activity->title, $version->submission->id, $version->submission->attempt_number, $version->version_number, $version->submission->status, $version->submitted_at, $version->participant_note];
            if ($labHeaders !== []) {
                $lab = $version->validationSubmission;
                array_push($values, $lab?->id, $lab?->artifact_code, $lab?->artifact_name);
            }
            foreach ($fields as $key => $label) {
                $values[] = $version->field_values[$key] ?? null;
            }
            foreach ($historicalFields as $key => $label) {
                $values[] = $version->field_values[$key] ?? null;
            }
            $row = $values;
        }

        return [$headers, $row];
    }

    /** Serialize the immutable Week 3 workspace checkpoint without exporting legacy field requirements. */
    private function weekThreeActivityRows(User $participant, Activity $activity, ?SubmissionVersion $version): array
    {
        $headers = ['Participant', 'QA Path', 'Week', 'Activity', 'Submission ID', 'Attempt', 'Submission Version', 'Submission Status', 'Submitted At', 'Participant Notes', 'Findings / RCA', 'Tests', 'Fix Validation', 'Regression Analysis'];
        $row = array_fill(0, count($headers), null);
        if (! $version) {
            return [$headers, $row];
        }

        $snapshot = $version->field_values['week_three_snapshot'] ?? [];
        $findings = collect($snapshot['findings'] ?? []);
        $tests = $findings->flatMap(fn (mixed $finding): mixed => $finding['tests'] ?? [])->values()->all();
        $fixes = collect($tests)->map(fn (mixed $test): mixed => $test['fix_validation'] ?? null)->filter()->values()->all();
        $regression = collect($snapshot)->only(['regression_areas', 'regression_issues', 'coverage_gaps', 'final_conclusion'])->all();
        $values = [$participant->name, $participant->trainingPathLabel(), 3, $activity->title, $version->submission->id, $version->submission->attempt_number, $version->version_number, $version->submission->status, $version->submitted_at, $version->participant_note];
        $values[] = json_encode($findings->map(fn (mixed $finding): array => collect($finding)->except(['tests'])->all())->values()->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $values[] = json_encode($tests, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $values[] = json_encode($fixes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $values[] = json_encode($regression, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$headers, $values];
    }

    public function dailyLogs(User $participant): string
    {
        return $this->fromCsv('Daily Logs', $this->exports->dailyLogs($participant));
    }

    private function dailyLogRows(User $participant, int $weekNumber): array
    {
        $logs = DailyLog::query()->with('activity.week')->where('user_id', $participant->id)->whereHas('activity.week', fn (Builder $q): Builder => $q->where('week_number', $weekNumber))->oldest('logged_on')->get();
        $headers = ['Date', 'Participant', 'QA Path', 'Week', 'Activity', 'Daily Log', 'Created At', 'Updated At'];
        $rows = $logs->map(fn (DailyLog $log): array => [$log->logged_on, $participant->name, $participant->trainingPathLabel(), $log->activity?->week?->week_number, $log->activity?->title ?? 'General training', $log->content, $log->created_at, $log->updated_at])->all();

        return [$headers, ...$rows];
    }

    private function generalDailyLogRows(User $participant): array
    {
        $logs = DailyLog::query()
            ->where('user_id', $participant->id)
            ->whereNull('activity_id')
            ->oldest('logged_on')
            ->oldest('id')
            ->get();
        $headers = ['Date', 'Participant', 'QA Path', 'Week', 'Activity', 'Daily Log', 'Created At', 'Updated At'];
        $rows = $logs->map(fn (DailyLog $log): array => [$log->logged_on, $participant->name, $participant->trainingPathLabel(), 'General Training', 'General training', $log->content, $log->created_at, $log->updated_at])->all();

        return [$headers, ...$rows];
    }

    private function sheetName(string $name): string
    {
        $name = preg_replace('~[\\/?*\[\]:]~', '', trim($name)) ?: 'Activity';

        return mb_substr($name, 0, 31);
    }

    private function uniqueSheetName(string $name, array $used): string
    {
        $base = $this->sheetName($name);
        $candidate = $base;
        $suffix = 2;
        while (in_array($candidate, $used, true)) {
            $tail = ' ('.$suffix++.')';
            $candidate = mb_substr($base, 0, 31 - mb_strlen($tail)).$tail;
        }

        return $candidate;
    }

    private function xlsx(array $sheets): string
    {
        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'qa-xlsx-');
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.implode('', array_map(fn (int $i): string => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, count($sheets)))).'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach (array_keys($sheets) as $i) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $rels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $workbook = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($sheets as $i => [$name]) {
            $workbook .= '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }
        $zip->addFromString('xl/workbook.xml', $workbook.'</sheets></workbook>');
        foreach ($sheets as $i => [, $rows]) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->sheetXml($rows));
        }
        $zip->close();
        $content = file_get_contents($path);
        @unlink($path);

        return $content;
    }

    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><autoFilter ref="A1:'.$this->column(count($rows[0] ?? [])).count($rows).'"/><sheetData>';
        foreach ($rows as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $value) {
                $ref = $this->column($c + 1).($r + 1);
                $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) ($value ?? '');
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            } $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function column(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }
}
