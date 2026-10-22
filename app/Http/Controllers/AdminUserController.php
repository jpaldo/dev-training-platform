<?php

namespace App\Http\Controllers;

use App\Enums\TrainingPath;
use App\Http\Requests\FilterAdminUsersRequest;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Http\Requests\UpdateParticipantAssignmentsRequest;
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
        return view('admin.users.index', $page->indexData($request->validated()));
    }

    public function create(AdminUserPageService $page): View
    {
        return view('admin.users.form', $page->formData(null));
    }

    public function store(StoreAdminUserRequest $request, PasswordSetupService $passwordSetup): RedirectResponse
    {
        $data = $request->validated();
        [$role, $path] = $this->mapAccessType($data['access_type']);
        $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make(Str::random(96)), 'role' => $role, 'training_path' => $path, 'is_active' => $data['is_active'] ?? true]);
        $this->audit($request, $user, 'user_created', ['access_type' => $data['access_type']]);

        $token = $passwordSetup->issue($user, $request->user());

        return redirect()->route('admin.users.index')->with('password_setup_url', route('password.setup', ['token' => $token]));
    }

    public function edit(User $user, AdminUserPageService $page): View
    {
        return view('admin.users.form', $page->formData($user));
    }

    public function assignments(UpdateParticipantAssignmentsRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $coachIds = $data['coach_ids'] ?? [];
        $user->assignedCoaches()->sync($coachIds);
        $this->audit($request, $user, 'participant_assignments_updated', ['coach_count' => count($coachIds)]);

        return back()->with('status', 'Participant assignments updated.');
    }

    public function update(UpdateAdminUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        [$role, $path] = $this->mapAccessType($data['access_type']);
        $willBeActive = (bool) ($data['is_active'] ?? false);
        if ($user->role === 'admin' && $user->is_active && ! $willBeActive) {
            $this->ensureCanDeactivate($user);
        }
        if ($user->role === 'admin' && $role !== 'admin' && $user->is_active && User::query()->where('role', 'admin')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['access_type' => 'The last active admin cannot be changed to another access type.']);
        }
        $attributes = ['name' => $data['name'], 'email' => $data['email'], 'role' => $role, 'training_path' => $path, 'is_active' => $data['is_active'] ?? false];
        if (filled($data['password'] ?? null)) {
            $attributes['password'] = Hash::make($data['password']);
        }
        $user->update($attributes);
        $this->audit($request, $user, 'user_updated', ['access_type' => $data['access_type'], 'is_active' => $willBeActive]);

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

    private function ensureCanDeactivate(User $user): void
    {
        if ($user->role === 'admin' && $user->is_active && User::query()->where('role', 'admin')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'The last active admin cannot be disabled.']);
        }
    }

    /**
     * The role and training path of an Access Type (coaches and admins have no path).
     *
     * @return array{string, ?TrainingPath}
     */
    private function mapAccessType(string $accessType): array
    {
        return match ($accessType) {
            'admin' => ['admin', null],
            'coach' => ['coach', null],
            'qa_automation' => ['participant', TrainingPath::Automation],
            'qa_automation_manual' => ['participant', TrainingPath::AutomationManual],
            default => ['participant', TrainingPath::Manual],
        };
    }

    private function audit(Request $request, User $target, string $action, array $metadata = []): void
    {
        AdminAuditLog::query()->create(['actor_id' => $request->user()->id, 'target_user_id' => $target->id, 'action' => $action, 'metadata' => $metadata]);
    }
}
