<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\User;
use App\Models\ValidationSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TrainingExportService
{
    public function dailyLogs(User $participant, ?int $weekNumber = null): string
    {
        $logs = DailyLog::query()->with(['activity.week'])->where('user_id', $participant->id)
            ->when($weekNumber, fn (Builder $q): Builder => $q->whereHas('activity.week', fn (Builder $week): Builder => $week->where('week_number', $weekNumber)))
            ->orderBy('logged_on')->orderBy('id')->get();

        return $this->csv(['Daily Log ID', 'Participant', 'QA Path', 'Training Week', 'Log Date', 'Work/Activity', 'Daily Log'], $logs->map(fn (DailyLog $log): array => [$log->id, $participant->name, $participant->trainingPathLabel(), $log->activity?->week?->week_number, $log->logged_on?->toDateString(), $log->activity?->title ?? 'General training', $log->content]));
    }

    public function activity(User $participant, int $activityId): string
    {
        $activity = Activity::query()->with('week')->findOrFail($activityId);
        $requirements = $activity->requirements()->where('requirement_type', 'submission_field')->where(function (Builder $query) use ($participant): void {
            $query->whereNull('participant_path')->orWhereIn('participant_path', $participant->eligibleTrainingPaths());
        })->orderBy('position')->get()->filter(fn (ActivityRequirement $requirement): bool => ! ($requirement->configuration['deprecated'] ?? false));
        $fieldKeys = $requirements->mapWithKeys(fn (ActivityRequirement $requirement): array => [$requirement->configuration['key'] => $requirement->configuration['label'] ?? $requirement->title])->all();
        $submission = ActivitySubmission::query()
            ->with(['participant', 'activity.week', 'versions.validationSubmission', 'versions.activityScore.dimensions', 'versions.reviews.reviewer', 'versions.evidence.googleDriveExport'])
            ->where('user_id', $participant->id)
            ->where('activity_id', $activityId)
            ->latest('attempt_number')
            ->first();
        $version = $submission?->versions
            ->sortByDesc('version_number')
            ->first(fn (SubmissionVersion $candidate): bool => ! $candidate->isDraft())
            ?? $submission?->versions->sortByDesc('version_number')->first();
        $rows = collect();
        if ($version && $submission) {
            $score = $version->activityScore;
            $dims = $score?->dimensions?->keyBy('dimension') ?? collect();
            $review = $version->reviews->sortByDesc('reviewed_at')->first();
            $metadata = [
                'Participant' => $participant->name,
                'QA Path' => $participant->training_path?->value,
                'Week' => $submission->activity->week->week_number,
                'Activity' => $submission->activity->title,
                'Submission ID' => $submission->id,
                'Attempt' => $submission->attempt_number,
                'Submission Version' => $version->version_number,
                'Submission Status' => $submission->status,
                'Submitted At' => $version->submitted_at,
                'Reviewed At' => $review?->reviewed_at,
                'Reviewer' => $review?->reviewer?->name,
                'Review Decision' => $review?->decision->value,
                'Coach Feedback' => $review?->comments,
                'Activity Score' => $score?->total_score,
                'D1' => $dims->get('D1')?->points,
                'D2' => $dims->get('D2')?->points,
                'D3' => $dims->get('D3')?->points,
                'D4' => $dims->get('D4')?->points,
                'D5' => $dims->get('D5')?->points,
                'Score Band' => $score?->performance_band,
                'Score Published At' => $score?->updated_at,
                'Participant Notes' => $version->participant_note,
            ];
            foreach ($metadata as $field => $value) {
                $rows->push(['Metadata', $field, $value]);
            }
            foreach ($fieldKeys as $key => $label) {
                $rows->push(['Activity Input', $label, $version->field_values[$key] ?? null]);
            }
            $validation = $version->validationSubmission;
            if ($validation) {
                foreach ($validation->getAttributes() as $key => $value) {
                    if (in_array($key, ['id', 'user_id', 'created_at', 'updated_at'], true) || blank($value)) {
                        continue;
                    }
                    $rows->push(['Validation Lab', str($key)->replace('_', ' ')->title()->toString(), $value]);
                }
            }
            foreach ($version->evidence as $evidence) {
                $rows->push(['Evidence', 'Evidence Type', $evidence->evidence_type]);
                $rows->push(['Evidence', 'Evidence Title', $evidence->title]);
                $rows->push(['Evidence', 'Original Filename', $evidence->original_filename]);
                $rows->push(['Evidence', 'URL', $evidence->url]);
                $rows->push(['Evidence', 'Drive File ID', $evidence->googleDriveExport?->drive_file_id]);
                $rows->push(['Evidence', 'Export Status', $evidence->googleDriveExport?->status?->value]);
            }
        }

        return $this->csv(['Section', 'Field', 'Participant Input'], $rows);
    }

    public function trainingProgress(User $participant): string
    {
        $program = Program::query()->where('is_active', true)->with('weeks')->first();
        $rows = collect();
        $progression = app(WeekProgressionService::class);
        if ($program) {
            foreach ($program->weeks->whereIn('week_number', [2, 3, 4]) as $week) {
                $required = $week->activities()->where('is_active', true)->count();
                $completed = $participant->activitySubmissions()->whereHas('activity', fn (Builder $q): Builder => $q->where('training_week_id', $week->id))->whereIn('status', ['submitted', 'resubmitted', 'approved', 'completed'])->count();
                $logs = $participant->dailyLogs()->whereHas('activity', fn (Builder $q): Builder => $q->where('training_week_id', $week->id))->count();
                $gateStatus = $progression->isWeekPassed($participant, $week) ? 'passed' : ($progression->canAccessWeek($participant, $week) ? 'in progress' : 'locked');
                $weekStatus = $gateStatus === 'passed' ? 'Completed' : ($gateStatus === 'locked' ? 'Locked' : 'In progress');
                $nextAction = $weekStatus === 'Completed' ? 'Continue to next week' : ($weekStatus === 'Locked' ? 'Pass previous Gate' : ($completed < $required ? 'Complete activities' : ($logs < 5 ? 'Complete daily logs' : 'Complete Gate requirements')));
                $rows->push([$participant->name, $participant->training_path?->value, $week->week_number, $weekStatus, $completed, $required, $logs, 5, $gateStatus, $required ? round($completed / $required * 100).'%' : '0%', $nextAction]);
            }
        }

        return $this->csv(['Participant', 'QA Path', 'Week', 'Week Status', 'Activities Completed', 'Activities Required', 'Daily Logs Completed', 'Daily Logs Required', 'Gate Status', 'Overall Progress', 'Next Action'], $rows);
    }

    public function gateProgress(User $participant): string
    {
        $gates = TrainingGate::query()->with('week')->whereHas('week', fn (Builder $q): Builder => $q->whereIn('week_number', [2, 3, 4]))->get();

        return $this->csv(['Participant', 'Week', 'Gate', 'Status'], $gates->map(fn (TrainingGate $gate): array => [$participant->name, $gate->week->week_number, $gate->title, $participant->gateEvaluations()->where('training_gate_id', $gate->id)->value('status')?->value ?? 'not evaluated']));
    }

    public function activityScores(User $participant): string
    {
        $versions = SubmissionVersion::query()->with(['submission.activity.week', 'activityScore.dimensions'])->whereHas('submission', fn (Builder $q): Builder => $q->where('user_id', $participant->id))->get();

        return $this->csv(['Participant', 'Week', 'Activity', 'Activity Score', 'Score Band', 'Score Published At', 'D1 - Deliverable Quality', 'D2 - Validation & Verification', 'D3 - AI-Augmented Workflow', 'D4 - Judgment & Iteration', 'D5 - Evidence & Reasoning'], $versions->map(function (SubmissionVersion $version) use ($participant): array {
            $score = $version->activityScore;
            $dims = $score?->dimensions?->keyBy('dimension') ?? collect();

            return [$participant->name, $version->submission->activity->week->week_number, $version->submission->activity->title, $score?->total_score, $score?->performance_band, $score?->updated_at, $dims->get('D1')?->points, $dims->get('D2')?->points, $dims->get('D3')?->points, $dims->get('D4')?->points, $dims->get('D5')?->points];
        }));
    }

    public function labRecords(User $participant): string
    {
        $records = $participant->validationSubmissions()->latest('id')->get();
        $headers = ['Record ID', 'Artifact Code', 'Artifact Name', 'Feature / Area', 'Artifact Type', 'AI Tool', 'Exact Prompt', 'AI-Generated Artifact', 'Source Result', 'Source Details', 'Scope Result', 'Scope Details', 'Spec Result', 'Spec Details', 'Cross-check Result', 'Cross-check Details', 'Run Result', 'Run Details', 'Log Result', 'Log Details', 'Evidence Record', 'What Did the Validation Catch?', 'Decision', 'Created At', 'Updated At', 'Evidence Count', 'Evidence File Names'];
        $rows = $records->map(fn (ValidationSubmission $record): array => [$record->id, $record->artifact_code, $record->artifact_name, $record->feature_area, $record->artifact_type, $record->ai_tool, $record->prompt, $record->artifact_content, $record->source_status?->value, $record->source_notes, $record->scope_status?->value, $record->scope_notes, $record->spec_status?->value, $record->spec_notes, $record->cross_check_status?->value, $record->cross_check_notes, $record->run_status?->value, $record->run_notes, $record->log_status?->value, $record->log_notes, $record->evidence_notes, $record->finding, $record->decision?->value, $record->created_at, $record->updated_at, count($record->evidence_files ?? []), collect($record->evidence_files ?? [])->pluck('original_filename')->filter()->implode(' | ')])->all();

        return $this->csv($headers, collect($rows));
    }

    public function csv(array $header, Collection $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $header);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);

        return stream_get_contents($stream);
    }
}
