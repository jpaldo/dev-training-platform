<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Program;
use App\Models\ProgramMentor;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A mentor or admin chooses the curriculum the Attendance page shows. An invalid choice returns to the page without
 * one, so it opens on the first curriculum of the list with the error shown.
 */
class FilterSessionAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isCoachOrAdmin() === true;
    }

    /**
     * An optional curriculum from the viewer's list (see programRules()).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'program' => ['nullable', ...self::programRules($this->user())],
        ];
    }

    /**
     * The curricula the viewer may choose, the same set CoachProgramFilterService::options() lists: any curriculum for
     * an admin, otherwise only those the viewer is a mentor of (`program_mentors`).
     *
     * @return list<Exists|string>
     */
    public static function programRules(User $viewer): array
    {
        return [
            'integer',
            $viewer->hasAnyRole(UserRole::Admin)
                ? Rule::exists(Program::class, 'id')
                : Rule::exists(ProgramMentor::class, 'program_id')->where('user_id', $viewer->id),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'program' => 'program',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'program.integer' => 'Choose a program from the list.',
            'program.exists' => 'Choose a program from the list.',
        ];
    }

    /**
     * The requested curriculum's id, or null when none is requested (the page and the export then show the first of the
     * list).
     */
    public function selectedProgramId(): ?int
    {
        return $this->filled('program') ? $this->integer('program') : null;
    }

    /**
     * A failed choice returns to the Attendance page of the same area without a curriculum.
     */
    protected function getRedirectUrl(): string
    {
        return route($this->routeIs('admin.*') ? 'admin.attendance.index' : 'coach.attendance.index');
    }
}
