<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin makes one of the curriculum's mentors a participant's mentor. Each participant's errors go to their own bag
 * (see errorBagFor()), so the Participants tab opens that participant's mentor form with them beside the select, and the
 * admin returns to that tab.
 */
class AssignMentorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->program()) === true;
    }

    /**
     * One mentor of the curriculum.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mentor_id' => [
                'required',
                'integer',
                Rule::exists('program_mentors', 'user_id')->where('program_id', $this->program()->id),
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
            'mentor_id.exists' => 'The selected mentor is not a mentor of this curriculum.',
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
     * A failed assignment returns to the curriculum page's Participants tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.show', $this->program()).'#participants';
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    private function participant(): User
    {
        return $this->route('participant');
    }
}
