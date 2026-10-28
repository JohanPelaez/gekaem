<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dashboard\GetDashboardStatsAction;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the main gipPyme dashboard with KPIs and recent orders.
     */
    public function index(Request $request, GetDashboardStatsAction $action): Response
    {
        $tenant = $this->resolveTenant();
        Tenant::ensureDefaultData($tenant->id);

        return Inertia::render('Dashboard', $action->execute($tenant));
    }

    /**
     * Resolve the active tenant.
     */
    protected function resolveTenant(): Tenant
    {
        /** @var Tenant $tenant */
        return auth()->user()?->tenants()->first()
            ?? (session('current_tenant_id') ? Tenant::find(session('current_tenant_id')) : null)
            ?? Tenant::first();
    }
}
