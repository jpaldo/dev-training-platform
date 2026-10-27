<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin moves a curriculum item of the route's program (an activity, a submission field or a checklist item) one
 * place up or down with the row's Move up / Move down buttons.
 */
class MoveCurriculumItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * The direction to move in: "up" or "down".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'in:up,down'],
        ];
    }

    /**
     * The validated direction to move in.
     *
     * @return 'up'|'down'
     */
    public function direction(): string
    {
        return $this->validated('direction');
    }

    private function program(): Program
    {
        return $this->route('program');
    }
}
