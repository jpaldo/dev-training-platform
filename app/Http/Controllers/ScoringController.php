<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterScoringParticipantsRequest;
use App\Http\Requests\ImproveScoringFeedbackRequest;
use App\Http\Requests\StoreActivityScoreRequest;
use App\Http\Requests\StoreSecondReaderScoreRequest;
use App\Jobs\ExportActivityJob;
use App\Models\ActivityScore;
use App\Models\Program;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\ScoringPageService;
use App\Services\ScoringService;
use App\Services\ScoringSuggestionService;
use App\Services\TrainingSyncCoordinator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class ScoringController extends Controller
{
    public function index(FilterScoringParticipantsRequest $request, ScoringService $scoring): View
    {
        $filters = $request->validated();
        $program = Program::query()->where('is_active', true)->firstOrFail();
        $participants = User::query()->where('role', 'participant')->when($filters['path'] ?? null, fn (Builder $q, string $v): Builder => $q->where('training_path', $v))->when($filters['search'] ?? null, fn (Builder $q, string $v): Builder => $q->where(fn (Builder $s): Builder => $s->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))->when($filters['week'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('activitySubmissions.activity.week', fn (Builder $w): Builder => $w->where('week_number', $v)))->when($filters['activity'] ?? null, fn (Builder $q, int|string $v): Builder => $q->whereHas('activitySubmissions.activity', fn (Builder $a): Builder => $a->whereKey($v)))->when(($filters['assigned_to_me'] ?? false) && $request->user()->role === 'coach', fn (Builder $q): Builder => $q->whereHas('assignedCoaches', fn (Builder $c): Builder => $c->whereKey($request->user()->id)))->when(($filters['score_status'] ?? null) === 'scored', fn (Builder $q): Builder => $q->whereHas('activitySubmissions.versions.activityScore', fn (Builder $s): Builder => $s->where('status', 'published')))->when(($filters['score_status'] ?? null) === 'unscored', fn (Builder $q): Builder => $q->whereDoesntHave('activitySubmissions.versions.activityScore'))->when($filters['band'] ?? null, fn (Builder $q, string $v): Builder => $q->whereHas('activitySubmissions.versions.activityScore', fn (Builder $s): Builder => $s->where('performance_band', $v)))->with('assignedCoaches')->paginate(25)->withQueryString();
        $rows = $participants->getCollection()->map(fn (User $participant): array => ['participant' => $participant, 'summary' => $scoring->summary($participant, $program)])->values();
        $participants->setCollection($rows);

        return view('scoring.index', compact('participants', 'filters'));
    }

    public function participant(User $participant, ScoringService $scoring): View
    {
        abort_unless($participant->role === 'participant', 404);
        $program = Program::query()->where('is_active', true)->firstOrFail();

        return view('scoring.participant', ['participant' => $participant, 'summary' => $scoring->summary($participant, $program)]);
    }

    public function edit(Request $request, SubmissionVersion $submissionVersion, ScoringPageService $page): View
    {
        $submissionVersion->load('submission.participant');
        $draft = $request->session()->pull('scoring_draft');
        abort_unless($submissionVersion->status !== 'draft' && $submissionVersion->submission->participant->role === 'participant', 404);

        return view('scoring.edit', $page->editData($submissionVersion, $request->user(), is_array($draft) ? $draft : null));
    }

    public function generateSuggestion(Request $request, SubmissionVersion $submissionVersion, ScoringSuggestionService $suggestions): RedirectResponse
    {
        $submissionVersion->load('submission.participant');
        abort_unless($submissionVersion->status !== 'draft' && $submissionVersion->submission->participant->role === 'participant', 404);
        abort_unless(request()->user()->canManageParticipant($submissionVersion->submission->participant), 403);
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
        abort_unless($submissionVersion->status !== 'draft' && $submissionVersion->submission->participant->role === 'participant', 404);
        $data = $request->validated();
        $total = collect($data['dimensions'])->sum('points');
        $service = app(ScoringService::class);
        DB::transaction(function () use ($data, $total, $service, $submissionVersion, $request): void {
            $score = ActivityScore::query()->firstOrNew(['submission_version_id' => $submissionVersion->id]);
            $previous = $score->total_score;
            $previousDimensions = $score->exists ? $score->dimensions()->pluck('points', 'dimension')->all() : null;
            $previousReasoning = $score->exists ? $score->dimensions()->pluck('evidence_reasoning', 'dimension')->all() : null;
            $previousOverallFeedback = $score->overall_feedback;
            $score->fill(['scorer_id' => $request->user()->id, 'status' => 'published', 'total_score' => $total, 'performance_band' => $service->band($total), 'overall_feedback' => $data['overall_feedback'] ?? null]);
            $score->second_reader_required = $service->requiresSecondReader($total, $service->runningAverage($submissionVersion->submission->participant, $submissionVersion->id));
            $score->save();
            foreach ($data['dimensions'] as $dimension => $values) {
                $score->dimensions()->updateOrCreate(['dimension' => $dimension], ['points' => $values['points'], 'evidence_reasoning' => $values['evidence_reasoning']]);
            }
            if ($previous !== $total || $previousDimensions !== null || $previousOverallFeedback !== ($data['overall_feedback'] ?? null)) {
                $score->history()->create(['scorer_id' => $request->user()->id, 'previous_score' => $previous, 'new_score' => $total, 'previous_dimensions' => $previousDimensions, 'new_dimensions' => collect($data['dimensions'])->mapWithKeys(fn (array $v, string $k): array => [$k => $v['points']])->all(), 'previous_reasoning' => $previousReasoning, 'new_reasoning' => collect($data['dimensions'])->mapWithKeys(fn (array $v, string $k): array => [$k => $v['evidence_reasoning']])->all(), 'previous_overall_feedback' => $previousOverallFeedback, 'new_overall_feedback' => $data['overall_feedback'] ?? null, 'reason' => $data['history_reason'] ?? null]);
            }
        });
        ExportActivityJob::dispatch($submissionVersion->id);
        $syncs->request($submissionVersion->submission->participant, 'score_published');

        return redirect()->route('coach.scoring.participant', $submissionVersion->submission->user_id)->with('status', 'Activity score saved.');
    }

    public function storeSecondReader(StoreSecondReaderScoreRequest $request, SubmissionVersion $submissionVersion): RedirectResponse
    {
        $submissionVersion->load('activityScore');
        abort_unless($submissionVersion->activityScore?->second_reader_required, 422);
        $submissionVersion->activityScore->update($request->validated());

        return back()->with('status', 'Second-reader review saved.');
    }

    public function criteria(): View
    {
        $ranges = [
            'D1' => ['Exemplary' => '27–30', 'Proficient' => '24–26', 'Developing' => '18–23', 'Beginning' => '11–17', 'Minimal' => '1–10'],
            'D2' => ['Exemplary' => '23–25', 'Proficient' => '20–22', 'Developing' => '15–19', 'Beginning' => '9–14', 'Minimal' => '1–8'],
            'D3' => ['Exemplary' => '18–20', 'Proficient' => '16–17', 'Developing' => '12–15', 'Beginning' => '7–11', 'Minimal' => '1–6'],
            'D4' => ['Exemplary' => '14–15', 'Proficient' => '12–13', 'Developing' => '9–11', 'Beginning' => '6–8', 'Minimal' => '1–5'],
            'D5' => ['Exemplary' => '9–10', 'Proficient' => '8', 'Developing' => '6–7', 'Beginning' => '4–5', 'Minimal' => '1–3'],
        ];

        return view('scoring.criteria', ['dimensions' => ScoringService::DIMENSIONS, 'ranges' => $ranges]);
    }
}
