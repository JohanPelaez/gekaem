<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrthogonalAttribute;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\Workflow;

class GetDashboardStatsAction
{
    /**
     * Retrieve aggregated metrics, recent orders, and catalog collections for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function execute(Tenant $tenant): array
    {
        $tenantId = $tenant->id;

        $totalOrdersCount = Order::where('tenant_id', $tenantId)->count();
        $totalRevenue = (float) Order::where('tenant_id', $tenantId)->sum('total_amount');
        $completedTasksCount = ProductionTask::where('tenant_id', $tenantId)
            ->whereHas('currentStage', fn ($q) => $q->where('stage_category', 'done'))
            ->count();
        $pendingOrdersCount = Order::where('tenant_id', $tenantId)
            ->whereIn('derived_status', ['pending', 'in_progress', 'blocked'])
            ->count();
        $activeCustomersCount = Customer::where('tenant_id', $tenantId)->count();

        $recentOrders = Order::where('tenant_id', $tenantId)
            ->with([
                'customer:id,name,phone,email,document_number,tax_id',
                'productionTasks.productVariant.product',
                'productionTasks.currentStage',
                'orderStatus',
                'payments.creator',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->whereHas('currentStage', fn ($s) => $s->where('stage_category', 'done')),
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $allScheduledOrders = Order::where('tenant_id', $tenantId)
            ->where(function ($q): void {
                $q->whereNotNull('committed_delivery_at')
                    ->orWhereNotNull('predicted_delivery_at');
            })
            ->where('derived_status', '!=', 'delivered')
            ->with([
                'customer:id,name',
                'productionTasks:id,order_id,quantity,item_name_snapshot,current_stage_id',
                'productionTasks.currentStage:id,name,color,stage_category',
            ])
            ->select('id', 'order_number', 'customer_id', 'committed_delivery_at', 'predicted_delivery_at', 'derived_status', 'shipping_type')
            ->orderByRaw('COALESCE(committed_delivery_at, predicted_delivery_at) ASC')
            ->get();

        $ordersCountByDate = Order::where('tenant_id', $tenantId)
            ->where(function ($q): void {
                $q->whereNotNull('committed_delivery_at')
                    ->orWhereNotNull('predicted_delivery_at');
            })
            ->where('derived_status', '!=', 'delivered')
            ->selectRaw('DATE(COALESCE(committed_delivery_at, predicted_delivery_at)) as date_key, COUNT(*) as aggregate_count')
            ->groupBy('date_key')
            ->pluck('aggregate_count', 'date_key')
            ->all();

        return [
            'totalOrdersCount' => $totalOrdersCount,
            'totalRevenue' => $totalRevenue,
            'completedTasksCount' => $completedTasksCount,
            'pendingOrdersCount' => $pendingOrdersCount,
            'activeCustomersCount' => $activeCustomersCount,
            'recentOrders' => $recentOrders,
            'customers' => Customer::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'phone', 'document_number', 'tax_id']),
            'products' => Product::where('tenant_id', $tenantId)->where('is_active', true)->with(['variants', 'workflow', 'materialRequirements.rawMaterial'])->get(),
            'workflows' => Workflow::where('tenant_id', $tenantId)->with('stages')->orderBy('name')->get(),
            'productTypes' => ProductType::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']),
            'rawMaterials' => RawMaterial::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'unit_of_measure', 'current_stock', 'reserved_stock']),
            'orthogonalAttributes' => OrthogonalAttribute::where('tenant_id', $tenantId)->with('options')->orderBy('name')->get(),
            'attributes' => OrthogonalAttribute::where('tenant_id', $tenantId)->with('options')->orderBy('name')->get(),
            'unitsOfMeasure' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']),
            'wipQueueMinutes' => (int) ProductionTask::where('tenant_id', $tenantId)->whereHas('currentStage', fn ($q) => $q->whereIn('stage_category', ['pending', 'in_progress']))->sum('estimated_minutes'),
            'dailyProductiveMinutes' => $tenant->daily_productive_minutes ?? 480,
            'defaultBufferPercent' => $tenant->default_buffer_percent ?? 20,
            'allScheduledOrders' => $allScheduledOrders,
            'ordersCountByDate' => $ordersCountByDate,
        ];
    }
}
