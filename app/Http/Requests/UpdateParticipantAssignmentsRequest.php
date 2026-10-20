<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParticipantAssignmentsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->route('user');
        abort_unless($user instanceof User && $user->role === 'participant', 404);

        return $this->user()?->hasAnyRole(['admin']) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'coach_ids' => ['nullable', 'array'],
            'coach_ids.*' => ['integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'coach')->where('is_active', true))],
        ];
    }
}
