<?php

namespace App\Http\Requests;

use App\Models\Program;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin offers a curriculum to participants or withdraws it from the program page's Overview tab.
 */
class UpdateProgramOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->program()) === true;
    }

    /**
     * Whether the curriculum is to be offered.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'offered' => ['required', 'boolean'],
        ];
    }

    /**
     * A failed save returns to the program page's Overview tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.show', $this->program()).'#overview';
    }

    private function program(): Program
    {
        return $this->route('program');
    }
}
