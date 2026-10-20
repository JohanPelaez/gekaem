<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // En entorno local, autologuear solo si no está en rutas de autenticación
        if (!auth()->check() && app()->environment('local')) {
            if (!$request->is('login') && !$request->is('logout') && !$request->is('demo-login')) {
                $firstUser = User::first();
                if ($firstUser !== null) {
                    auth()->login($firstUser);
                }
            }
        }

        /** @var User|null $user */
        $user = auth()->user();
        $tenant = $user?->currentTenant();

        if ($tenant !== null && $request->hasSession()) {
            if ($request->session()->get('tenant_id') !== $tenant->id) {
                $request->session()->put('tenant_id', $tenant->id);
            }
        }

        $activeOrdersCount = 0;
        $materialAlertsCount = 0;

        if ($tenant !== null) {
            $activeOrdersCount = Order::where('derived_status', '!=', 'delivered')->count();
            $materialAlertsCount = RawMaterial::whereColumn('current_stock', '<=', 'min_stock_alert')->count();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'role' => $user->currentRole(),
                    'current_tenant_id' => $user->current_tenant_id,
                ] : null,
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'daily_productive_minutes' => (int) $tenant->daily_productive_minutes,
                    'daily_hours' => round(((int) $tenant->daily_productive_minutes) / 60, 1),
                    'default_buffer_percent' => (int) $tenant->default_buffer_percent,
                    'settings' => $tenant->settings ?? [],
                ] : null,
                'all_tenants' => ($user !== null && $user->isSuperAdmin())
                    ? Tenant::orderBy('name')->get(['id', 'name', 'slug'])
                    : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'counts' => [
                'active_orders' => $activeOrdersCount,
                'raw_material_alerts' => $materialAlertsCount,
            ],
        ];
    }
}
