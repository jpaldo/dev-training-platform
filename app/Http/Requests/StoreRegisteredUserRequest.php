<?php

namespace App\Http\Requests;

use App\Enums\JobRole;
use App\Models\TrainingSession;
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
     * Get the validation rules that apply to the request. The training sessions are optional; each one ticked must be
     * open to join (it exists and is not completed).
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
            'training_session_ids' => ['nullable', 'array'],
            'training_session_ids.*' => ['integer', 'distinct', Rule::in(TrainingSession::query()->openToJoin()->pluck('id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'training_session_ids.*.in' => 'The selected training is not open to join.',
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
            'training_session_ids' => 'trainings',
            'training_session_ids.*' => 'training',
        ];
    }

    /**
     * The account fields to store on the new user, without the training sessions to join.
     *
     * @return array{employee_id: string, first_name: string, last_name: string, email: string, job_role: string, password: string}
     */
    public function userAttributes(): array
    {
        return $this->safe()->except('training_session_ids');
    }

    /**
     * The training sessions the new participant ticked to join; none when they ticked nothing.
     *
     * @return Collection<int, TrainingSession>
     */
    public function trainingSessionsToJoin(): Collection
    {
        return TrainingSession::query()->whereKey($this->validated('training_session_ids') ?? [])->get();
    }
}
