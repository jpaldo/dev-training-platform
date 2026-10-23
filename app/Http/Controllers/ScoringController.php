<?php

namespace App\Http\Controllers;

use App\Enums\ActivityScoreStatus;
use App\Enums\UserRole;
use App\Http\Requests\FilterScoringParticipantsRequest;
use App\Http\Requests\ImproveScoringFeedbackRequest;
use App\Http\Requests\StoreActivityScoreRequest;
use App\Http\Requests\StoreSecondReaderScoreRequest;
use App\Models\ActivityScore;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\ScoringPageService;
use App\Services\ScoringService;
use App\Services\ScoringSuggestionService;
use App\Services\TrainingSyncCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

class ScoringController extends Controller
{
    public function index(FilterScoringParticipantsRequest $request, ScoringPageService $page): View
    {
        return view('scoring.index', $page->indexData($request->user(), $request->validated()));
    }

    /**
     * A participant's scores in one training session. The participant is bound through the session's participants, so
     * someone who is not in the session is not found; only an admin or the participant's current mentor in the session
     * may open it.
     */
    public function participant(Request $request, TrainingSession $trainingSession, User $participant, ScoringPageService $page): View
    {
        abort_unless($participant->hasAnyRole(UserRole::Participant), 404);
        abort_unless($request->user()->mentorsParticipantInSession($participant, $trainingSession), 403);

        return view('scoring.participant', $page->participantData($trainingSession, $participant));
    }

    /**
     * The scoring page of a submitted version, for an admin or the participant's current mentor in the submission's
     * session.
     */
    public function edit(Request $request, SubmissionVersion $submissionVersion, ScoringPageService $page): View
    {
        $submissionVersion->load(['submission.participant', 'submission.trainingSession']);
        $draft = $request->session()->pull('scoring_draft');
        abort_unless(! $submissionVersion->isDraft() && $submissionVersion->submission->participant->role === UserRole::Participant, 404);
        Gate::authorize('view', $submissionVersion);

        return view('scoring.edit', $page->editData($submissionVersion, is_array($draft) ? $draft : null));
    }

    public function generateSuggestion(Request $request, SubmissionVersion $submissionVersion, ScoringSuggestionService $suggestions): RedirectResponse
    {
        $submissionVersion->load(['submission.participant', 'submission.trainingSession']);
        abort_unless(! $submissionVersion->isDraft() && $submissionVersion->submission->participant->role === UserRole::Participant, 404);
        abort_unless($request->user()->mentorsParticipantInSession($submissionVersion->submission->participant, $submissionVersion->submission->trainingSession), 403);
        if ($request->filled('draft_json')) {
            $draft = json_decode((string) $request->input('draft_json'), true);
            if (is_array($draft)) {
                $request->session()->flash('scoring_draft', $draft);
            }
        }

        try {
            $suggestions->generate($submissionVersion);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['suggestion' => $exception->getMessage()]);
        }

        return back()->with('status', 'Suggested scoring generated. Review it before saving the mentor score.');
    }

    public function improveFeedback(ImproveScoringFeedbackRequest $request, SubmissionVersion $submissionVersion, ScoringSuggestionService $suggestions): JsonResponse
    {
        $data = $request->validated();
        try {
            return response()->json(['text' => $suggestions->improve($data['text'], $data['dimension'] ?? null), 'message' => 'Feedback improved. Review before saving.']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function store(StoreActivityScoreRequest $request, SubmissionVersion $submissionVersion, TrainingSyncCoordinator $syncs): RedirectResponse
    {
        $submissionVersion->load('submission.participant');
        abort_unless(! $submissionVersion->isDraft() && $submissionVersion->submission->participant->role === UserRole::Participant, 404);
        $data = $request->validated();
        $total = collect($data['dimensions'])->sum('points');
        $service = app(ScoringService::class);
        DB::transaction(function () use ($data, $total, $service, $submissionVersion, $request): void {
            $score = ActivityScore::query()->firstOrNew(['submission_version_id' => $submissionVersion->id]);
            $previous = $score->total_score;
            $previousDimensions = $score->exists ? $score->dimensions()->pluck('points', 'dimension')->all() : null;
            $previousReasoning = $score->exists ? $score->dimensions()->pluck('evidence_reasoning', 'dimension')->all() : null;
            $previousOverallFeedback = $score->overall_feedback;
            $score->fill(['scorer_id' => $request->user()->id, 'status' => ActivityScoreStatus::Published, 'total_score' => $total, 'performance_band' => $service->band($total), 'overall_feedback' => $data['overall_feedback'] ?? null]);
            $score->second_reader_required = $service->requiresSecondReader($total, $service->runningAverage($submissionVersion->submission->participant, $submissionVersion->id));
            $score->save();
            foreach ($data['dimensions'] as $dimension => $values) {
                $score->dimensions()->updateOrCreate(['dimension' => $dimension], ['points' => $values['points'], 'evidence_reasoning' => $values['evidence_reasoning']]);
            }
            if ($previous !== $total || $previousDimensions !== null || $previousOverallFeedback !== ($data['overall_feedback'] ?? null)) {
                $score->history()->create(['scorer_id' => $request->user()->id, 'previous_score' => $previous, 'new_score' => $total, 'previous_dimensions' => $previousDimensions, 'new_dimensions' => collect($data['dimensions'])->mapWithKeys(fn (array $v, string $k): array => [$k => $v['points']])->all(), 'previous_reasoning' => $previousReasoning, 'new_reasoning' => collect($data['dimensions'])->mapWithKeys(fn (array $v, string $k): array => [$k => $v['evidence_reasoning']])->all(), 'previous_overall_feedback' => $previousOverallFeedback, 'new_overall_feedback' => $data['overall_feedback'] ?? null, 'reason' => $data['history_reason'] ?? null]);
            }
        });
        $syncs->request($submissionVersion->submission->participant, 'score_published');

        return redirect()
            ->route('coach.scoring.participant', [$submissionVersion->submission->training_session_id, $submissionVersion->submission->user_id])
            ->with('status', 'Activity score saved.');
    }

    public function storeSecondReader(StoreSecondReaderScoreRequest $request, SubmissionVersion $submissionVersion): RedirectResponse
    {
        $submissionVersion->load('activityScore');
        abort_unless($submissionVersion->activityScore?->second_reader_required, 422);
        $submissionVersion->activityScore->update($request->validated());

        return back()->with('status', 'Second-reader review saved.');
    }

    public function criteria(ScoringPageService $page): View
    {
        return view('scoring.criteria', $page->criteriaData());
    }
}
