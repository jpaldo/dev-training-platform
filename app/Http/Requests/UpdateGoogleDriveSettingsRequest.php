<?php

namespace App\Http\Requests;

use App\Services\GoogleDriveConfigurationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGoogleDriveSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:4096'],
            'picker_api_key' => ['nullable', 'string', 'max:4096'],
            'project_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]+$/'],
            'redirect_uri' => ['required', 'url', 'max:2048'],
            'enabled' => ['nullable', 'boolean'],
            'automatic_export' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $uri = (string) $this->input('redirect_uri');
            if (app()->environment('production') && parse_url($uri, PHP_URL_SCHEME) !== 'https') {
                $validator->errors()->add('redirect_uri', 'The redirect URI must use HTTPS in production.');
            }

            $configuration = app(GoogleDriveConfigurationService::class);
            $effectivePickerKey = filled($this->input('picker_api_key')) ? $this->input('picker_api_key') : $configuration->getPickerApiKey();
            $effectiveProjectNumber = filled($this->input('project_number')) ? $this->input('project_number') : $configuration->getProjectNumber();
            if (filled($effectivePickerKey) && blank($effectiveProjectNumber)) {
                $validator->errors()->add('project_number', 'A numeric Google Cloud Project Number is required when configuring Google Picker.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_id' => is_string($this->client_id) ? trim($this->client_id) : $this->client_id,
            'redirect_uri' => is_string($this->redirect_uri) ? trim($this->redirect_uri) : $this->redirect_uri,
            'picker_api_key' => is_string($this->picker_api_key) ? trim($this->picker_api_key) : $this->picker_api_key,
        ]);
    }
}
