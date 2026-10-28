<?php

namespace App\Http\Controllers;

use App\Enums\RecoveryRequestStatus;
use App\Http\Requests\DecideRecoveryRequestRequest;
use App\Http\Requests\FilterRecoveryRequestsRequest;
use App\Http\Requests\StoreRecoveryRequestRequest;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Services\CoachRecoveryRequestPageService;
use App\Services\SubmissionReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RecoveryRequestController extends Controller
{
    public function store(StoreRecoveryRequestRequest $request, ActivitySubmission $activitySubmission): RedirectResponse
    {
        if ($request->hasPendingRecoveryRequest()) {
            return back()->with('status', 'Review requested. Waiting for mentor response.');
        }
        ActivityRecoveryRequest::create(['user_id' => $request->user()->id, 'activity_submission_id' => $activitySubmission->id, 'message' => $request->validated('message'), 'requested_at' => now()]);

        return back()->with('status', 'Review requested. Waiting for mentor response.');
    }

    public function index(FilterRecoveryRequestsRequest $request, CoachRecoveryRequestPageService $page): View
    {
        return view('coach.recovery_requests', $page->indexData($request->user(), $request->validated()));
    }

    /**
     * Record the coach's decision; an approved request opens the submission's next attempt
     * (SubmissionReviewService::createRetry()).
     */
    public function decide(DecideRecoveryRequestRequest $request, ActivityRecoveryRequest $recoveryRequest, SubmissionReviewService $reviews): RedirectResponse
    {
        $decision = $request->enum('decision', RecoveryRequestStatus::class);
        $coachResponse = $request->validated('coach_response');
        DB::transaction(function () use ($recoveryRequest, $decision, $coachResponse, $request, $reviews): void {
            if ($decision === RecoveryRequestStatus::Approved) {
                $reviews->createRetry($recoveryRequest->submission);
            }
            $recoveryRequest->update(['status' => $decision, 'coach_response' => $coachResponse, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        });

        return back()->with('status', 'Recovery request updated.');
    }
}
