<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideRecoveryRequestRequest;
use App\Http\Requests\StoreRecoveryRequestRequest;
use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use App\Services\CoachRecoveryRequestPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RecoveryRequestController extends Controller
{
    public function store(StoreRecoveryRequestRequest $request, ActivitySubmission $activitySubmission): RedirectResponse
    {
        if ($request->hasPendingRecoveryRequest()) {
            return back()->with('status', 'Review requested. Waiting for coach response.');
        }
        ActivityRecoveryRequest::create(['user_id' => $request->user()->id, 'activity_submission_id' => $activitySubmission->id, 'message' => $request->validated('message'), 'requested_at' => now()]);

        return back()->with('status', 'Review requested. Waiting for coach response.');
    }

    public function index(CoachRecoveryRequestPageService $page): View
    {
        return view('coach.recovery_requests', $page->indexData());
    }

    public function decide(DecideRecoveryRequestRequest $request, ActivityRecoveryRequest $recoveryRequest): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($recoveryRequest, $data, $request): void {
            $retry = null;
            if ($data['decision'] === 'approved') {
                $submission = $recoveryRequest->submission;
                $attempt = ActivitySubmission::where('activity_id', $submission->activity_id)->where('user_id', $submission->user_id)->max('attempt_number') + 1;
                $retry = ActivitySubmission::create(['activity_id' => $submission->activity_id, 'user_id' => $submission->user_id, 'attempt_number' => $attempt, 'status' => 'draft']);
                $retry->versions()->create(['version_number' => 1, 'status' => 'draft', 'field_values' => []]);
            } $recoveryRequest->update(['status' => $data['decision'], 'coach_response' => $data['coach_response'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        });

        return back()->with('status', 'Recovery request updated.');
    }
}
