<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarkOrderDeliveredAction
{
    /**
     * Mark an order as delivered and move it to the tenant's history status.
     */
    public function execute(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $historyStatus = Order::resolveHistoryStatusForTenant((int) $order->tenant_id);
            $now = Carbon::now();

            $order->order_status_id = $historyStatus->id;
            $order->derived_status = 'delivered';
            $order->delivered_at = $order->delivered_at ?? $now;
            $order->shipped_at = $now;
            $order->save();

            return $order->fresh(['orderStatus', 'customer']);
        });
    }
}
