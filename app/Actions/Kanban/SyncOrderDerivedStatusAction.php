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

            $needsSave = false;
            if ($order->derived_status !== $newStatus) {
                $order->derived_status = $newStatus;
                $needsSave = true;
            }

            // Si el pedido no está en un estado personalizado del usuario, sincronizar su order_status_id
            $currentStatus = $order->orderStatus;
            $isCustom = $currentStatus && $currentStatus->system_key === 'custom';
            $targetKey = ($newStatus === 'delivered') ? 'ready' : $newStatus;

            if (!$isCustom && in_array($targetKey, ['pending', 'in_progress', 'blocked', 'ready'], true)) {
                $matchingStatus = \App\Models\OrderStatus::withoutGlobalScope('tenant')
                    ->where('tenant_id', $order->tenant_id)
                    ->where('system_key', $targetKey)
                    ->first();

                if ($matchingStatus && $order->order_status_id !== $matchingStatus->id) {
                    $order->order_status_id = $matchingStatus->id;
                    $needsSave = true;
                }
            }

            if ($needsSave) {
                $order->save();
            }

            return $order;
        });
    }
}
