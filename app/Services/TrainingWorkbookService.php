<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use ZipArchive;

class TrainingWorkbookService
{
    /**
     * Build the participant's workbook for one week of a training session: one sheet per active activity of the week,
     * each with the participant's latest attempt in that session, or a header-only Activities sheet when the week has
     * none, so the file is always a valid workbook. A fixed number of queries, whatever the number of activities.
     */
    public function forWeek(User $participant, TrainingSession $session, TrainingWeek $week): string
    {
        $activities = $week->activities()
            ->where('is_active', true)
            ->orderBy('position')
            ->with(['requirements' => fn (Relation $query): Relation => $query->where('requirement_type', 'submission_field')->orderBy('position')])
            ->get();
        $latestAttempts = ActivitySubmission::query()
            ->where('training_session_id', $session->id)
            ->where('user_id', $participant->id)
            ->whereIn('activity_id', $activities->modelKeys())
            ->with(['versions' => fn (Relation $query): Relation => $query->orderByDesc('version_number')])
            ->orderByDesc('attempt_number')
            ->get()
            ->unique('activity_id')
            ->keyBy('activity_id');
        $sheets = [];
        $usedNames = [];
        foreach ($activities as $activity) {
            $sheetName = $this->uniqueSheetName($activity->title, $usedNames);
            $usedNames[] = $sheetName;
            $sheets[] = [$sheetName, $this->activityRows($participant, $week, $activity, $latestAttempts->get($activity->id))];
        }
        if ($sheets === []) {
            $sheets[] = ['Activities', [['Participant', 'Employee ID', 'Week', 'Activity']]];
        }

        return $this->xlsx($sheets);
    }

    /**
     * The header row and the participant's row of one activity sheet: the latest submitted version of the attempt (or
     * its newest draft), with the answers of the activity's current fields and of retired fields that version answered.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function activityRows(User $participant, TrainingWeek $week, Activity $activity, ?ActivitySubmission $submission): array
    {
        $allRequirements = $activity->requirements;
        $requirements = $allRequirements->filter(fn (ActivityRequirement $requirement): bool => ! ($requirement->configuration['deprecated'] ?? false));
        $fields = $requirements->mapWithKeys(fn (ActivityRequirement $requirement): array => [$requirement->configuration['key'] => $requirement->configuration['label'] ?? $requirement->title]);
        $headers = ['Participant', 'Employee ID', 'Week', 'Activity', 'Submission ID', 'Attempt', 'Submission Version', 'Submission Status', 'Submitted At', 'Participant Notes', ...$fields->values()->all()];
        $version = $submission?->versions->first(fn (SubmissionVersion $candidate): bool => ! $candidate->isDraft())
            ?? $submission?->versions->first();
        $historicalFields = collect();
        if ($version) {
            $historicalFields = $allRequirements->filter(fn (ActivityRequirement $requirement): bool => ($requirement->configuration['deprecated'] ?? false) && array_key_exists($requirement->configuration['key'], $version->field_values ?? []))->mapWithKeys(fn (ActivityRequirement $requirement): array => [$requirement->configuration['key'] => $requirement->configuration['label'] ?? $requirement->title]);
        }
        $headers = array_merge($headers, $historicalFields->values()->all());
        $row = array_fill(0, count($headers), null);
        if ($submission && $version) {
            $values = [$participant->name, $participant->employee_id, $week->week_number, $activity->title, $submission->id, $submission->attempt_number, $version->version_number, $submission->status->value, $version->submitted_at, $version->participant_note];
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
        $path = tempnam(sys_get_temp_dir(), 'training-xlsx-');
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
