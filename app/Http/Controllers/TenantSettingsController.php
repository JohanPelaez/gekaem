<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Team\AddTeamMemberAction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
    public function addMember(Request $request, AddTeamMemberAction $action): RedirectResponse|JsonResponse
    {
        abort_if(auth()->user()?->currentRole() === 'worker', 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', 'in:worker,owner,admin'],
        ]);

        $tenant = $this->resolveTenant();
        $isCeoRole = in_array($validated['role'], ['owner', 'admin'], true);

        if ($isCeoRole && !$tenant->canAddCeo()) {
            return $this->limitReachedResponse(
                $request,
                'ceo',
                'Has alcanzado el límite de Usuarios CEO incluidos en tu plan.'
            );
        }

        if (!$isCeoRole && !$tenant->canAddWorker()) {
            return $this->limitReachedResponse(
                $request,
                'worker',
                'Has alcanzado el límite de Usuarios Operario incluidos en tu plan.'
            );
        }

        $action->execute($validated, $tenant);

        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => 'Miembro del equipo registrado con éxito en el taller.',
                'planQuotas' => $tenant->fresh()->getQuotaSummary(),
            ], 201);
        }

        return redirect()->back()->with('success', 'Miembro del equipo registrado con éxito en el taller.');
    }

    /**
     * Update an existing team member's info and role.
     */
    public function updateMember(Request $request, User $user): RedirectResponse|JsonResponse
    {
        abort_if(auth()->user()?->currentRole() === 'worker', 403);

        $tenant = $this->resolveTenant();

        $existingMember = $tenant->users()->where('users.id', $user->id)->first();
        if ($existingMember === null) {
            abort(404, 'El usuario no pertenece a este taller.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'string',
                'email',
                'max:150',
                \Illuminate\Validation\Rule::unique('users')->ignore($user->id),
            ],
            'role' => ['required', 'string', 'in:owner,admin,worker'],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        $currentPivotRole = (string) ($existingMember->pivot->role ?? 'worker');
        $targetPivotRole = in_array($validated['role'], ['owner', 'admin'], true) ? 'owner' : 'worker';
        $wasCeo = in_array($currentPivotRole, ['owner', 'admin'], true);
        $willBeCeo = $targetPivotRole === 'owner';

        if (!$user->isSuperAdmin() && $wasCeo !== $willBeCeo) {
            if ($willBeCeo && !$tenant->canAddCeo()) {
                return $this->limitReachedResponse(
                    $request,
                    'ceo',
                    'Has alcanzado el límite de Usuarios CEO incluidos en tu plan.'
                );
            }
            if (!$willBeCeo && !$tenant->canAddWorker()) {
                return $this->limitReachedResponse(
                    $request,
                    'worker',
                    'Has alcanzado el límite de Usuarios Operario incluidos en tu plan.'
                );
            }
        }

        DB::transaction(function () use ($validated, $user, $tenant, $targetPivotRole): void {
            $updateData = [
                'name' => \Illuminate\Support\Str::title(trim($validated['name'])),
                'email' => strtolower(trim($validated['email'])),
            ];

            if (!empty($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
            }

            $user->update($updateData);
            $tenant->users()->updateExistingPivot($user->id, ['role' => $targetPivotRole]);
        });

        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => "Información del miembro '{$user->name}' actualizada con éxito.",
                'planQuotas' => $tenant->fresh()->getQuotaSummary(),
            ]);
        }

        return redirect()->back()->with('success', "Información del miembro '{$user->name}' actualizada con éxito.");
    }

    /**
     * Return a consistent quota limit response for JSON or Inertia requests.
     */
    private function limitReachedResponse(Request $request, string $addonType, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'limit_reached' => true,
                'addon_type' => $addonType,
                'message' => $message,
            ], 402);
        }

        return redirect()->back()
            ->with('error', $message)
            ->with('limit_reached_addon', $addonType);
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

        return redirect()->back()->with(
            'success',
            "Usuario '{$user->name}' removido del taller. El cupo pagado permanece cubierto durante este ciclo."
        );
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
