<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\TrainingWeek;
use App\Models\User;
use App\Services\WeekProgressionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyVideoRecordingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * A week whose checklist does not ask for the video answers 404, a week
     * the participant cannot access yet or a curriculum that has ended
     * answers 403, and a week whose Gate the participant has passed is
     * locked with a 422.
     */
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        /** @var Program $program */
        $program = $this->route('program');
        /** @var TrainingWeek $trainingWeek */
        $trainingWeek = $this->route('trainingWeek');
        abort_unless($trainingWeek->asksForWeeklyVideo(), 404);
        $progression = app(WeekProgressionService::class);
        $progression->ensureCanAccessWeek($user, $program, $trainingWeek);
        $progression->ensureProgramAcceptsWork($user, $program);
        abort_if($progression->hasPassedActiveGate($user, $trainingWeek), 422, 'This week is locked after Gate completion.');

        return true;
    }

    /**
     * Get the validation rules that apply to the request: the Google Drive file ID and resource key the Picker
     * returned, and the optional notes.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'drive_file_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'resource_key' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'drive_file_id.required' => 'Choose a video from Google Drive.',
        ];
    }
}
