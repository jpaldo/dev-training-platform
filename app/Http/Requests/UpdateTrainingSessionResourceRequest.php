<?php

namespace App\Http\Requests;

use App\Models\TrainingSession;
use App\Models\TrainingSessionResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin renames a session resource; only its title changes. Each resource's errors go to its own bag (see
 * errorBagFor()), so the Resources tab shows them beside the right Rename form, and the admin returns to that tab.
 */
class UpdateTrainingSessionResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->trainingSession()) === true;
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
    public static function errorBagFor(TrainingSessionResource $resource): string
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
     * A failed rename returns to the dashboard's Resources tab.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.sessions.show', $this->trainingSession()).'#resources';
    }

    private function trainingSession(): TrainingSession
    {
        return $this->route('trainingSession');
    }

    private function resource(): TrainingSessionResource
    {
        return $this->route('resource');
    }
}
