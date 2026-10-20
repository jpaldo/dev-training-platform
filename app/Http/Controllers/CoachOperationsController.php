<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignWeekFourPairRequest;
use App\Http\Requests\ExportCoachReportRequest;
use App\Http\Requests\FilterCoachGatesRequest;
use App\Http\Requests\FilterCoachParticipantsRequest;
use App\Http\Requests\FilterCoachReportsRequest;
use App\Http\Requests\FilterParticipantDailyLogsRequest;
use App\Http\Requests\FilterReviewQueueRequest;
use App\Http\Requests\StorePartnerFeedbackRequest;
use App\Http\Requests\StoreSubmissionReviewRequest;
use App\Http\Requests\WaiveWeekFourPeerInterviewRequest;
use App\Jobs\ExportActivityJob;
use App\Models\Activity;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\DailyLog;
use App\Models\GateEvaluation;
use App\Models\Program;
use App\Models\SubmissionReview;
use App\Models\SubmissionVersion;
use App\Models\TrainingGate;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Models\WeekFourInterviewPair;
use App\Services\CoachReportService;
use App\Services\SubmissionAnswerPresenter;
use App\Services\TrainingSyncCoordinator;
use App\Services\WeekFourInterviewService;
use App\Services\WeekFourReviewPresenter;
use App\Services\WeekProgressionService;
use App\Services\WeekTwoGateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoachOperationsController extends Controller
{
    public function dashboard(CoachReportService $reports, WeekProgressionService $progression): View
    {
        $participants = $reports->participants()->get();
        $program = Program::query()->where('is_active', true)->firstOrFail();
        $stats = ['participants' => $participants->count(), 'completed' => $progression->completedParticipantIds($participants, $program)->count(), 'needs_review' => $reports->reviewQueue()->whereIn('status', ['submitted', 'resubmitted', 'under_review'])->count(), 'needs_revision' => $reports->reviewQueue()->where('status', 'needs_revision')->count(), 'at_risk' => $reports->atRisk()->count()];

        return view('coach.dashboard', compact('stats'));
    }

    public function participants(FilterCoachParticipantsRequest $request, CoachReportService $reports): View
    {
        $filters = $request->validated();
        $items = $reports->participants($filters)->paginate(25)->withQueryString();

        $pairs = WeekFourInterviewPair::query()->where('status', 'active')->with(['participantOne.assignedCoaches', 'participantTwo.assignedCoaches'])->get();
        $pairParticipants = User::query()->where('role', 'participant')->where('is_active', true)->with('assignedCoaches')->orderBy('name')->get(['id', 'name', 'email']);
        $pairParticipants->each(function (User $participant): void {
            $participant->name = $participant->name.' — Mentor: '.($participant->assignedCoaches->first()?->name ?? 'Not assigned');
        });

        return view('coach.participants', compact('items', 'pairs', 'pairParticipants'));
    }

    public function assignWeekFourPair(AssignWeekFourPairRequest $request, WeekFourInterviewService $interviews): mixed
    {
        $validated = $request->validated();
        $first = User::query()->findOrFail($validated['participant_one_id']);
        $second = User::query()->findOrFail($validated['participant_two_id']);
        $interviews->assign($request->user(), $first, $second);

        return back()->with('status', 'Week 4 interview partners assigned reciprocally.');
    }

    public function unpairWeekFourPair(Request $request, WeekFourInterviewPair $pair, WeekFourInterviewService $interviews): mixed
    {
        $interviews->unpair($request->user(), $pair->load(['participantOne', 'participantTwo']));

        return back()->with('status', 'The Week 4 interview pairing was safely deactivated.');
    }

    public function waiveWeekFourPeerInterview(WaiveWeekFourPeerInterviewRequest $request, User $participant, WeekFourInterviewService $interviews): mixed
    {
        $activity = Activity::query()->where('position', 2)->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', 4))->firstOrFail();
        $validated = $request->validated();
        $interviews->waive($request->user(), $participant, $activity, $validated['reason'] ?? null);

        return back()->with('status', 'Peer mock interview requirement waived for this participant.');
    }

    public function revokeWeekFourPeerInterviewWaiver(Request $request, User $participant, WeekFourInterviewService $interviews): mixed
    {
        abort_unless($participant->role === 'participant', 404);
        $activity = Activity::query()->where('position', 2)->whereHas('week', fn (Builder $query): Builder => $query->where('week_number', 4))->firstOrFail();
        $interviews->revokeWaiver($request->user(), $participant, $activity);

        return back()->with('status', 'Peer mock interview waiver revoked.');
    }

    public function savePartnerFeedback(StorePartnerFeedbackRequest $request, WeekFourInterviewService $interviews): mixed
    {
        $validated = $request->validated();
        $interviews->saveFeedback($request->user(), (int) $validated['question_number'], $validated['content'], isset($validated['pair_id']) ? (int) $validated['pair_id'] : null);

        return back()->with('status', 'Your feedback was saved for your partner.');
    }

    public function participantDailyLogs(FilterParticipantDailyLogsRequest $request, User $participant): View
    {
        $validated = $request->validated();
        $weeks = TrainingWeek::query()->where('is_active', true)->orderBy('week_number')->get();
        $selectedWeek = $weeks->firstWhere('week_number', (int) ($validated['week'] ?? 0));
        $logs = DailyLog::query()
            ->where('user_id', $participant->id)
            ->with(['activity.week'])
            ->when($selectedWeek, fn (Builder $query): Builder => $query->whereHas('activity', fn (Builder $activity): Builder => $activity->where('training_week_id', $selectedWeek->id)))
            ->when($validated['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('logged_on', $date))
            ->latest('logged_on')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();
        $participant->loadCount('dailyLogs');

        return view('coach.participant-daily-logs', compact('participant', 'logs', 'weeks', 'selectedWeek'));
    }

    public function participant(User $participant): View
    {
        abort_unless($participant->role === 'participant', 404);
        Gate::authorize('view', $participant);
        $participant->load(['activitySubmissions.activity.week', 'activitySubmissions.versions.evidence', 'activitySubmissions.versions.reviews', 'weeklyVideoRecordings.week', 'dailyLogs.activity', 'gateEvaluations.gate.criteria', 'attendanceSessions', 'assignedCoaches']);
        $gates = TrainingGate::query()->whereHas('week', fn (Builder $q): Builder => $q->whereIn('week_number', [2, 3, 4]))->with(['week', 'criteria', 'evaluations' => fn (Relation $q): Relation => $q->where('user_id', $participant->id)])->get();
        $gateService = app(WeekTwoGateService::class);
        $readinessByGate = $gateService->readinessForGates($participant, $gates);
        $gateRows = $gates->map(fn (TrainingGate $gate): array => ['gate' => $gate, 'evaluation' => $gate->evaluations->first(), 'readiness' => $readinessByGate->get($gate->id)]);
        $dailyLogCounts = $gateService->dailyLogCountsByWeek($participant, $gates->pluck('training_week_id')->unique()->values()->all());
        $weeklyLogCounts = $gates->mapWithKeys(fn (TrainingGate $gate): array => [$gate->week->id => $dailyLogCounts->get($gate->week->id, 0)]);

        $canManage = request()->user()->canManageParticipant($participant);
        $weekFourActivity = Activity::query()->where('position', 2)->whereHas('week', fn (Builder $q): Builder => $q->where('week_number', 4))->first();
        $peerWaiver = $weekFourActivity ? app(WeekFourInterviewService::class)->waiverFor($participant, $weekFourActivity) : null;

        return view('coach.participant', compact('participant', 'gateRows', 'weeklyLogCounts', 'canManage', 'weekFourActivity', 'peerWaiver'));
    }

    public function reviews(FilterReviewQueueRequest $request, CoachReportService $reports): View
    {
        $filters = $request->validated();
        $items = $reports->reviewQueue($filters)->paginate(25)->withQueryString();

        return view('coach.reviews', compact('items'));
    }

    public function weekFourQuestionnaire(): View
    {
        return view('coach.week-four-questionnaire', ['questions' => app(WeekFourInterviewService::class)->questionnaire()]);
    }

    public function review(SubmissionVersion $submissionVersion, SubmissionAnswerPresenter $answers, WeekFourReviewPresenter $weekFour): View
    {
        Gate::authorize('view', $submissionVersion);
        $submissionVersion->load(['submission.participant', 'submission.activity.week', 'evidence', 'reviews.reviewer', 'validationSubmission', 'interactions']);
        $activity = $submissionVersion->submission->activity;
        $isActivityOne = $activity->title === 'Validation Checklist' && $activity->week->week_number === 2;
        $configuredFields = $activity->requirements()
            ->where('requirement_type', 'submission_field')
            ->whereNull('participant_path')
            ->orderBy('position')
            ->get();
        $submittedFields = $configuredFields
            ->map(fn (ActivityRequirement $requirement): array => ['key' => $requirement->configuration['key'] ?? null, 'label' => $requirement->configuration['label'] ?? $requirement->title, 'value' => $submissionVersion->field_values[$requirement->configuration['key'] ?? ''] ?? null])
            ->filter(fn (array $field): bool => filled($field['value']))
            ->values();
        // Keep historical submissions readable after current activity schemas evolve.
        // Deprecated fields are never offered on new/revision forms, but their
        // immutable values must remain visible to authorized reviewers.
        $legacyLabels = [
            'artifact_context' => 'Real AI artifact and work context',
            'feature_area' => 'Feature / Area',
            'environment' => 'Environment',
            'ai_tool' => 'AI Tool',
            'artifact_type' => 'Artifact Type',
            'exact_prompt' => 'Exact Prompt',
            'exact_ai_output' => 'Exact AI Output',
            'prompt' => 'Prompt',
            'ai_output' => 'AI Output',
            'thread_drive_link' => 'Thread / Drive Link',
        ];
        $knownKeys = $configuredFields->pluck('configuration.key')->filter()->all();
        $historicalFields = collect($submissionVersion->field_values ?? [])
            ->except(['week_three_snapshot'])
            ->reject(fn (mixed $value, int|string $key): bool => in_array($key, $knownKeys, true) || blank($value))
            ->map(fn (mixed $value, int|string $key): array => [
                'key' => $key,
                'label' => $legacyLabels[$key] ?? ucwords(str_replace('_', ' ', $key)),
                'value' => $value,
            ])
            ->values();
        $submittedFields = $submittedFields->concat($historicalFields)->values();
        $submittedFields = $answers->fields($submissionVersion);
        $linkedValidationSubmission = $submissionVersion->validationSubmission;
        if (! $linkedValidationSubmission || (int) $linkedValidationSubmission->user_id !== (int) $submissionVersion->submission->user_id) {
            $linkedValidationSubmission = null;
        }

        $canManage = request()->user()->canManageParticipant($submissionVersion->submission->participant);
        $weekThreeSnapshot = $submissionVersion->submission->activity->week->week_number === 3
            ? ($submissionVersion->field_values['week_three_snapshot'] ?? null)
            : null;
        $weekThreeReview = $weekThreeSnapshot
            ? $answers->weekThreeReview($weekThreeSnapshot, (int) $activity->position, $submissionVersion->submission->participant->training_path)
            : null;
        $weekFourReview = $weekFour->present($activity, $submissionVersion->field_values ?? [], true);
        $officialQuestions = $activity->week->week_number === 4 && $activity->position === 2 ? app(WeekFourInterviewService::class)->questionnaire() : [];

        return view('coach.review', compact('submissionVersion', 'isActivityOne', 'submittedFields', 'linkedValidationSubmission', 'canManage', 'weekThreeSnapshot', 'weekThreeReview', 'weekFourReview', 'officialQuestions'));
    }

    public function claimReview(SubmissionVersion $submissionVersion): mixed
    {
        Gate::authorize('update', $submissionVersion);
        DB::transaction(function () use ($submissionVersion): void {
            $submission = $submissionVersion->submission()->lockForUpdate()->firstOrFail();
            if (in_array($submission->status, ['submitted', 'resubmitted'], true)) {
                $submission->update(['status' => 'under_review']);
            }
        });

        return back()->with('status', 'Review claimed.');
    }

    public function storeReview(StoreSubmissionReviewRequest $request, SubmissionVersion $submissionVersion, TrainingSyncCoordinator $syncs): mixed
    {
        Gate::authorize('update', $submissionVersion);
        $review = SubmissionReview::create(['submission_version_id' => $submissionVersion->id, 'reviewer_id' => $request->user()->id, 'decision' => $request->validated('decision'), 'comments' => $request->validated('comments'), 'reviewed_at' => now()]);
        $status = ['approved' => 'approved', 'needs_revision' => 'needs_revision', 'rejected' => 'failed'][$review->decision];
        $submissionVersion->submission()->update(['status' => $status, 'completed_at' => $status === 'approved' ? now() : null]);
        ExportActivityJob::dispatch($submissionVersion->id);
        $syncs->request($submissionVersion->submission->participant, 'review_updated');

        return back()->with('status', 'Review saved.');
    }

    public function createRetry(SubmissionVersion $submissionVersion): mixed
    {
        Gate::authorize('update', $submissionVersion);
        $submission = $submissionVersion->submission()->with('activity')->firstOrFail();
        abort_unless($submission->status === 'failed', 422);
        $existingRetry = ActivitySubmission::query()->where('activity_id', $submission->activity_id)->where('user_id', $submission->user_id)->where('attempt_number', '>', $submission->attempt_number)->whereIn('status', ['draft', 'submitted', 'under_review', 'needs_revision', 'approved', 'completed'])->first();
        if ($existingRetry) {
            return redirect()->route('coach.participants.show', $submission->user_id)->with('status', "Retry attempt {$existingRetry->attempt_number} already exists.");
        }
        $retry = DB::transaction(function () use ($submission): ActivitySubmission {
            $attempt = ActivitySubmission::query()->where('activity_id', $submission->activity_id)->where('user_id', $submission->user_id)->max('attempt_number') + 1;
            $retry = ActivitySubmission::create(['activity_id' => $submission->activity_id, 'user_id' => $submission->user_id, 'attempt_number' => $attempt, 'status' => 'draft']);
            $retry->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => []]);

            return $retry;
        });

        return redirect()->route('coach.participants.show', $submission->user_id)->with('status', "Retry attempt {$retry->attempt_number} created.");
    }

    public function gates(FilterCoachGatesRequest $request, WeekTwoGateService $readiness): View
    {
        $filters = $request->validated();
        $gates = TrainingGate::query()->where('is_active', true)->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('week', fn (Builder $w): Builder => $w->where('week_number', $v)))->with(['week', 'criteria', 'evaluations' => fn (Relation $q): Relation => $q->with(['participant', 'criterionEvaluations'])])->get();
        $participants = User::query()->where('role', 'participant')->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->where('training_path', $v))->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $s): Builder => $s->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))->with(['activitySubmissions', 'dailyLogs.activity'])->get();
        $readinessByParticipant = $readiness->readinessForParticipants($participants, $gates);
        $weeklyVideosByParticipant = $readiness->weeklyVideosForParticipants($participants, $gates);
        $gateRows = collect();
        foreach ($gates as $gate) {
            foreach ($participants as $participant) {
                $evaluation = $gate->evaluations->firstWhere('user_id', $participant->id);
                $readinessReasons = $readinessByParticipant->get($participant->id)->get($gate->id);
                $loom = $weeklyVideosByParticipant->get($participant->id)->get($gate->id);
                $status = $evaluation?->status ?? ($readinessReasons === [] ? 'ready_for_review' : 'not_ready');
                if (($filters['status'] ?? null) && $status !== $filters['status']) {
                    continue;
                }
                $gateRows->push(['gate' => $gate, 'participant' => $participant, 'evaluation' => $evaluation, 'readiness' => $readinessReasons, 'status' => $status, 'daily_log_count' => $participant->dailyLogs->filter(fn (DailyLog $log): bool => $log->activity?->training_week_id === $gate->week->id)->count(), 'criteria_met' => $evaluation?->criterionEvaluations->where('status', 'met')->count() ?? 0, 'criteria_total' => $gate->criteria->where('is_required', true)->count(), 'loom' => $loom]);
            }
        }
        $statusOrder = ['ready_for_review' => 1, 'awaiting_review' => 2, 'needs_revision' => 3, 'not_ready' => 4, 'failed' => 5, 'passed' => 6];
        $gateRows = $gateRows->sortBy(fn (array $row): int => $statusOrder[$row['status']] ?? 99)->values();
        $summary = ['total' => $gateRows->count(), 'ready' => $gateRows->where('status', 'ready_for_review')->count(), 'awaiting' => $gateRows->where('status', 'awaiting_review')->count(), 'passed' => $gateRows->where('status', 'passed')->count(), 'not_ready' => $gateRows->where('status', 'not_ready')->count()];
        $gateRows = new LengthAwarePaginator(
            $gateRows->forPage(max(1, (int) $request->input('page', 1)), 25)->values(),
            $summary['total'],
            25,
            max(1, (int) $request->input('page', 1)),
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('coach.gates', compact('gates', 'gateRows', 'summary', 'filters'));
    }

    public function reports(FilterCoachReportsRequest $request, CoachReportService $reports): View
    {
        $filters = $request->validated();
        $summaryRows = $reports->participantSummary($filters)->get();
        $items = $reports->participants($filters)->orderBy('name')->paginate(25)->withQueryString();
        $atRiskRows = $reports->atRiskRows($filters)->when($filters['mentor'] ?? null, fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['participant']->assignedCoaches->contains('id', (int) $filters['mentor']))->values());
        $atRisk = new LengthAwarePaginator(
            $atRiskRows->forPage(max(1, (int) $request->input('risk_page', 1)), 25)->values(),
            $atRiskRows->count(),
            25,
            max(1, (int) $request->input('risk_page', 1)),
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'risk_page']
        );
        $mentors = User::query()->whereIn('role', ['coach', 'admin'])->orderBy('name')->get();
        $summary = [
            'participants' => $summaryRows->count(),
            'active' => $summaryRows->where('is_active', true)->count(),
            'submissions' => $summaryRows->sum('activity_submissions_count'),
            'gates' => $summaryRows->sum(fn (User $participant): int => $participant->passed_gate_evaluations_count),
            'at_risk' => $atRiskRows->count(),
        ];

        return view('coach.reports', compact('items', 'atRisk', 'filters', 'mentors', 'summary'));
    }

    public function export(ExportCoachReportRequest $request, CoachReportService $reports): StreamedResponse
    {
        $validated = $request->validated();
        $type = $validated['type'] ?? 'progress';
        $filters = $request->only('path', 'week', 'activity', 'status', 'participant');
        if ($type === 'reviews') {
            return $this->csv('review-queue.csv', ['Participant', 'Path', 'Week', 'Activity', 'Status', 'Version', 'Submitted', 'Decision', 'Reviewed'], $reports->reviewRows($filters)->map(fn (array $r): array => [$r['submission']->participant->name, $r['submission']->participant->trainingPathLabel(), $r['submission']->activity->week->week_number, $r['submission']->activity->title, $r['submission']->status, $r['version']?->version_number, $r['submission']->submitted_at, $r['review']?->decision, $r['review']?->reviewed_at]));
        }
        if ($type === 'gates') {
            return $this->csv('gate-results.csv', ['Participant', 'Path', 'Week', 'Gate', 'Status', 'Evaluated', 'Reviewer'], $reports->filteredGateResults($filters)->get()->map(fn (GateEvaluation $e): array => [$e->participant->name, $e->participant->trainingPathLabel(), $e->gate->week->week_number, $e->gate->title, $e->status, $e->evaluated_at, $e->reviewer_id]));
        }
        if ($type === 'risk') {
            return $this->csv('at-risk.csv', ['Participant', 'Path', 'Reason'], $reports->atRiskRows($filters)->map(fn (array $r): array => [$r['participant']->name, $r['participant']->trainingPathLabel(), implode('; ', $r['reasons'])]));
        }
        $items = $reports->participants($request->only('path', 'search'))->get();
        if ($request->input('mentor')) {
            $items = $items->filter(fn (User $participant): bool => $participant->assignedCoaches->contains('id', (int) $request->input('mentor')))->values();
        }

        return response()->streamDownload(function () use ($items): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Training path', 'Submissions', 'Gate evaluations']);
            foreach ($items as $item) {
                fputcsv($out, [$item->name, $item->email, $item->trainingPathLabel(), $item->activitySubmissions->count(), $item->gateEvaluations->count()]);
            } fclose($out);
        }, 'participant-progress.csv', ['Content-Type' => 'text/csv']);
    }

    private function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            } fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
