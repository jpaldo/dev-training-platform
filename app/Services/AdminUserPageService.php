<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    private const PATH_OPTIONS = ['manual' => 'Manual QA', 'automation' => 'Automation QA'];

    private const RECENT_ATTENDANCE_SESSIONS = 5;

    /**
     * Everything the user management page renders: the settings links, the filter values and the matching user
     * rows. Add User is the page's single main action; every other button is secondary.
     *
     * @param  array{search?: ?string, access_type?: ?string, status?: ?string, path?: ?string}  $filters
     * @return array{createUrl: string, settingsLinks: list<array{label: string, url: string}>, passwordSetupExpiresInMinutes: int, search: string, accessTypeOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, pathOptions: list<array{value: string, label: string, is_selected: bool}>, users: LengthAwarePaginator<int, array{name: string, email: string, access_type: string, path: string, is_active: bool, created: string, edit_url: string, disable_url: ?string, enable_url: ?string}>, attendanceUrl: string}
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
            'pathOptions' => $this->options(self::PATH_OPTIONS, $filters['path'] ?? null),
            'users' => $this->filteredUsers($filters)
                ->latest()
                ->paginate(self::USERS_PER_PAGE)
                ->withQueryString()
                ->through(fn (User $user): array => $this->userRow($user)),
            'attendanceUrl' => route('admin.attendance.index'),
        ];
    }

    /**
     * Everything the add / edit user page renders. Editing also shows the Training Drive link, the recent attendance
     * summary and, for a participant, the coach assignment form. Saving the user is the page's single main action.
     *
     * @return array{isEditing: bool, title: string, formAction: string, formMethod: ?string, submitLabel: string, cancelUrl: string, name: ?string, email: ?string, accessType: string, accessTypeOptions: list<array{value: string, label: string}>, isActive: bool, driveUrl: ?string, coachAssignment: ?array{url: string, size: int, coachOptions: list<array{value: string, label: string, is_selected: bool}>, assignedNames: string}, attendance: ?array{completedCount: int, totalHours: string, url: string}}
     */
    public function formData(?User $user): array
    {
        $user?->load(['attendanceSessions' => fn (Relation $query): Relation => $query->latest('check_in_at')->limit(self::RECENT_ATTENDANCE_SESSIONS)]);

        return [
            'isEditing' => $user !== null,
            'title' => $user ? 'Edit User' : 'Add User',
            'formAction' => $user ? route('admin.users.update', $user) : route('admin.users.store'),
            'formMethod' => $user ? 'PUT' : null,
            'submitLabel' => $user ? 'Save User' : 'Create User',
            'cancelUrl' => route('admin.users.index'),
            'name' => $user?->name,
            'email' => $user?->email,
            'accessType' => $user ? $this->accessType($user) : '',
            'accessTypeOptions' => collect(self::ACCESS_TYPES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'isActive' => $user?->is_active ?? true,
            'driveUrl' => $user?->drive_link ?: null,
            'coachAssignment' => $user?->hasAnyRole(['participant']) ? $this->coachAssignment($user) : null,
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
            ->when($filters['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('is_active', $status === 'active'))
            ->when($filters['path'] ?? null, fn (Builder $query, string $path): Builder => $query->where('training_path', $path))
            ->when($filters['access_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where(fn (Builder $q): Builder => match ($type) {
                'admin' => $q->where('role', 'admin'),
                'coach' => $q->where('role', 'coach'),
                'qa_manual' => $q->where('role', 'participant')->where('training_path', 'manual'),
                'qa_automation' => $q->where('role', 'participant')->where('training_path', 'automation'),
                'qa_automation_manual' => $q->where('role', 'participant')->where('training_path', 'automation_manual'),
            }));
    }

    /**
     * One table row. The access type label and the QA path are worded as the page always showed them (a QA
     * Automation / Manual participant reads "QA Manual" and "Automation_manual"; see Open decisions in the plan).
     *
     * @return array{name: string, email: string, access_type: string, path: string, is_active: bool, created: string, edit_url: string, disable_url: ?string, enable_url: ?string}
     */
    private function userRow(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'access_type' => match (true) {
                $user->hasAnyRole(['admin']) => 'Admin',
                $user->hasAnyRole(['coach']) => 'Mentor / Coach',
                $user->training_path === 'automation' => 'QA Automation',
                default => 'QA Manual',
            },
            'path' => $user->training_path ? ucfirst($user->training_path) : '—',
            'is_active' => (bool) $user->is_active,
            'created' => (string) $user->created_at?->format('M j, Y'),
            'edit_url' => route('admin.users.edit', $user),
            'disable_url' => $user->is_active ? route('admin.users.disable', $user) : null,
            'enable_url' => $user->is_active ? null : route('admin.users.enable', $user),
        ];
    }

    /**
     * The Access Type the edit form preselects for an existing user.
     */
    private function accessType(User $user): string
    {
        return match (true) {
            $user->hasAnyRole(['admin']) => 'admin',
            $user->hasAnyRole(['coach']) => 'coach',
            $user->training_path === 'automation' => 'qa_automation',
            $user->training_path === 'automation_manual' => 'qa_automation_manual',
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
        $coaches = User::query()->where('role', 'coach')->where('is_active', true)->orderBy('name')->get();

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
     * The completed sessions and their total hours among the user's five latest attendance sessions.
     *
     * @return array{completedCount: int, totalHours: string, url: string}
     */
    private function attendanceSummary(User $user): array
    {
        $completedSessions = $user->attendanceSessions->whereNotNull('check_out_at');

        return [
            'completedCount' => $completedSessions->count(),
            'totalHours' => number_format($completedSessions->sum(fn (AttendanceSession $session): float => $session->totalHours()), 2),
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
