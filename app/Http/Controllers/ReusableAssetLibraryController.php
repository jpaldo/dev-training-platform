<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterReusableAssetsRequest;
use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\ReusableAssetLibraryService;
use App\Services\SubmissionEvidenceFileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReusableAssetLibraryController extends Controller
{
    public function index(FilterReusableAssetsRequest $request, ReusableAssetLibraryService $library): View
    {
        $filters = $request->validated();
        $items = $library->approvedSubmissions($filters)->paginate(12)->withQueryString();
        $items->setCollection($items->getCollection()->map(function (ActivitySubmission $submission) use ($library): array {
            $published = $library->assetForSubmission($submission);

            return ['submission' => $submission, 'version' => $published['version'], 'asset' => $published['asset']];
        }));

        $assetTypes = ActivityRequirement::query()
            ->whereHas('activity', fn (Builder $activity): Builder => $activity->where('position', 1)->whereHas('week', fn (Builder $week): Builder => $week->where('week_number', 4)))
            ->get()
            ->flatMap(fn (ActivityRequirement $requirement): array => $requirement->configuration['options'] ?? [])
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return view('reusable-assets.index', compact('items', 'assetTypes', 'filters'));
    }

    public function show(Request $request, SubmissionVersion $submissionVersion, ReusableAssetLibraryService $library): View
    {
        $this->authorizeTrainingUser($request);
        $asset = $library->assetForVersion($submissionVersion);
        $submissionVersion->loadMissing('submission.participant', 'submission.activity', 'evidence.evidenceRequirement');

        return view('reusable-assets.show', [
            'version' => $submissionVersion,
            'submission' => $submissionVersion->submission,
            'asset' => $asset,
            'values' => $submissionVersion->field_values ?? [],
        ]);
    }

    public function preview(Request $request, SubmissionVersion $submissionVersion, ReusableAssetLibraryService $library, SubmissionEvidenceFileService $evidenceFiles): JsonResponse
    {
        $this->authorizeTrainingUser($request);
        $asset = $library->assetForVersion($submissionVersion);

        return $evidenceFiles->previewResponse($asset);
    }

    public function view(Request $request, SubmissionVersion $submissionVersion, ReusableAssetLibraryService $library, SubmissionEvidenceFileService $evidenceFiles): BinaryFileResponse|StreamedResponse|View
    {
        $this->authorizeTrainingUser($request);
        $asset = $library->assetForVersion($submissionVersion);

        return $evidenceFiles->viewResponse($asset);
    }

    public function download(Request $request, SubmissionVersion $submissionVersion, ReusableAssetLibraryService $library): StreamedResponse
    {
        $this->authorizeTrainingUser($request);
        $asset = $library->assetForVersion($submissionVersion);
        abort_unless(Storage::disk($asset->disk)->exists($asset->path), 404);

        return Storage::disk($asset->disk)->download($asset->path, $asset->original_filename ?: 'reusable-ai-asset');
    }

    private function authorizeTrainingUser(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user && in_array($user->role, ['participant', 'coach', 'admin'], true), 403);
    }
}
