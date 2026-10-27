<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin dates every week of a curriculum from the first day of Week 1 on the program page's schedule form. Errors
 * go to the `schedule` bag and the admin returns to the schedule.
 */
#[ErrorBag('schedule')]
class ScheduleProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * The first day of Week 1.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_on' => 'start date',
        ];
    }

    /**
     * A failed save returns to the program page's schedule.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.show', $this->program()).'#schedule';
    }

    private function program(): Program
    {
        return $this->route('program');
    }
}
