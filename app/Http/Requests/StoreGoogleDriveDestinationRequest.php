<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoogleDriveDestinationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'folder_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/'],
            'folder_name' => ['nullable', 'string', 'max:255'],
            'resource_key' => ['nullable', 'string', 'max:255'],
            'drive_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
