<?php

namespace App\Http\Controllers;

use App\Models\ActivityRecoveryRequest;
use App\Models\ActivitySubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecoveryRequestController extends Controller
{
    public function store(Request $request, ActivitySubmission $activitySubmission)
    {
        abort_unless($activitySubmission->user_id === $request->user()->id && $activitySubmission->status === 'failed', 403);
        if (ActivityRecoveryRequest::where('activity_submission_id', $activitySubmission->id)->where('status', 'pending')->exists()) {
            return back()->with('status', 'Review requested. Waiting for coach response.');
        } ActivityRecoveryRequest::create(['user_id' => $request->user()->id, 'activity_submission_id' => $activitySubmission->id, 'message' => $request->validate(['message' => ['nullable', 'string', 'max:2000']])['message'] ?? null, 'requested_at' => now()]);

        return back()->with('status', 'Review requested. Waiting for coach response.');
    }

    public function index()
    {
        $requests = ActivityRecoveryRequest::with(['participant', 'submission.activity', 'submission.versions'])->where('status', 'pending')->latest()->paginate(25)->withQueryString();

        return view('coach.recovery_requests', compact('requests'));
    }

    public function decide(Request $request, ActivityRecoveryRequest $recoveryRequest)
    {
        abort_unless($recoveryRequest->status === 'pending', 422);
        abort_unless($request->user()->canManageParticipant($recoveryRequest->participant), 403);
        $data = $request->validate(['decision' => ['required', 'in:approved,declined'], 'coach_response' => ['nullable', 'string', 'max:2000']]);
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
