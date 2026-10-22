<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSecondReaderScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = $this->route('submissionVersion');
        $participant = $version?->loadMissing('submission.participant')->submission?->participant;

        return $participant && $this->user()?->isCoachOrAdmin() && $this->user()->canManageParticipant($participant);
    }

    public function rules(): array
    {
        return [
            'second_reader_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn (Builder $q): Builder => $q->whereIn('role', [UserRole::Coach, UserRole::Admin])->where('is_active', true))],
            'second_score' => ['required', 'integer', 'min:0', 'max:100'],
            'second_reasoning' => ['required', 'string', 'max:10000'],
            'moderation_outcome' => ['nullable', 'string', 'max:100'],
            'moderation_reason' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
