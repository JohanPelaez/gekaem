<?php

declare(strict_types=1);

namespace App\Actions\Kanban;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\DB;

class SyncOrderDerivedStatusAction
{
    /**
     * Unidirectional rule: when a production task moves into an active/in-progress stage
     * and its parent order is still in its initial waiting state, promote the order to
     * the tenant's "En Producción" status. Never modifies any tasks or regresses orders.
     */
    public function execute(Order $order, ?WorkflowStage $targetStage = null): Order
    {
        if ($targetStage === null) {
            return $order;
        }

        return DB::transaction(function () use ($order, $targetStage): Order {
            // 1. Verificar que la nueva etapa sea activa / en progreso:
            //    - NO es la primera etapa inicial del flujo donde esperan tareas nuevas.
            //    - NO es una etapa bloqueada/detenida.
            $firstStageId = WorkflowStage::where('workflow_id', $targetStage->workflow_id)
                ->orderBy('position', 'asc')
                ->orderBy('id', 'asc')
                ->value('id');

            if ($firstStageId !== null && (int) $targetStage->id === (int) $firstStageId) {
                return $order;
            }

            $normalizedStageName = mb_strtolower(trim((string) $targetStage->name), 'UTF-8');
            if (
                $targetStage->stage_category === 'blocked'
                || str_contains($normalizedStageName, 'bloquead')
                || str_contains($normalizedStageName, 'detenid')
            ) {
                return $order;
            }

            // 2. Verificar que el pedido padre se encuentre en su estado inicial de espera
            $order->loadMissing('orderStatus');
            $currentStatus = $order->orderStatus;

            if (
                ($currentStatus && $currentStatus->is_history_status)
                || $order->shipped_at !== null
                || $order->derived_status === 'delivered'
            ) {
                return $order;
            }

            $activeStatuses = OrderStatus::withoutGlobalScope('tenant')
                ->where('tenant_id', $order->tenant_id)
                ->where('is_history_status', false)
                ->orderBy('position', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $firstStatus = $activeStatuses->first();
            $isOrderInInitialWaitingState = false;

            if ($currentStatus !== null) {
                $normalizedOrderStatusName = mb_strtolower(trim((string) $currentStatus->name), 'UTF-8');
                $isOrderInInitialWaitingState = ($firstStatus !== null && (int) $currentStatus->id === (int) $firstStatus->id)
                    || $currentStatus->system_key === 'pending'
                    || in_array($normalizedOrderStatusName, ['pendiente', 'por iniciar'], true);
            } else {
                $isOrderInInitialWaitingState = $order->derived_status === 'pending';
            }

            if (!$isOrderInInitialWaitingState) {
                return $order;
            }

            // 3. Buscar el estado de producción del taller (mismo tenant_id)
            $productionStatus = $activeStatuses->first(function (OrderStatus $st): bool {
                $name = mb_strtolower(trim((string) $st->name), 'UTF-8');
                if (str_contains($name, 'bloquead') || str_contains($name, 'detenid')) {
                    return false;
                }

                return str_contains($name, 'producción')
                    || str_contains($name, 'produccion')
                    || str_contains($name, 'proceso');
            });

            if ($productionStatus === null) {
                $productionStatus = $activeStatuses->first(
                    fn (OrderStatus $st): bool => $st->system_key === 'in_progress'
                );
            }

            if ($productionStatus === null) {
                $productionStatus = $activeStatuses
                    ->filter(function (OrderStatus $st) use ($firstStatus): bool {
                        if ($firstStatus !== null && (int) $st->id === (int) $firstStatus->id) {
                            return false;
                        }

                        $name = mb_strtolower(trim((string) $st->name), 'UTF-8');

                        return $st->system_key !== 'blocked'
                            && !str_contains($name, 'bloquead')
                            && !str_contains($name, 'detenid');
                    })
                    ->first();
            }

            if ($productionStatus !== null) {
                $order->order_status_id = $productionStatus->id;
            }

            $order->derived_status = 'in_progress';
            $order->save();

            return $order;
        });
    }
}
