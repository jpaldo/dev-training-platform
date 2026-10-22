<?php

namespace App\Http\Requests;

use App\Enums\TrainingPath;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($this->user())],
            'training_path' => [Rule::requiredIf($this->user()?->role === UserRole::Participant), 'nullable', Rule::enum(TrainingPath::class)],
            'drive_link' => ['nullable', 'string', 'max:2048', 'url', 'regex:#^https://(?:www\.)?drive\.google\.com/drive/(?:u/\d+/)?folders/[A-Za-z0-9_-]+(?:\?.*)?$#'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['drive_link' => filled($this->input('drive_link')) ? trim((string) $this->input('drive_link')) : null]);
    }
}
