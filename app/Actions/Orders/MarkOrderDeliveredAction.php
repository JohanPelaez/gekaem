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
     * Mark an order as delivered if it is ready.
     */
    public function execute(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            if ($order->derived_status !== 'ready') {
                throw new InvalidArgumentException(
                    'Solo se pueden marcar como entregados los pedidos que se encuentran en estado Listo (ready).'
                );
            }

            $order->derived_status = 'delivered';
            $order->delivered_at = Carbon::now();
            $order->save();

            return $order;
        });
    }
}
