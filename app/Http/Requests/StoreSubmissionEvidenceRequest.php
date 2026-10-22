<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\EvidenceRequirement;
use App\Services\EvidenceFilePolicy;
use App\Support\FieldLimits;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionEvidenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Participant;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $policy = app(EvidenceFilePolicy::class);
        $fileRules = $this->isVideoEvidence() ? $policy->videoRules() : ($this->isReusableAsset() ? $policy->reusableAssetRules() : $policy->generalRules());

        return [
            'evidence_requirement_id' => ['required', 'integer', 'exists:evidence_requirements,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:'.FieldLimits::EVIDENCE_TEXT],
            'url' => ['nullable', 'url', 'max:2048'],
            'file' => array_merge(['nullable'], $fileRules),
            'files' => ['nullable', 'array'],
            'files.*' => array_merge(['file'], $fileRules),
        ];
    }

    private function isVideoEvidence(): bool
    {
        return EvidenceRequirement::query()
            ->whereKey($this->integer('evidence_requirement_id'))
            ->where('evidence_type', 'video')
            ->exists();
    }

    private function isReusableAsset(): bool
    {
        return EvidenceRequirement::query()->whereKey($this->integer('evidence_requirement_id'))->where('evidence_type', 'asset')->exists();
    }
}
