<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreSubmissionEvidenceRequest;
use App\Models\Activity;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use App\Models\TrainingSession;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\GoogleDriveReadinessService;
use App\Services\SubmissionEvidenceFileService;
use App\Services\SubmissionEvidenceService;
use App\Services\SubmissionWorkflowService;
use App\Services\WeekProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionEvidenceController extends Controller
{
    public function store(StoreSubmissionEvidenceRequest $request, TrainingSession $trainingSession, TrainingWeek $trainingWeek, Activity $activity, SubmissionVersion $submissionVersion, WeekProgressionService $progression, SubmissionWorkflowService $workflow, GoogleDriveReadinessService $readiness, SubmissionEvidenceService $evidence): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->role === UserRole::Participant && $readiness->enforced() && ! $readiness->isReady($user), 403, $readiness->message($user));
        $progression->ensureCanAccessActivity($user, $trainingSession, $trainingWeek, $activity);
        $progression->ensureCanEditActivity($user, $trainingSession, $trainingWeek, $activity);
        abort_unless($submissionVersion->submission->isForActivityInSession($activity, $trainingSession), 404);
        Gate::authorize('update', $submissionVersion);
        $workflow->ensureDraft($user, $submissionVersion);

        $files = $request->file('files', []);
        if ($request->hasFile('file')) {
            $files[] = $request->file('file');
        }
        $stored = collect($evidence->store($user, $activity, $submissionVersion, $request->validated(), $files));

        if ($request->expectsJson()) {
            return response()->json(['uploaded' => true, 'evidence_ids' => $stored->pluck('id')->values(), 'filenames' => $stored->pluck('original_filename')->filter()->values(), 'status' => 'Uploaded']);
        }

        return redirect()->route('training.submission.edit', [$trainingSession, $trainingWeek, $activity])->with('status', 'Evidence added.');
    }

    public function download(SubmissionEvidence $submissionEvidence, SubmissionEvidenceFileService $files): StreamedResponse
    {
        Gate::authorize('view', $submissionEvidence);
        $files->ensureFileExists($submissionEvidence);

        return Storage::disk($submissionEvidence->disk)->download($submissionEvidence->path, $submissionEvidence->original_filename);
    }

    public function view(SubmissionEvidence $submissionEvidence, SubmissionEvidenceFileService $files): BinaryFileResponse|StreamedResponse|Response
    {
        Gate::authorize('view', $submissionEvidence);

        return $files->viewResponse($submissionEvidence);
    }

    public function preview(SubmissionEvidence $submissionEvidence, SubmissionEvidenceFileService $files): JsonResponse
    {
        Gate::authorize('view', $submissionEvidence);

        return $files->previewResponse($submissionEvidence);
    }
}
