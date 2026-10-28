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
 * An admin adds one or more active participants who are not in the curriculum yet. Errors go to the `participants` bag
 * and the admin returns to the curriculum page's Participants tab.
 */
#[ErrorBag('participants')]
class StoreProgramParticipantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->program()) === true;
    }

    /**
     * At least one user; each an active participant, chosen once and not already in the curriculum.
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
                Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', UserRole::Participant)->where('is_active', true)),
                Rule::unique('program_participants', 'user_id')->where('program_id', $this->program()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => 'Choose at least one participant to add.',
            'user_ids.*.exists' => 'Only active participants can be added to a curriculum.',
            'user_ids.*.unique' => 'The selected participant is already in this curriculum.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_ids' => 'participants',
            'user_ids.*' => 'participant',
        ];
    }

    /**
     * The participant IDs to add.
     *
     * @return list<int>
     */
    public function participantIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('user_ids')));
    }

    /**
     * A failed add returns to the curriculum page's Participants tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.show', $this->program()).'#participants';
    }

    private function program(): Program
    {
        return $this->route('program');
    }
}
