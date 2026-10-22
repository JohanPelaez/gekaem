<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GetOrdersListAction
{
    /**
     * Execute the retrieval of filtered orders, calendar collection and date aggregations.
     *
     * @return array<string, mixed>
     */
    public function execute(Request $request): array
    {
        // Autorreparar pedidos que tengan committed_delivery_at nulo pero sí tengan predicted_delivery_at
        Order::whereNull('committed_delivery_at')
            ->whereNotNull('predicted_delivery_at')
            ->update(['committed_delivery_at' => DB::raw('predicted_delivery_at')]);

        $status = $request->string('status')->trim()->value();
        $search = $request->string('search')->trim()->value();
        $customerId = $request->input('customer_id');
        $paymentStatus = $request->string('payment_status')->trim()->value();
        $deliveryRange = $request->string('delivery_range')->trim()->value();

        $exactDate = $request->string('exact_date')->trim()->value();

        $query = Order::query()
            ->when(!empty($status), fn ($q) => $q->where('derived_status', $status))
            ->when(!empty($customerId), fn ($q) => $q->where('customer_id', (int) $customerId))
            ->when(!empty($exactDate), fn ($q) => $q->whereDate('committed_delivery_at', $exactDate))
            ->when(!empty($search), function ($q) use ($search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(!empty($paymentStatus), function ($q) use ($paymentStatus): void {
                $subQuery = '(SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_payments.order_id = orders.id)';
                if ($paymentStatus === 'unpaid') {
                    $q->whereRaw("{$subQuery} <= 0");
                } elseif ($paymentStatus === 'paid') {
                    $q->whereRaw("{$subQuery} >= orders.total_amount");
                } elseif ($paymentStatus === 'partial') {
                    $q->whereRaw("{$subQuery} > 0 AND {$subQuery} < orders.total_amount");
                }
            })
            ->when(!empty($deliveryRange), function ($q) use ($deliveryRange): void {
                $now = Carbon::now();
                if ($deliveryRange === 'delayed') {
                    $q->where(function ($sub) use ($now): void {
                        $sub->whereNotNull('committed_delivery_at')->where('committed_delivery_at', '<', $now);
                    })->where('derived_status', '!=', 'delivered');
                } elseif ($deliveryRange === 'today') {
                    $q->whereDate('committed_delivery_at', $now->toDateString());
                } elseif ($deliveryRange === 'this_week') {
                    $q->whereBetween('committed_delivery_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
                } elseif ($deliveryRange === 'this_month') {
                    $q->whereBetween('committed_delivery_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
                }
            });

        $orders = (clone $query)
            ->with(['customer', 'productionTasks.productVariant.product', 'productionTasks.currentStage', 'payments.creator'])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->whereHas('currentStage', fn ($s) => $s->where('stage_category', 'done')),
            ])
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $calendarOrders = (clone $query)
            ->with(['customer:id,name,phone', 'productionTasks.currentStage:id,name,color,stage_category', 'payments'])
            ->orderBy('committed_delivery_at')
            ->get();

        $allScheduledOrders = Order::query()
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

        $ordersCountByDate = Order::query()
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
            'orders' => $orders,
            'calendarOrders' => $calendarOrders,
            'allScheduledOrders' => $allScheduledOrders,
            'ordersCountByDate' => $ordersCountByDate,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'customer_id' => $customerId ? (int) $customerId : '',
                'payment_status' => $paymentStatus,
                'delivery_range' => $deliveryRange,
                'exact_date' => $exactDate,
            ],
        ];
    }
}
