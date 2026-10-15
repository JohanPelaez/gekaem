<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\RawMaterial;
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
        // En entorno local, autologuear con el primer usuario si no hay sesión activa
        if (!auth()->check() && app()->environment('local')) {
            $firstUser = User::first();
            if ($firstUser !== null) {
                auth()->login($firstUser);
            }
        }

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
                ] : null,
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'daily_productive_minutes' => (int) $tenant->daily_productive_minutes,
                    'default_buffer_percent' => (int) $tenant->default_buffer_percent,
                ] : null,
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
