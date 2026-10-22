<?php

namespace App\Services;

use App\Models\ActivityRequirement;
use App\Models\ActivitySubmission;
use App\Models\SubmissionEvidence;
use App\Models\SubmissionVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ReusableAssetLibraryPageService
{
    private const ASSETS_PER_PAGE = 12;

    /** File extensions the library offers only as a download, without the evidence preview. */
    private const PREVIEW_UNAVAILABLE_EXTENSIONS = ['doc', 'xls', 'zip', 'tar'];

    /** Week 4 response fields shown under "About this asset", in display order. */
    private const DETAIL_FIELDS = [
        'asset_content' => 'Asset Content',
        'original_task' => 'Original Task',
        'original_use' => 'How It Was Used',
        'second_task' => 'Second Real Task',
        'reuse_method' => 'How It Was Reused',
        'reuse_result' => 'Reuse Result',
        'reuse_changes' => 'Changes Made',
        'reusability_learning' => 'What Was Learned',
        'reusability_improvement' => 'What Could Improve',
    ];

    public function __construct(private readonly ReusableAssetLibraryService $library) {}

    /**
     * Everything the reusable asset library renders: the filter values, the Asset type field options of the Week 4
     * Activity 1 requirements and one card per published asset. The page has no single main action, so every
     * button is secondary.
     *
     * @param  array{search?: ?string, asset_type?: ?string}  $filters
     * @return array{search: string, assetTypeOptions: list<array{value: string, label: string, is_selected: bool}>, items: LengthAwarePaginator<int, array{asset_type: string, asset_name: string, owner: string, purpose: string, filename: string, details_url: string, is_preview_unavailable: bool, asset: SubmissionEvidence, view_url: string, download_url: string, preview_url: string, modal_id: string}>}
     */
    public function indexData(array $filters): array
    {
        return [
            'search' => (string) ($filters['search'] ?? ''),
            'assetTypeOptions' => $this->assetTypeOptions($filters['asset_type'] ?? ''),
            'items' => $this->library->approvedSubmissions($filters)
                ->paginate(self::ASSETS_PER_PAGE)
                ->withQueryString()
                ->through(fn (ActivitySubmission $submission): array => $this->assetCard($submission)),
        ];
    }

    /**
     * Everything the reusable asset detail page renders: the summary, the filled Week 4 response fields and the
     * asset file actions. Aborts with a 404 when the version is not a published asset.
     *
     * @return array{assetName: string, owner: string, assetType: string, purpose: string, detailRows: list<array{label: string, text: string}>, filename: string, isPreviewUnavailable: bool, asset: SubmissionEvidence, viewUrl: string, downloadUrl: string, previewUrl: string, modalId: string, libraryUrl: string}
     */
    public function showData(SubmissionVersion $version): array
    {
        $asset = $this->library->assetForVersion($version);
        $values = $version->field_values ?? [];

        return [
            'assetName' => $values['asset_name'] ?? 'Reusable AI Asset',
            'owner' => $version->submission->participant->name,
            'assetType' => $values['asset_type'] ?? 'Reusable QA Asset',
            'purpose' => $values['asset_purpose'] ?? 'Not provided',
            'detailRows' => $this->detailRows($values),
            'filename' => $asset->original_filename ?: 'Reusable AI Asset',
            'isPreviewUnavailable' => $this->isPreviewUnavailable($asset),
            'asset' => $asset,
            'viewUrl' => route('training.reusable-assets.view', $version),
            'downloadUrl' => route('training.reusable-assets.download', $version),
            'previewUrl' => route('training.reusable-assets.preview', $version),
            'modalId' => 'reusable-asset-detail-preview-'.$version->id,
            'libraryUrl' => route('training.reusable-assets.index'),
        ];
    }

    /**
     * The options of the Asset type field (`asset_type`) configured on the Week 4 Activity 1 requirements, sorted and
     * without duplicates.
     *
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function assetTypeOptions(string $selectedType): array
    {
        return ActivityRequirement::query()
            ->where('configuration->key', 'asset_type')
            ->whereHas('activity', fn (Builder $activity): Builder => $activity->where('position', 1)->whereHas('week', fn (Builder $week): Builder => $week->where('week_number', 4)))
            ->get()
            ->flatMap(fn (ActivityRequirement $requirement): array => $requirement->configuration['options'] ?? [])
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $type): array => [
                'value' => $type,
                'label' => $type,
                'is_selected' => $selectedType === $type,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{asset_type: string, asset_name: string, owner: string, purpose: string, filename: string, details_url: string, is_preview_unavailable: bool, asset: SubmissionEvidence, view_url: string, download_url: string, preview_url: string, modal_id: string}
     */
    private function assetCard(ActivitySubmission $submission): array
    {
        ['version' => $version, 'asset' => $asset] = $this->library->assetForSubmission($submission);
        $values = $version->field_values ?? [];

        return [
            'asset_type' => $values['asset_type'] ?? 'Reusable QA Asset',
            'asset_name' => $values['asset_name'] ?? 'Reusable AI Asset',
            'owner' => $submission->participant->name,
            'purpose' => $values['asset_purpose'] ?? 'Validated reusable QA asset.',
            'filename' => $asset->original_filename ?: 'Reusable asset',
            'details_url' => route('training.reusable-assets.show', $version),
            'is_preview_unavailable' => $this->isPreviewUnavailable($asset),
            'asset' => $asset,
            'view_url' => route('training.reusable-assets.view', $version),
            'download_url' => route('training.reusable-assets.download', $version),
            'preview_url' => route('training.reusable-assets.preview', $version),
            'modal_id' => 'reusable-asset-preview-'.$version->id,
        ];
    }

    /**
     * The filled response fields in display order; a non-scalar value is shown as pretty-printed JSON.
     *
     * @param  array<string, mixed>  $values
     * @return list<array{label: string, text: string}>
     */
    private function detailRows(array $values): array
    {
        return collect(self::DETAIL_FIELDS)
            ->filter(fn (string $label, string $key): bool => filled($values[$key] ?? null))
            ->map(fn (string $label, string $key): array => [
                'label' => $label,
                'text' => is_scalar($values[$key])
                    ? (string) $values[$key]
                    : (string) json_encode($values[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ])
            ->values()
            ->all();
    }

    private function isPreviewUnavailable(SubmissionEvidence $asset): bool
    {
        $extension = strtolower(pathinfo($asset->original_filename ?: '', PATHINFO_EXTENSION));

        return in_array($extension, self::PREVIEW_UNAVAILABLE_EXTENSIONS, true);
    }
}
