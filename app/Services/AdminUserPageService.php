<?php

namespace App\Services;

use App\Enums\JobRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminUserPageService
{
    private const USERS_PER_PAGE = 25;

    /** @var array<string, string> The Access Type option texts by role value, in display order. */
    private const ACCESS_TYPES = [
        UserRole::Admin->value => 'Admin',
        UserRole::Coach->value => 'Mentor',
        UserRole::Participant->value => 'Participant',
    ];

    private const STATUS_OPTIONS = ['active' => 'Active', 'disabled' => 'Disabled'];

    /**
     * Everything the user management page renders: the settings links, the filter values and the matching user
     * rows. Add User is the page's single main action; every other button is secondary.
     *
     * @param  array{search?: ?string, access_type?: ?UserRole, status?: ?string}  $filters
     * @return array{createUrl: string, settingsLinks: list<array{label: string, url: string}>, passwordSetupExpiresInMinutes: int, search: string, accessTypeOptions: list<array{value: string, label: string, is_selected: bool}>, statusOptions: list<array{value: string, label: string, is_selected: bool}>, users: LengthAwarePaginator<int, array{name: string, employee_id: string, email: string, access_type: string, is_active: bool, created: string, edit_url: string, disable_url: ?string, enable_url: ?string}>}
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
            'accessTypeOptions' => $this->options(self::ACCESS_TYPES, ($filters['access_type'] ?? null)?->value),
            'statusOptions' => $this->options(self::STATUS_OPTIONS, $filters['status'] ?? null),
            'users' => $this->filteredUsers($filters)
                ->latest()
                ->paginate(self::USERS_PER_PAGE)
                ->withQueryString()
                ->through(fn (User $user): array => $this->userRow($user)),
        ];
    }

    /**
     * Everything the add / edit user page renders. The employee ID is required when adding a user or editing one who
     * already has it, and optional when editing a user created before it existed; the job role is optional. Editing
     * also shows the Training Drive link. Saving the user is the page's single main action.
     *
     * @return array{isEditing: bool, title: string, formAction: string, formMethod: ?string, submitLabel: string, cancelUrl: string, employeeId: ?string, isEmployeeIdRequired: bool, firstName: ?string, lastName: ?string, email: ?string, jobRole: string, jobRoleOptions: list<array{value: string, label: string}>, accessType: string, accessTypeOptions: list<array{value: string, label: string}>, isActive: bool, driveUrl: ?string}
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
            'accessType' => $user?->role->value ?? '',
            'accessTypeOptions' => collect(self::ACCESS_TYPES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'isActive' => $user?->is_active ?? true,
            'driveUrl' => $user?->drive_link ?: null,
        ];
    }

    /**
     * @param  array{search?: ?string, access_type?: ?UserRole, status?: ?string}  $filters
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
            ->when($filters['access_type'] ?? null, fn (Builder $query, UserRole $role): Builder => $query->where('role', $role));
    }

    /**
     * One table row ("—" for a user without an employee ID). The access type label is the edit form's Access Type
     * option text for the user's role.
     *
     * @return array{name: string, employee_id: string, email: string, access_type: string, is_active: bool, created: string, edit_url: string, disable_url: ?string, enable_url: ?string}
     */
    private function userRow(User $user): array
    {
        return [
            'name' => $user->name,
            'employee_id' => filled($user->employee_id) ? $user->employee_id : '—',
            'email' => $user->email,
            'access_type' => self::ACCESS_TYPES[$user->role->value],
            'is_active' => (bool) $user->is_active,
            'created' => (string) $user->created_at?->format('M j, Y'),
            'edit_url' => route('admin.users.edit', $user),
            'disable_url' => $user->is_active ? route('admin.users.disable', $user) : null,
            'enable_url' => $user->is_active ? null : route('admin.users.enable', $user),
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
