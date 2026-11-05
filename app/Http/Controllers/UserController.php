<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Daftar semua user beserta role dan divisinya.
     * Eager loading untuk mencegah N+1 query.
     */
    public function index(): View
    {
        $users = User::query()
            ->with(['role', 'division'])
            ->orderBy('name')
            ->paginate(50);

        $adminCount = User::whereRelation('role', 'slug', Role::ADMIN)->count();

        return view('users.index', [
            'users' => $users,
            'adminCount' => $adminCount,
        ]);
    }

    /**
     * Form create user baru.
     */
    public function create(): View
    {
        $roles = Role::orderBy('name')->get(['id', 'name', 'slug']);
        $divisions = Division::orderBy('name')->get(['id', 'name']);

        return view('users.create', compact('roles', 'divisions'));
    }

    /**
     * Simpan user baru ke database.
     */
    public function store(Request $request): RedirectResponse
    {
        $divisionRule = $this->isPrivilegedRoleId($request->input('role_id'))
            ? 'nullable|exists:divisions,id'
            : 'required|exists:divisions,id';

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
            'division_id' => $divisionRule,
        ], [
            'division_id.required' => 'Divisi wajib dipilih untuk role Staf Divisi.',
            'division_id.exists' => 'Divisi yang dipilih tidak valid.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $user->role()->associate($validated['role_id'])->save();

        if ($validated['division_id'] ?? null) {
            $user->division()->associate($validated['division_id'])->save();
        }

        return redirect()->route('users.index')->with('success', "User \"{$user->name}\" berhasil dibuat.");
    }

    /**
     * Form edit user.
     */
    public function edit(User $user): View
    {
        $roles = Role::orderBy('name')->get(['id', 'name', 'slug']);
        $divisions = Division::orderBy('name')->get(['id', 'name']);

        return view('users.edit', compact('user', 'roles', 'divisions'));
    }

    /**
     * Update user. Password hanya diganti jika diisi.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $divisionRule = $this->isPrivilegedRoleId($request->input('role_id'))
            ? 'nullable|exists:divisions,id'
            : 'required|exists:divisions,id';

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
            'division_id' => $divisionRule,
        ], [
            'division_id.required' => 'Divisi wajib dipilih untuk role Staf Divisi.',
            'division_id.exists' => 'Divisi yang dipilih tidak valid.',
        ]);

        $newRole = Role::find($validated['role_id']);
        $isDemotingAdmin = $user->hasRole(Role::ADMIN) && ($newRole?->slug !== Role::ADMIN);

        if ($isDemotingAdmin) {
            if ($user->is(auth()->user())) {
                return redirect()->route('users.index')->with('error', 'Tidak dapat menurunkan role akun admin sendiri.');
            }

            $adminCount = User::whereRelation('role', 'slug', Role::ADMIN)->count();
            if ($adminCount <= 1) {
                return redirect()->route('users.index')->with('error', 'Tidak dapat mengubah role admin terakhir.');
            }
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->role()->associate($validated['role_id']);
        $user->division()->associate($validated['division_id'] ?? null);
        $user->save();

        return redirect()->route('users.index')->with('success', "User \"{$user->name}\" berhasil diperbarui.");
    }

    /**
     * Hard delete user. Cascade di DB menghapus datasets + metrics miliknya.
     * audit_logs.user_id di-set NULL via nullOnDelete (append-only terjaga).
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->is(auth()->user())) {
            return redirect()->route('users.index')->with('error', 'Tidak dapat menghapus akun sendiri.');
        }

        if ($user->hasRole(Role::ADMIN)) {
            $adminCount = User::whereRelation('role', 'slug', Role::ADMIN)->count();
            if ($adminCount <= 1) {
                return redirect()->route('users.index')->with('error', 'Tidak dapat menghapus akun admin terakhir.');
            }
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('users.index')->with('success', "User \"{$name}\" berhasil dihapus.");
    }

    /**
     * Role privileged (Admin, Management, Editor Dashboard) tidak terikat pada
     * satu divisi. Role non-privileged (Staf Divisi) wajib memiliki divisi.
     */
    private function isPrivilegedRoleId(mixed $roleId): bool
    {
        if (! $roleId) {
            return false;
        }

        $role = Role::find($roleId);

        return $role && in_array($role->slug, [
            Role::ADMIN,
            Role::MANAGEMENT,
            Role::EDITOR_DASHBOARD,
        ], true);
    }
}
