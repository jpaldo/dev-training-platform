<?php

namespace App\Http\Requests;

use App\Models\TrainingSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The create form's rules (StoreTrainingSessionRequest), plus: once participants have joined, the program must stay
 * the session's current one. The edit form then submits the current program from a hidden input.
 */
class UpdateTrainingSessionRequest extends StoreTrainingSessionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->trainingSession()) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $trainingSession = $this->trainingSession();

        if ($trainingSession->hasParticipants()) {
            $rules['program_id'][] = Rule::in([$trainingSession->program_id]);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'program_id.in' => 'The program cannot change after participants have joined.',
        ];
    }

    private function trainingSession(): TrainingSession
    {
        return $this->route('trainingSession');
    }
}
