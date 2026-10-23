<?php

namespace App\Http\Requests;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin makes one of the session's mentors a participant's mentor. Each participant's errors go to their own bag
 * (see errorBagFor()), so the Mentors tab shows them beside the right mentor select, and the admin returns to that tab.
 */
class AssignMentorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->trainingSession()) === true;
    }

    /**
     * One mentor of the session.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mentor_id' => [
                'required',
                'integer',
                Rule::exists('training_session_mentors', 'user_id')->where('training_session_id', $this->trainingSession()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mentor_id.required' => 'Choose a mentor.',
            'mentor_id.exists' => 'The selected mentor is not a mentor of this training session.',
        ];
    }

    /**
     * The error bag of a participant's mentor select, e.g. "assignMentor12".
     */
    public static function errorBagFor(User $participant): string
    {
        return 'assignMentor'.$participant->id;
    }

    /**
     * The chosen mentor.
     */
    public function mentor(): User
    {
        return User::query()->findOrFail($this->validated('mentor_id'));
    }

    /**
     * Put the errors in the participant's own bag before the request redirects back.
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->errorBag = self::errorBagFor($this->participant());

        parent::failedValidation($validator);
    }

    /**
     * A failed assignment returns to the dashboard's Mentors tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.sessions.show', $this->trainingSession()).'#mentors';
    }

    private function trainingSession(): TrainingSession
    {
        return $this->route('trainingSession');
    }

    private function participant(): User
    {
        return $this->route('participant');
    }
}
