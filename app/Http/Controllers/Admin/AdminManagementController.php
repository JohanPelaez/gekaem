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
        ])->withCount(['orders'])->get()->map(function (Tenant $tenant): array {
            $cutDate = $tenant->nextCutDate();
            $daysRemaining = $tenant->daysUntilNextCut();

            $data = $tenant->toArray();
            $data['next_cut_date'] = $cutDate?->format('d/m/Y');
            $data['next_cut_date_label'] = $cutDate ? ($cutDate->format('d') . ' ' . $cutDate->translatedFormat('M Y')) : null;
            $data['days_until_cut'] = $daysRemaining;
            $data['is_cut_expired'] = $daysRemaining < 0;
            $data['is_cut_today'] = $daysRemaining === 0;

            return $data;
        });

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
                    \App\Models\OrderStatus::seedDefaultStatusesForTenant($newTenant->id);
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

    /**
     * Extend free trial days for a specific tenant.
     */
    public function extendTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) $validated['days'];

        DB::transaction(function () use ($tenant, $days): void {
            $currentTrialEnds = $tenant->trial_ends_at ?? $tenant->created_at?->copy()->addDays(15) ?? now();

            if (now()->greaterThan($currentTrialEnds)) {
                $newTrialEnds = now()->addDays($days);
            } else {
                $newTrialEnds = $currentTrialEnds->copy()->addDays($days);
            }

            $updateData = [
                'trial_ends_at' => $newTrialEnds,
            ];

            // Si el taller estaba bloqueado o vencido sin pago, reactivar en trialing para restaurar acceso
            if (
                in_array($tenant->subscription_status, ['past_due', 'expired', 'canceled', 'unpaid'], true) ||
                $tenant->isTrialExpired()
            ) {
                if ($tenant->subscription_status !== 'active') {
                    $updateData['subscription_status'] = 'trialing';
                }
            }

            $tenant->update($updateData);
        });

        $formattedDate = $tenant->fresh()->trial_ends_at?->format('d/m/Y');

        return redirect()->back()->with(
            'success',
            "¡Prueba extendida con éxito! Se otorgaron {$days} días adicionales a '{$tenant->name}'. Nuevo vencimiento: {$formattedDate}."
        );
    }
}
