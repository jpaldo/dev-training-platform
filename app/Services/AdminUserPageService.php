<?php

namespace App\Services;

use App\Enums\JobRole;
use App\Enums\TrainingPath;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminUserPageService
{
    private const USERS_PER_PAGE = 25;

    private const ACCESS_TYPES = [
        'admin' => 'Admin',
        'coach' => 'Mentor / Coach',
        'qa_manual' => 'QA Manual',
        'qa_automation' => 'QA Automation',
        'qa_automation_manual' => 'QA Automation / Manual',
    ];

    private const STATUS_OPTIONS = ['active' => 'Active', 'disabled' => 'Disabled'];

    /**
     * Everything the user management page renders: the settings links, the filter values and the matching user
     * rows. Add User is the page's single main action; every other button is secondary.
     *
     * @param  array{search?: ?string, access_type?: ?string, status?: ?string, path?: ?string}  $filters
     * @return array{createUrl: string, settingsLinks: list<array{label: string, url: string}>, passwordSetupExpiresInMinutes: int, search: string, accessTypeOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, users: LengthAwarePaginator<int, array{name: string, employee_id: string, email: string, access_type: string, path: string, is_active: bool, created: string, edit_url: string, disable_url: ?string, enable_url: ?string}>, attendanceUrl: string}
     */
    public function indexData(array $filters): array
    {
        return [
            'createUrl' => route('admin.users.create'),
            'settingsLinks' => [
                ['label' => 'Google Drive Settings', 'url' => route('admin.settings.google-drive')],
                ['label' => 'AI Settings', 'url' => route('admin.settings.ai')],
            ],
            'passwordSetupExpiresInMinutes' => (int) config('password_setup.expire'),
            'search' => (string) ($filters['search'] ?? ''),
            'accessTypeOptions' => $this->options(self::ACCESS_TYPES, $filters['access_type'] ?? null),
            'statusOptions' => $this->options(self::STATUS_OPTIONS, $filters['status'] ?? null),
            'pathOptions' => $this->options(TrainingPath::options(), $filters['path'] ?? null),
            'users' => $this->filteredUsers($filters)
                ->latest()
                ->paginate(self::USERS_PER_PAGE)
                ->withQueryString()
                ->through(fn (User $user): array => $this->userRow($user)),
            'attendanceUrl' => route('admin.attendance.index'),
        ];
    }

    /**
     * Everything the add / edit user page renders. The employee ID is required when adding a user or editing one who
     * already has it, and optional when editing a user created before it existed; the job role is optional. Editing
     * also shows the Training Drive link, the all-time attendance summary and, for a participant, the coach assignment
     * form. Saving the user is the page's single main action.
     *
     * @return array{isEditing: bool, title: string, formAction: string, formMethod: ?string, submitLabel: string, cancelUrl: string, employeeId: ?string, isEmployeeIdRequired: bool, firstName: ?string, lastName: ?string, email: ?string, jobRole: string, jobRoleOptions: list<array{value: string, label: string}>, accessType: string, accessTypeOptions: list<array{value: string, label: string}>, isActive: bool, driveUrl: ?string, coachAssignment: ?array{url: string, size: int, coachOptions: list<array{value: string, label: string, is_selected: bool}>, assignedNames: string}, attendance: ?array{completedCount: int, totalHours: string, url: string}}
     */
    public function formData(?User $user): array
    {
        return [
            'isEditing' => $user !== null,
            'title' => $user ? 'Edit User' : 'Add User',
            'formAction' => $user ? route('admin.users.update', $user) : route('admin.users.store'),
            'formMethod' => $user ? 'PUT' : null,
            'submitLabel' => $user ? 'Save User' : 'Create User',
            'cancelUrl' => route('admin.users.index'),
            'employeeId' => $user?->employee_id,
            'isEmployeeIdRequired' => $user === null || filled($user->employee_id),
            'firstName' => $user?->first_name,
            'lastName' => $user?->last_name,
            'email' => $user?->email,
            'jobRole' => $user?->job_role?->value ?? '',
            'jobRoleOptions' => collect(JobRole::options())
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'accessType' => $user ? $this->accessType($user) : '',
            'accessTypeOptions' => collect(self::ACCESS_TYPES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'isActive' => $user?->is_active ?? true,
            'driveUrl' => $user?->drive_link ?: null,
            'coachAssignment' => $user?->hasAnyRole(UserRole::Participant) ? $this->coachAssignment($user) : null,
            'attendance' => $user ? $this->attendanceSummary($user) : null,
        ];
    }

    /**
     * @param  array{search?: ?string, access_type?: ?string, status?: ?string, path?: ?string}  $filters
     * @return Builder<User>
     */
    private function filteredUsers(array $filters): Builder
    {
        return User::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('employee_id', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('is_active', $status === 'active'))
            ->when($filters['path'] ?? null, fn (Builder $query, string $path): Builder => $query->where('training_path', $path))
            ->when($filters['access_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where(fn (Builder $q): Builder => match ($type) {
                'admin' => $q->where('role', UserRole::Admin),
                'coach' => $q->where('role', UserRole::Coach),
                'qa_manual' => $q->where('role', UserRole::Participant)->where('training_path', TrainingPath::Manual),
                'qa_automation' => $q->where('role', UserRole::Participant)->where('training_path', TrainingPath::Automation),
                'qa_automation_manual' => $q->where('role', UserRole::Participant)->where('training_path', TrainingPath::AutomationManual),
            }));
    }

    /**
     * One table row ("—" for a user without an employee ID). The access type label comes from the same mapping as the
     * edit form's Access Type and the QA path from the training path's label.
     *
     * @return array{name: string, employee_id: string, email: string, access_type: string, path: string, is_active: bool, created: string, edit_url: string, disable_url: ?string, enable_url: ?string}
     */
    private function userRow(User $user): array
    {
        return [
            'name' => $user->name,
            'employee_id' => filled($user->employee_id) ? $user->employee_id : '—',
            'email' => $user->email,
            'access_type' => self::ACCESS_TYPES[$this->accessType($user)],
            'path' => $user->training_path?->label() ?? '—',
            'is_active' => (bool) $user->is_active,
            'created' => (string) $user->created_at?->format('M j, Y'),
            'edit_url' => route('admin.users.edit', $user),
            'disable_url' => $user->is_active ? route('admin.users.disable', $user) : null,
            'enable_url' => $user->is_active ? null : route('admin.users.enable', $user),
        ];
    }

    /**
     * The Access Type of an existing user (the edit form preselects it; the user list shows its label).
     */
    private function accessType(User $user): string
    {
        return match (true) {
            $user->hasAnyRole(UserRole::Admin) => 'admin',
            $user->hasAnyRole(UserRole::Coach) => 'coach',
            $user->training_path === TrainingPath::Automation => 'qa_automation',
            $user->training_path === TrainingPath::AutomationManual => 'qa_automation_manual',
            default => 'qa_manual',
        };
    }

    /**
     * The participant's coach assignment form: every active Mentor / Coach by name (assigned ones selected) and
     * the names of the coaches assigned now.
     *
     * @return array{url: string, size: int, coachOptions: list<array{value: string, label: string, is_selected: bool}>, assignedNames: string}
     */
    private function coachAssignment(User $participant): array
    {
        $participant->loadMissing('assignedCoaches');
        $coaches = User::query()->where('role', UserRole::Coach)->where('is_active', true)->orderBy('name')->get();

        return [
            'url' => route('admin.users.assignments', $participant),
            'size' => max(3, min(6, $coaches->count())),
            'coachOptions' => $coaches
                ->map(fn (User $coach): array => [
                    'value' => (string) $coach->id,
                    'label' => "{$coach->name} · {$coach->email}",
                    'is_selected' => $participant->assignedCoaches->contains($coach->id),
                ])
                ->values()
                ->all(),
            'assignedNames' => $participant->assignedCoaches->pluck('name')->join(', '),
        ];
    }

    /**
     * The number of the user's completed attendance sessions (all time) and their total hours.
     *
     * @return array{completedCount: int, totalHours: string, url: string}
     */
    private function attendanceSummary(User $user): array
    {
        $completedSessions = fn (Builder $query): Builder => $query->whereNotNull('check_out_at');
        $user->loadCount(['attendanceSessions as completed_attendance_sessions_count' => $completedSessions])
            ->loadSum(['attendanceSessions as completed_attendance_seconds' => $completedSessions], 'total_seconds');

        return [
            'completedCount' => $user->completed_attendance_sessions_count,
            'totalHours' => number_format((int) $user->completed_attendance_seconds / 3600, 2),
            'url' => route('admin.attendance.index', ['participant' => $user->id]),
        ];
    }

    /**
     * Select options with the filter's current value selected.
     *
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string, is_selected: bool}>
     */
    private function options(array $labels, ?string $selected): array
    {
        return collect($labels)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
                'is_selected' => $selected === $value,
            ])
            ->values()
            ->all();
    }
}
