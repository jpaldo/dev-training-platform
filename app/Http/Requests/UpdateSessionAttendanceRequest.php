<?php

namespace App\Http\Requests;

use App\Models\Program;
use App\Services\SessionAttendanceService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A mentor or admin saves the Attendance page's grid of a curriculum: every cell posts 0 or 1 as
 * `attendance[{participantId}][{weekId}]`. Every participant must be one the viewer sees there and every week an active
 * week of that curriculum (any dates); otherwise the whole save is refused.
 */
class UpdateSessionAttendanceRequest extends FormRequest
{
    private ?Program $program = null;

    public function authorize(): bool
    {
        return $this->user()?->isCoachOrAdmin() === true;
    }

    /**
     * A curriculum from the viewer's list (FilterSessionAttendanceRequest::programRules()) and the cells, each 0 or 1.
     * The keys are checked in after().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'program' => ['required', ...FilterSessionAttendanceRequest::programRules($this->user())],
            'attendance' => ['required', 'array'],
            'attendance.*' => ['array'],
            'attendance.*.*' => ['required', 'in:0,1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'program' => 'curriculum',
            'attendance.*' => 'attendance',
            'attendance.*.*' => 'attendance',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'program.integer' => 'Choose a curriculum from the list.',
            'program.exists' => 'Choose a curriculum from the list.',
        ];
    }

    /**
     * Once the rules pass, every participant key must be one the viewer sees in the curriculum
     * (SessionAttendanceService::visibleParticipantIds()) and every week key an active week of it
     * (SessionAttendanceService::activeWeekIds()). Weeks that have not started or have no dates are accepted.
     *
     * @return list<callable(Validator): void>
     */
    public function after(SessionAttendanceService $sessionAttendance): array
    {
        return [function (Validator $validator) use ($sessionAttendance): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $cells = $this->input('attendance');
            $postedWeekIds = collect($cells)->flatMap(fn (array $weeks): array => array_keys($weeks))->unique()->all();

            if (array_diff(array_keys($cells), $sessionAttendance->visibleParticipantIds($this->user(), $this->program())) !== []) {
                $validator->errors()->add('attendance', 'You can only record attendance for participants shown in this curriculum.');
            }

            if (array_diff($postedWeekIds, $sessionAttendance->activeWeekIds($this->program())) !== []) {
                $validator->errors()->add('attendance', 'You can only record attendance for active weeks of this curriculum.');
            }
        }];
    }

    /**
     * The validated curriculum.
     */
    public function program(): Program
    {
        return $this->program ??= Program::query()->select(['id'])->findOrFail($this->integer('program'));
    }

    /**
     * The validated cells, keyed by participant id and then week id: true when attended.
     *
     * @return array<int, array<int, bool>>
     */
    public function cells(): array
    {
        return collect($this->validated('attendance'))
            ->map(fn (array $weeks): array => array_map(fn (int|string $value): bool => (string) $value === '1', $weeks))
            ->all();
    }

    /**
     * A failed save returns to the Attendance page of the same area, on the posted curriculum when it is a number.
     */
    protected function getRedirectUrl(): string
    {
        $program = filter_var($this->input('program'), FILTER_VALIDATE_INT);

        return route(
            $this->routeIs('admin.*') ? 'admin.attendance.index' : 'coach.attendance.index',
            $program === false ? [] : ['program' => $program],
        );
    }
}
