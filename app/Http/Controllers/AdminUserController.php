<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\PasswordSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public const ACCESS_TYPES = [
        'admin' => 'Admin',
        'coach' => 'Mentor / Coach',
        'qa_manual' => 'QA Manual',
        'qa_automation' => 'QA Automation',
        'qa_automation_manual' => 'QA Automation / Manual',
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'access_type' => ['nullable', 'in:admin,coach,qa_manual,qa_automation,qa_automation_manual'], 'status' => ['nullable', 'in:active,disabled'], 'path' => ['nullable', 'in:manual,automation,automation_manual']]);
        $users = User::query()->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->when($filters['path'] ?? null, fn ($query, $path) => $query->where('training_path', $path))
            ->when($filters['access_type'] ?? null, fn ($query, $type) => $query->where(fn ($q) => match ($type) {
                'admin' => $q->where('role', 'admin'),
                'coach' => $q->where('role', 'coach'),
                'qa_manual' => $q->where('role', 'participant')->where('training_path', 'manual'),
                'qa_automation' => $q->where('role', 'participant')->where('training_path', 'automation'),
                'qa_automation_manual' => $q->where('role', 'participant')->where('training_path', 'automation_manual'),
            }))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'accessTypes' => self::ACCESS_TYPES]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => null, 'accessTypes' => self::ACCESS_TYPES]);
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

    public function edit(User $user): View
    {
        $user->load(['attendanceSessions' => fn ($query) => $query->latest('check_in_at')->limit(5), 'assignedCoaches']);

        return view('admin.users.form', ['user' => $user, 'accessTypes' => self::ACCESS_TYPES, 'currentAccessType' => $this->accessType($user), 'coaches' => User::query()->where('role', 'coach')->where('is_active', true)->orderBy('name')->get()]);
    }

    public function assignments(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'participant', 404);
        $data = $request->validate(['coach_ids' => ['nullable', 'array'], 'coach_ids.*' => ['integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'coach')->where('is_active', true))]]);
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

    private function mapAccessType(string $accessType): array
    {
        return match ($accessType) {
            'admin' => ['admin', null],
            'coach' => ['coach', null],
            'qa_automation' => ['participant', 'automation'],
            'qa_automation_manual' => ['participant', 'automation_manual'],
            default => ['participant', 'manual'],
        };
    }

    private function accessType(User $user): string
    {
        return $user->role === 'admin' ? 'admin' : ($user->role === 'coach' ? 'coach' : match ($user->training_path) {
            'automation' => 'qa_automation',
            'automation_manual' => 'qa_automation_manual',
            default => 'qa_manual',
        });
    }

    private function audit(Request $request, User $target, string $action, array $metadata = []): void
    {
        AdminAuditLog::query()->create(['actor_id' => $request->user()->id, 'target_user_id' => $target->id, 'action' => $action, 'metadata' => $metadata]);
    }
}
