<?php

declare(strict_types=1);

namespace App\Actions\Kanban;

use App\Models\Order;
use App\Models\ProductionTask;
use Illuminate\Support\Facades\DB;

class SyncOrderDerivedStatusAction
{
    /**
     * Synchronize the derived status of an Order based on all its Kanban Production Tasks.
     */
    public function execute(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            // Obtener todas las tareas del pedido con su etapa actual
            $tasks = ProductionTask::withoutGlobalScope('tenant')
                ->where('order_id', $order->id)
                ->with('currentStage')
                ->get();

            if ($tasks->isEmpty()) {
                return $order;
            }

            $categories = $tasks->map(fn (ProductionTask $task): string => (string) ($task->currentStage?->stage_category ?? 'pending'));

            $hasBlocked = $categories->contains('blocked');
            $allDone = $categories->every(fn (string $cat): bool => $cat === 'done');
            $allPending = $categories->every(fn (string $cat): bool => $cat === 'pending');

            if ($hasBlocked) {
                $newStatus = 'blocked';
            } elseif ($allDone) {
                $newStatus = ($order->derived_status === 'delivered') ? 'delivered' : 'ready';
            } elseif ($allPending) {
                $newStatus = 'pending';
            } else {
                $newStatus = 'in_progress';
            }

            if ($order->derived_status !== $newStatus) {
                $order->derived_status = $newStatus;
                $order->save();
            }

            return $order;
        });
    }
}
