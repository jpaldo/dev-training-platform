<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Models\Program;
use App\Models\TrainingWeek;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An admin deactivates an activity from the week page's Activities tab. A week keeps at least one active activity, so
 * its last one cannot be deactivated. Errors go to the `activities` bag and the admin returns to the Activities tab.
 */
#[ErrorBag('activities')]
class DeactivateActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * Deactivating takes no input.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Reject deactivating the week's last active activity.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isLastActiveActivity()) {
                $validator->errors()->add('activity', 'A week needs at least one active activity.');
            }
        }];
    }

    /**
     * A failed deactivation returns to the week page's Activities tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.weeks.show', [$this->program(), $this->week()]).'#activities';
    }

    /**
     * Whether the activity is active and no other activity of its week is.
     */
    private function isLastActiveActivity(): bool
    {
        return $this->activity()->is_active
            && $this->week()->activities()->where('is_active', true)->whereKeyNot($this->activity()->id)->doesntExist();
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    private function week(): TrainingWeek
    {
        return $this->route('week');
    }

    private function activity(): Activity
    {
        return $this->route('activity');
    }
}
