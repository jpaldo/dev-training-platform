<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin adds one or more active coaches who are not mentors of the curriculum yet. Errors go to the `mentors` bag
 * and the admin returns to the curriculum page's Mentors tab.
 */
#[ErrorBag('mentors')]
class StoreProgramMentorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->program()) === true;
    }

    /**
     * At least one user; each an active coach, chosen once and not already a mentor of the curriculum.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array'],
            'user_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', UserRole::Coach)->where('is_active', true)),
                Rule::unique('program_mentors', 'user_id')->where('program_id', $this->program()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => 'Choose at least one mentor to add.',
            'user_ids.*.exists' => 'Only active users with the Mentor role can be added as mentors.',
            'user_ids.*.unique' => 'The selected mentor is already a mentor of this curriculum.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_ids' => 'mentors',
            'user_ids.*' => 'mentor',
        ];
    }

    /**
     * The mentor IDs to add.
     *
     * @return list<int>
     */
    public function mentorIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('user_ids')));
    }

    /**
     * A failed add returns to the curriculum page's Mentors tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.show', $this->program()).'#mentors';
    }

    private function program(): Program
    {
        return $this->route('program');
    }
}
