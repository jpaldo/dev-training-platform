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
     * Whether the posted requirement is `video` evidence, once looked up for the rules and messages.
     */
    private ?bool $isVideoEvidence = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Participant;
    }

    /**
     * Get the validation rules that apply to the request. Video evidence is an http(s) link to the recording and
     * refuses files; every other evidence type takes files under the general evidence rules.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'evidence_requirement_id' => ['required', 'integer', 'exists:evidence_requirements,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:'.FieldLimits::EVIDENCE_TEXT],
        ];

        if ($this->isVideoEvidence()) {
            return [
                ...$rules,
                'url' => ['required', 'string', 'max:2048', 'url:http,https'],
                'file' => ['prohibited'],
                'files' => ['prohibited'],
                'files.*' => ['prohibited'],
            ];
        }

        $fileRules = app(EvidenceFilePolicy::class)->generalRules();

        return [
            ...$rules,
            'url' => ['nullable', 'url', 'max:2048'],
            'file' => array_merge(['nullable'], $fileRules),
            'files' => ['nullable', 'array'],
            'files.*' => array_merge(['file'], $fileRules),
        ];
    }

    /**
     * The video evidence messages; other evidence types keep the default messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        if (! $this->isVideoEvidence()) {
            return [];
        }

        return [
            'url.required' => 'Paste the link to your recording.',
            'url.url' => 'The link must be a full web address starting with http:// or https://.',
            'prohibited' => 'Video evidence is a link. Paste the link to your recording instead of uploading a file.',
        ];
    }

    private function isVideoEvidence(): bool
    {
        return $this->isVideoEvidence ??= EvidenceRequirement::query()
            ->whereKey($this->integer('evidence_requirement_id'))
            ->where('evidence_type', 'video')
            ->exists();
    }
}
