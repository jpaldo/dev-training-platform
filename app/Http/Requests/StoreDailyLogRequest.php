<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreDailyLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Participant;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'activity_id' => ['nullable', 'integer', 'exists:activities,id'],
            'logged_on' => ['required', 'date', 'before_or_equal:'.now(config('app.timezone', 'Asia/Manila'))->toDateString()],
            'content' => ['required', 'string', 'max:10000'],
        ];
    }
}
