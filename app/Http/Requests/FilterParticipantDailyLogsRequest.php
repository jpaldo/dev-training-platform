<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FilterParticipantDailyLogsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $participant = $this->route('participant');
        abort_unless($participant instanceof User && $participant->role === UserRole::Participant, 404);

        return $this->user()?->can('view', $participant) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'week' => ['nullable', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:'.now(config('app.timezone', 'Asia/Manila'))->toDateString()],
        ];
    }
}
