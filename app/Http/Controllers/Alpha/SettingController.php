<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Alpha\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    use ProvidesAlphaShell;

    public function users(Request $request): View
    {
        return view('alpha.settings.users', [
            ...$this->shell($request, 'settings.users'),
            'users' => User::query()
                ->orderByRaw("case role when 'super_admin' then 1 when 'admin' then 2 when 'principal' then 3 when 'teacher' then 4 when 'parent' then 5 else 6 end")
                ->orderBy('name')
                ->get(),
            'roleOptions' => Role::labels(),
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(Role::all())],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        User::query()->create($validated);

        return back()->with('status', 'User baru berhasil ditambahkan.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(Role::all())],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->user()?->is($user) && (! $request->boolean('is_active') || $validated['role'] !== $user->role)) {
            return back()->withErrors([
                'user' => 'Akun yang sedang dipakai tidak bisa dinonaktifkan atau diganti role dari form ini.',
            ])->withInput();
        }

        if ($user->role === Role::SUPER_ADMIN && $validated['role'] !== Role::SUPER_ADMIN && $this->isLastSuperAdmin($user)) {
            return back()->withErrors([
                'user' => 'Super Admin terakhir tidak bisa diganti role.',
            ])->withInput();
        }

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $validated['is_active'] = $request->boolean('is_active');
        $user->update($validated);

        return back()->with('status', 'User berhasil diperbarui.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (blank($validated['password'] ?? null)) {
            unset($validated['password'], $validated['current_password']);
        } else {
            unset($validated['current_password']);
        }

        $user?->update($validated);

        return back()->with('status', 'Profil berhasil diperbarui.');
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->is($user)) {
            return back()->withErrors(['user' => 'Akun yang sedang dipakai tidak bisa dihapus.']);
        }

        if ($user->role === Role::SUPER_ADMIN && $this->isLastSuperAdmin($user)) {
            return back()->withErrors(['user' => 'Super Admin terakhir tidak bisa dihapus.']);
        }

        $user->delete();

        return back()->with('status', 'User berhasil dihapus.');
    }

    private function isLastSuperAdmin(User $user): bool
    {
        return User::query()
            ->where('role', Role::SUPER_ADMIN)
            ->whereKeyNot($user->id)
            ->doesntExist();
    }
}
