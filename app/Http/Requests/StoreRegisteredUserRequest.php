<?php

namespace App\Http\Requests;

use App\Enums\JobRole;
use App\Models\Program;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegisteredUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. The curricula are optional; each one ticked must be open to
     * join (offered and not completed).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('users')],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')],
            'job_role' => ['required', Rule::enum(JobRole::class)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['integer', 'distinct', Rule::in(Program::query()->openToJoin()->pluck('id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'program_ids.*.in' => 'The selected curriculum is not open to join.',
        ];
    }

    /**
     * Get the readable names of the registration fields.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'employee ID',
            'program_ids' => 'curricula',
            'program_ids.*' => 'curriculum',
        ];
    }

    /**
     * The account fields to store on the new user, without the curricula to join.
     *
     * @return array{employee_id: string, first_name: string, last_name: string, email: string, job_role: string, password: string}
     */
    public function userAttributes(): array
    {
        return $this->safe()->except('program_ids');
    }

    /**
     * The curricula the new participant ticked to join; none when they ticked nothing.
     *
     * @return Collection<int, Program>
     */
    public function programsToJoin(): Collection
    {
        return Program::query()->whereKey($this->validated('program_ids') ?? [])->get();
    }
}
