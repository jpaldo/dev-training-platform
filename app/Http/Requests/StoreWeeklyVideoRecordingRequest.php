<?php

namespace App\Http\Requests;

use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\EvidenceFilePolicy;
use App\Services\WeekProgressionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyVideoRecordingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * A week the participant cannot access yet answers 403, and a week whose
     * Gate the participant has passed is locked with a 422.
     */
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        /** @var TrainingWeek $trainingWeek */
        $trainingWeek = $this->route('trainingWeek');
        $progression = app(WeekProgressionService::class);
        $progression->ensureCanAccessWeek($user, $trainingWeek);
        abort_if($progression->hasPassedActiveGate($user, $trainingWeek), 422, 'This week is locked after Gate completion.');

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'video' => array_merge(['required'], app(EvidenceFilePolicy::class)->videoRules()),
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'video.max' => app(EvidenceFilePolicy::class)->videoMaxSizeMessage(),
        ];
    }
}
