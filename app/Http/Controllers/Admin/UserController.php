<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('users.view');

        $users = User::query()->with(['department', 'roles'])
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $search) => $search->where('name', 'like', '%'.$request->string('search').'%')->orWhere('email', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('department_id'), fn (Builder $query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('role_id'), fn (Builder $query) => $query->whereHas('roles', fn (Builder $roles) => $roles->whereKey($request->integer('role_id'))))
            ->when($request->input('status') === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($request->input('status') === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'departments' => Department::orderBy('name')->get(), 'roles' => Role::orderBy('display_name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('users.create');

        return view('admin.users.create', ['departments' => Department::where('is_active', true)->orderBy('name')->get(), 'roles' => Role::orderBy('display_name')->get()]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('users.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'employee_number' => ['nullable', 'string', 'max:255', 'unique:users,employee_number'], 'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'], 'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_ids' => ['array'], 'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'employee_number' => $data['employee_number'] ?? null,
                'phone' => $data['phone'] ?? null, 'department_id' => $data['department_id'] ?? null,
                'password' => $data['password'], 'is_active' => true,
            ]);
            $user->roles()->sync($data['role_ids'] ?? []);

            return $user;
        });
        $auditLogger->record('user_created', 'users', 'Pengguna baru dibuat.', $user, null, $user->only(['name', 'email', 'employee_number', 'phone', 'department_id', 'is_active']));

        return redirect()->route('admin.users.show', $user)->with('status', 'Pengguna berhasil dibuat.');
    }

    public function show(User $user): View
    {
        Gate::authorize('users.view');
        $user->load(['department', 'roles']);

        return view('admin.users.show', ['user' => $user, 'roles' => Role::orderBy('display_name')->get()]);
    }

    public function edit(User $user): View
    {
        Gate::authorize('users.update');
        $user->load('roles');

        return view('admin.users.edit', ['user' => $user, 'departments' => Department::orderBy('name')->get(), 'roles' => Role::orderBy('display_name')->get()]);
    }

    public function update(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('users.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'employee_number' => ['nullable', 'string', 'max:255', Rule::unique('users')->ignore($user)], 'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $oldValues = $user->only(['name', 'email', 'employee_number', 'phone', 'department_id']);
        $user->update($data);
        $auditLogger->record('user_updated', 'users', 'Data pengguna diperbarui.', $user, $oldValues, $user->only(array_keys($oldValues)));

        return redirect()->route('admin.users.show', $user)->with('status', 'Data pengguna diperbarui.');
    }

    public function updateRoles(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('roles.assign');
        $data = $request->validate(['role_ids' => ['array'], 'role_ids.*' => ['integer', 'distinct', 'exists:roles,id']]);
        $ids = $data['role_ids'] ?? [];
        $oldRoleIds = $user->roles()->pluck('roles.id')->all();

        DB::transaction(function () use ($user, $ids): void {
            $this->protectLastAdministrator($user, ! in_array(Role::where('name', 'admin_system')->value('id'), $ids));
            $user->roles()->sync($ids);
        });
        $auditLogger->record('roles_changed', 'users', 'Role pengguna diperbarui.', $user, ['role_ids' => $oldRoleIds], ['role_ids' => $ids]);

        return back()->with('status', 'Role pengguna diperbarui.');
    }

    public function updateStatus(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('users.deactivate');
        abort_if($request->user()->is($user), 422, 'Akun sendiri tidak dapat dinonaktifkan.');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];
        $oldActive = $user->is_active;

        DB::transaction(function () use ($user, $active): void {
            if (! $active && $user->is_active && $user->hasRole('admin_system')) {
                $this->protectLastAdministrator($user, true);
            }
            $user->update(['is_active' => $active]);
        });
        $auditLogger->record($active ? 'user_activated' : 'user_deactivated', 'users', 'Status akun diperbarui.', $user, ['is_active' => $oldActive], ['is_active' => $active]);

        return back()->with('status', 'Status akun diperbarui.');
    }

    public function resetPassword(Request $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('users.reset_password');
        $data = $request->validate(['password' => ['required', 'string', 'min:12', 'confirmed']]);
        $user->update(['password' => Hash::make($data['password'])]);
        $auditLogger->record('password_reset', 'users', 'Kata sandi pengguna direset administrator.', $user);

        return back()->with('status', 'Kata sandi berhasil direset.');
    }

    private function protectLastAdministrator(User $user, bool $removingAdmin): void
    {
        if (! $removingAdmin || ! $user->hasRole('admin_system') || ! $user->is_active) {
            return;
        }

        $adminIds = User::query()->where('is_active', true)->whereHas('roles', fn (Builder $query) => $query->where('roles.name', 'admin_system'))->lockForUpdate()->pluck('users.id');
        abort_if($adminIds->count() <= 1, 422, 'Administrator aktif terakhir tidak dapat dinonaktifkan atau kehilangan role administrator.');
    }
}
