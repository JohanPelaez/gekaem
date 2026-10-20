<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class TenantSettingsController extends Controller
{
    /**
     * Display the Tenant settings and team management view.
     */
    public function index(Request $request): Response
    {
        return app(ProfileController::class)->edit($request);
    }

    /**
     * Update the workshop configuration and capacity parameters.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_if(auth()->user()?->currentRole() === 'worker', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'daily_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'default_buffer_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'parallel_workers' => ['nullable', 'integer', 'min:1', 'max:50'],
            'workdays' => ['nullable', 'array'],
            'workdays.*' => ['integer', 'between:1,7'],
            'currency' => ['nullable', 'string', 'max:10'],
        ]);

        $tenant = $this->resolveTenant();
        $dailyMinutes = (int) round(((float) $validated['daily_hours']) * 60);

        $tenant->update([
            'name' => $validated['name'],
            'daily_productive_minutes' => $dailyMinutes,
            'default_buffer_percent' => (int) $validated['default_buffer_percent'],
            'settings' => [
                ...($tenant->settings ?? []),
                'parallel_workers' => (int) ($validated['parallel_workers'] ?? 1),
                'workdays' => $validated['workdays'] ?? [1, 2, 3, 4, 5, 6],
                'currency' => $validated['currency'] ?? 'COP',
            ],
        ]);

        return redirect()->back()->with('success', 'Configuración del taller actualizada correctamente.');
    }

    /**
     * Add a team member (Worker or Co-Owner) to the workshop.
     */
    public function addMember(Request $request): RedirectResponse
    {
        abort_if(auth()->user()?->currentRole() === 'worker', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', 'in:worker,owner'],
        ]);

        $tenant = $this->resolveTenant();

        DB::transaction(function () use ($validated, $tenant): void {
            /** @var User|null $user */
            $user = User::where('email', $validated['email'])->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'is_super_admin' => false,
                    'current_tenant_id' => $tenant->id,
                ]);
            }

            if ($tenant->users()->where('users.id', $user->id)->exists()) {
                $tenant->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);
            } else {
                $tenant->users()->attach($user->id, ['role' => $validated['role']]);
            }
        });

        return redirect()->back()->with('success', 'Miembro del equipo registrado con éxito en el taller.');
    }

    /**
     * Remove a member from the workshop.
     */
    public function removeMember(Request $request, User $user): RedirectResponse
    {
        abort_if(auth()->user()?->currentRole() === 'worker', 403);

        if ($user->id === auth()->id()) {
            return redirect()->back()->with('error', 'No puedes eliminarte a ti mismo de tu taller.');
        }

        $tenant = $this->resolveTenant();
        $tenant->users()->detach($user->id);

        return redirect()->back()->with('success', "Usuario '{$user->name}' removido del taller.");
    }

    /**
     * Resolve active tenant.
     */
    protected function resolveTenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = auth()->user()?->currentTenant() ?? Tenant::first();

        return $tenant;
    }
}
