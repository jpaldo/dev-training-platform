<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterReusableAssetsRequest;
use App\Models\SubmissionVersion;
use App\Models\User;
use App\Services\ReusableAssetLibraryPageService;
use App\Services\ReusableAssetLibraryService;
use App\Services\SubmissionEvidenceFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReusableAssetLibraryController extends Controller
{
    public function index(FilterReusableAssetsRequest $request, ReusableAssetLibraryPageService $pages): View
    {
        return view('reusable-assets.index', $pages->indexData($request->validated()));
    }

    public function show(Request $request, SubmissionVersion $submissionVersion, ReusableAssetLibraryPageService $pages): View
    {
        $this->authorizeTrainingUser($request);

        return view('reusable-assets.show', $pages->showData($submissionVersion));
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
