<?php

namespace App\Http\Controllers;

use App\Enums\JobRole;
use App\Enums\UserRole;
use App\Http\Requests\FilterAdminUsersRequest;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AdminUserPageService;
use App\Services\PasswordSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(FilterAdminUsersRequest $request, AdminUserPageService $page): View
    {
        $filters = [...$request->validated(), 'access_type' => $request->enum('access_type', UserRole::class)];

        return view('admin.users.index', $page->indexData($filters));
    }

    public function create(AdminUserPageService $page): View
    {
        return view('admin.users.form', $page->formData(null));
    }

    public function store(StoreAdminUserRequest $request, PasswordSetupService $passwordSetup): RedirectResponse
    {
        $data = $request->validated();
        $user = User::query()->create([
            ...$this->identityAttributes($request),
            'email' => $data['email'],
            'password' => Hash::make(Str::random(96)),
            'role' => $request->enum('access_type', UserRole::class),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->audit($request, $user, 'user_created', ['access_type' => $data['access_type']]);

        $token = $passwordSetup->issue($user, $request->user());

        return $this->withPasswordSetupLink($user, $token);
    }

    public function edit(User $user, AdminUserPageService $page): View
    {
        return view('admin.users.form', $page->formData($user));
    }

    public function update(UpdateAdminUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $role = $request->enum('access_type', UserRole::class);
        $willBeActive = (bool) ($data['is_active'] ?? false);
        if ($user->role === UserRole::Admin && $user->is_active && ! $willBeActive) {
            $this->ensureCanDeactivate($user);
        }
        if ($user->role === UserRole::Admin && $role !== UserRole::Admin && $user->is_active && User::query()->where('role', UserRole::Admin)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['access_type' => 'The last active admin cannot be changed to another access type.']);
        }
        $attributes = [
            ...$this->identityAttributes($request),
            'email' => $data['email'],
            'role' => $role,
            'is_active' => $data['is_active'] ?? false,
        ];
        if (filled($data['password'] ?? null)) {
            $attributes['password'] = Hash::make($data['password']);
        }
        $user->update($attributes);
        $this->audit($request, $user, 'user_updated', [
            'access_type' => $data['access_type'],
            'is_active' => $willBeActive,
            'employee_id_changed' => $user->wasChanged('employee_id'),
        ]);

        return redirect()->route('admin.users.index')->with('status', 'User updated successfully.');
    }

    public function disable(User $user): RedirectResponse
    {
        $this->ensureCanDeactivate($user);
        $user->update(['is_active' => false]);
        $this->audit(request(), $user, 'account_disabled');

        return back()->with('status', 'Account disabled. Historical training records were preserved.');
    }

    public function enable(User $user): RedirectResponse
    {
        $user->update(['is_active' => true]);
        $this->audit(request(), $user, 'account_enabled');

        return back()->with('status', 'Account enabled.');
    }

    /**
     * A new one-time password setup link for an active user whose first link expired or was lost. Any earlier unused
     * link stops working (PasswordSetupService::issue()).
     */
    public function issuePasswordSetupLink(User $user, PasswordSetupService $passwordSetup): RedirectResponse
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => 'Enable this account before creating a password setup link.']);
        }
        $token = $passwordSetup->issue($user, request()->user());
        $this->audit(request(), $user, 'password_setup_link_issued');

        return $this->withPasswordSetupLink($user, $token);
    }

    /**
     * Back to the user list, which shows the link once, with the address it is for.
     */
    private function withPasswordSetupLink(User $user, string $token): RedirectResponse
    {
        return redirect()->route('admin.users.index')->with([
            'password_setup_url' => route('password.setup', ['token' => $token]),
            'password_setup_email' => $user->email,
        ]);
    }

    private function ensureCanDeactivate(User $user): void
    {
        if ($user->role === UserRole::Admin && $user->is_active && User::query()->where('role', UserRole::Admin)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'The last active admin cannot be disabled.']);
        }
    }

    /**
     * The employee ID, first and last name and job role from the add / edit user form. `name` follows the first and
     * last name through the `User` saving hook.
     *
     * @return array{employee_id: ?string, first_name: string, last_name: string, job_role: ?JobRole}
     */
    private function identityAttributes(StoreAdminUserRequest|UpdateAdminUserRequest $request): array
    {
        return [
            'employee_id' => $request->validated('employee_id'),
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'job_role' => $request->enum('job_role', JobRole::class),
        ];
    }

    private function audit(Request $request, User $target, string $action, array $metadata = []): void
    {
        AdminAuditLog::query()->create(['actor_id' => $request->user()->id, 'target_user_id' => $target->id, 'action' => $action, 'metadata' => $metadata]);
    }
}
