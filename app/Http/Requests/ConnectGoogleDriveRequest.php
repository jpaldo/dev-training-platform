<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A participant starts the Google Drive connection, optionally from a training page they return to afterwards
 * (`return_to`). An unusable `return_to` is never an error: the connection starts anyway and returns to the profile.
 */
class ConnectGoogleDriveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'return_to' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * The training page to return to after Google answers, or null for the profile. Only a path on this site under
     * `/training/` is kept, checked both as given and percent-decoded.
     */
    public function returnTo(): ?string
    {
        if ($this->validator->errors()->isNotEmpty()) {
            return null;
        }

        $returnTo = $this->validated('return_to');

        if (! is_string($returnTo) || ! $this->isTrainingPath($returnTo) || ! $this->isTrainingPath(rawurldecode($returnTo))) {
            return null;
        }

        return $returnTo;
    }

    /**
     * A failed rule does not redirect back with errors (a GET would just bounce); returnTo() falls back instead.
     */
    protected function failedValidation(Validator $validator): void {}

    /**
     * Whether the path starts with `/training/` and has no scheme or host (`//`), no backslash, no `.` or `..`
     * segment and no whitespace or control character.
     */
    private function isTrainingPath(string $path): bool
    {
        return str_starts_with($path, '/training/')
            && ! str_contains($path, '//')
            && ! str_contains($path, '\\')
            && preg_match('#/\.{1,2}(?:[/?\#]|$)#', $path) !== 1
            && preg_match('/[\x00-\x20\x7F]/', $path) !== 1;
    }
}
