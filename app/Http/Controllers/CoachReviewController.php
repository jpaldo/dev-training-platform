<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterReviewQueueRequest;
use App\Http\Requests\StoreSubmissionReviewRequest;
use App\Models\SubmissionVersion;
use App\Services\CoachReviewQueuePageService;
use App\Services\CoachSubmissionReviewPageService;
use App\Services\SubmissionReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CoachReviewController extends Controller
{
    public function index(FilterReviewQueueRequest $request, CoachReviewQueuePageService $page): View
    {
        return view('coach.reviews', $page->indexData($request->validated()));
    }

    public function show(Request $request, SubmissionVersion $submissionVersion, CoachSubmissionReviewPageService $page): View
    {
        Gate::authorize('view', $submissionVersion);

        return view('coach.review', $page->showData($submissionVersion, $request->user()));
    }

    public function claim(SubmissionVersion $submissionVersion, SubmissionReviewService $reviews): RedirectResponse
    {
        Gate::authorize('update', $submissionVersion);
        $reviews->claim($submissionVersion);

        return back()->with('status', 'Review claimed.');
    }

    public function store(StoreSubmissionReviewRequest $request, SubmissionVersion $submissionVersion, SubmissionReviewService $reviews): RedirectResponse
    {
        Gate::authorize('update', $submissionVersion);
        $reviews->record($submissionVersion, $request->user(), $request->validated('decision'), $request->validated('comments'));

        return back()->with('status', 'Review saved.');
    }

    public function retry(SubmissionVersion $submissionVersion, SubmissionReviewService $reviews): RedirectResponse
    {
        Gate::authorize('update', $submissionVersion);
        $submission = $submissionVersion->submission()->with('activity')->firstOrFail();
        abort_unless($submission->status === 'failed', 422);
        $existingRetry = $reviews->existingRetry($submission);
        if ($existingRetry) {
            return redirect()->route('coach.participants.show', $submission->user_id)->with('status', "Retry attempt {$existingRetry->attempt_number} already exists.");
        }
        $retry = $reviews->createRetry($submission);

        return redirect()->route('coach.participants.show', $submission->user_id)->with('status', "Retry attempt {$retry->attempt_number} created.");
    }
}
