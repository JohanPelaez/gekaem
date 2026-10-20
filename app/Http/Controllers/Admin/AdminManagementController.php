<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminManagementController extends Controller
{
    /**
     * Display the SuperAdmin Beta Management Panel.
     */
    public function index(Request $request): Response
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $tenants = Tenant::with([
            'users' => fn ($q) => $q->withPivot('role'),
        ])->withCount(['orders'])->get();

        $users = User::with([
            'tenants' => fn ($q) => $q->withPivot('role'),
        ])->orderBy('name')->get();

        return Inertia::render('Admin/Index', [
            'tenants' => $tenants,
            'users' => $users,
        ]);
    }

    /**
     * Create a user (Admin, Owner, or Worker) with optional new Tenant onboarding.
     */
    public function storeUser(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role_type' => ['required', 'string', 'in:admin,owner,worker'],
            'tenant_mode' => ['required_unless:role_type,admin', 'string', 'in:existing,new'],
            'tenant_id' => ['required_if:tenant_mode,existing', 'nullable', 'integer', 'exists:tenants,id'],
            'new_tenant_name' => ['required_if:tenant_mode,new', 'nullable', 'string', 'max:150'],
        ]);

        DB::transaction(function () use ($validated): void {
            $isSuperAdmin = $validated['role_type'] === 'admin';
            $tenantId = null;

            if ($validated['role_type'] !== 'admin') {
                if ($validated['tenant_mode'] === 'new') {
                    $slugBase = Str::slug($validated['new_tenant_name'] ?? 'taller');
                    $slug = $slugBase . '-' . Str::lower(Str::random(4));
                    $newTenant = Tenant::create([
                        'name' => $validated['new_tenant_name'],
                        'slug' => $slug,
                        'daily_productive_minutes' => 480,
                        'default_buffer_percent' => 20,
                        'settings' => ['currency' => 'COP', 'parallel_workers' => 1, 'workdays' => [1, 2, 3, 4, 5, 6]],
                    ]);
                    $tenantId = $newTenant->id;
                } else {
                    $tenantId = (int) $validated['tenant_id'];
                }
            }

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_super_admin' => $isSuperAdmin,
                'current_tenant_id' => $tenantId,
            ]);

            if ($tenantId !== null) {
                $role = $validated['role_type'] === 'owner' ? 'owner' : 'worker';
                $user->tenants()->attach($tenantId, ['role' => $role]);
            }
        });

        return redirect()->back()->with('success', 'Usuario registrado con éxito en la plataforma.');
    }

    /**
     * Switch active tenant for the SuperAdmin.
     */
    public function switchTenant(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        /** @var User $user */
        $user = auth()->user();
        $user->update(['current_tenant_id' => $tenant->id]);
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->route('kanban.index')->with(
            'success',
            "Administrando ahora el taller: {$tenant->name}"
        );
    }
}
