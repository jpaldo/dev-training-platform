<?php

namespace App\Http\Requests;

use App\Models\TrainingSession;
use App\Rules\GitHubRepositoryUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSessionRepositoryRequest extends FormRequest
{
    /**
     * Only a participant in the route's session saves their repository for it (the route already requires the
     * participant role).
     */
    public function authorize(): bool
    {
        $trainingSession = $this->route('trainingSession');

        return $this->user() !== null
            && $trainingSession instanceof TrainingSession
            && $trainingSession->includesParticipant($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'repository_url' => ['required', 'string', 'max:255', new GitHubRepositoryUrl],
        ];
    }

    /**
     * Get the readable names of the fields.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'repository_url' => 'repository link',
        ];
    }
}
