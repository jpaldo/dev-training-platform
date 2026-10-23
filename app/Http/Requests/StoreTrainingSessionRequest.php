<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Models\TrainingSession;
use App\Services\TrainingSessionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTrainingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TrainingSession::class) === true;
    }

    /**
     * An active program, a title, 1 up to the program's number of active weeks, and a start and end date (the end
     * date is checked against the start date and weeks in after()).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $programWeeksCount = $this->programWeeksCount();

        return [
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'weeks_count' => ['required', 'integer', 'min:1', ...($programWeeksCount === null ? [] : ["max:{$programWeeksCount}"])],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $programWeeksCount = (int) $this->programWeeksCount();

        return [
            'weeks_count.max' => 'The program has only '.$programWeeksCount.' '.Str::plural('week', $programWeeksCount).'.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'program_id' => 'program',
            'weeks_count' => 'weeks',
            'starts_on' => 'start date',
            'ends_on' => 'end date',
        ];
    }

    /**
     * Once the weeks and both dates are valid, the end date must be the one that follows from the start date and
     * weeks (TrainingSessionService::endsOn()).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['weeks_count', 'starts_on', 'ends_on'])) {
                return;
            }

            $weeksCount = (int) $this->input('weeks_count');
            $expectedEndsOn = TrainingSessionService::endsOn(Carbon::parse($this->input('starts_on')), $weeksCount);

            if (! Carbon::parse($this->input('ends_on'))->startOfDay()->equalTo($expectedEndsOn)) {
                $validator->errors()->add('ends_on', 'The end date must be '.$weeksCount.' '.Str::plural('week', $weeksCount).' after the start date ('.$expectedEndsOn->format('j M Y').').');
            }
        }];
    }

    /**
     * The number of active weeks of the chosen program, or null when the program is not an existing active one.
     */
    private function programWeeksCount(): ?int
    {
        return once(fn (): ?int => Program::query()
            ->whereKey($this->integer('program_id'))
            ->where('is_active', true)
            ->withCount(['weeks' => fn (Builder $weeks): Builder => $weeks->where('is_active', true)])
            ->first()
            ?->weeks_count);
    }
}
