<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\ProgramResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin renames a curriculum resource; only its title changes. Each resource's errors go to its own bag (see
 * errorBagFor()), so the Resources tab shows them beside the right Rename form, and the admin returns to that tab.
 */
class UpdateProgramResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->program()) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * The error bag of a resource's Rename form, e.g. "renameResource12".
     */
    public static function errorBagFor(ProgramResource $resource): string
    {
        return 'renameResource'.$resource->id;
    }

    /**
     * Put the errors in the resource's own bag before the request redirects back.
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->errorBag = self::errorBagFor($this->resource());

        parent::failedValidation($validator);
    }

    /**
     * A failed rename returns to the curriculum page's Resources tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.programs.show', $this->program()).'#resources';
    }

    private function program(): Program
    {
        return $this->route('program');
    }

    private function resource(): ProgramResource
    {
        return $this->route('resource');
    }
}
