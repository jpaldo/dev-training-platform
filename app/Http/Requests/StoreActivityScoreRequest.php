<?php

namespace App\Http\Requests;

use App\Services\ScoringService;
use Illuminate\Foundation\Http\FormRequest;

class StoreActivityScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = $this->route('submissionVersion');

        return $this->user()?->isCoachOrAdmin() === true && $version?->loadMissing('submission.participant')->submission?->participant && $this->user()->canManageParticipant($version->submission->participant);
    }

    public function rules(): array
    {
        $rules = [];
        foreach (ScoringService::DIMENSIONS as $key => $dimension) {
            $rules["dimensions.{$key}.points"] = ['required', 'integer', 'min:0', "max:{$dimension['max']}"];
            $rules["dimensions.{$key}.evidence_reasoning"] = ['required', 'string', 'max:10000'];
        }
        $rules['overall_feedback'] = ['nullable', 'string', 'max:10000'];
        $rules['history_reason'] = ['nullable', 'string', 'max:2000'];

        return $rules;
    }
}
