<?php

declare(strict_types=1);

namespace App\Actions\Kanban;

use App\Models\Order;
use App\Models\ProductMaterialRequirement;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\WorkflowStage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MoveProductionTaskAction
{
    public function __construct(
        protected SyncOrderDerivedStatusAction $syncOrderDerivedStatusAction
    ) {}

    /**
     * Move a production task to a new Kanban stage, consume materials if entering in_progress, and sync order status.
     */
    public function execute(ProductionTask $task, int $targetStageId, int $columnPosition = 0): ProductionTask
    {
        return DB::transaction(function () use ($task, $targetStageId, $columnPosition): ProductionTask {
            /** @var WorkflowStage $targetStage */
            $targetStage = WorkflowStage::findOrFail($targetStageId);

            $task->current_stage_id = $targetStage->id;
            $task->column_position = $columnPosition;

            // Registrar inicio si entra por primera vez a producción o se finaliza
            if (in_array($targetStage->stage_category, ['in_progress', 'done'], true) && $task->started_at === null) {
                $task->started_at = Carbon::now();
            }

            // Registrar finalización si llega a done
            if ($targetStage->stage_category === 'done') {
                $task->completed_at = Carbon::now();
            }

            // Descontar materia prima si entra a in_progress o done y aún no se ha consumido
            if (in_array($targetStage->stage_category, ['in_progress', 'done'], true) && !$task->materials_consumed) {
                $variant = $task->productVariant()->with('product')->first();

                if ($variant !== null) {
                    $requirements = ProductMaterialRequirement::where(function ($query) use ($variant): void {
                        $query->where('product_variant_id', $variant->id)
                            ->orWhere(function ($sub) use ($variant): void {
                                $sub->where('product_id', $variant->product_id)
                                    ->whereNull('product_variant_id');
                            });
                    })->get();

                    foreach ($requirements as $req) {
                        $wasteMultiplier = 1 + (((float) $req->waste_percent) / 100);
                        $consumedQty = ((float) $req->quantity_required) * $wasteMultiplier * ((float) $task->quantity);

                        /** @var RawMaterial|null $rawMaterial */
                        $rawMaterial = RawMaterial::withoutGlobalScope('tenant')
                            ->where('tenant_id', $task->tenant_id)
                            ->lockForUpdate()
                            ->find($req->raw_material_id);

                        if ($rawMaterial !== null) {
                            $rawMaterial->current_stock = max(0.0, ((float) $rawMaterial->current_stock) - $consumedQty);
                            $rawMaterial->reserved_stock = max(0.0, ((float) $rawMaterial->reserved_stock) - $consumedQty);
                            $rawMaterial->save();

                            RawMaterialMovement::withoutGlobalScope('tenant')->create([
                                'tenant_id' => $task->tenant_id,
                                'raw_material_id' => $rawMaterial->id,
                                'production_task_id' => $task->id,
                                'type' => 'consumption',
                                'quantity' => round($consumedQty, 3),
                                'stock_after' => round((float) $rawMaterial->current_stock, 3),
                                'notes' => "Consumo por inicio de tarea #{$task->id}: {$task->item_name_snapshot}",
                            ]);
                        }
                    }

                    $task->materials_consumed = true;
                }
            }

            $task->save();

            // Sincronizar el estado derivado del pedido padre ("US")
            /** @var Order $order */
            $order = Order::withoutGlobalScope('tenant')->findOrFail($task->order_id);
            $this->syncOrderDerivedStatusAction->execute($order);

            return $task->fresh(['order', 'currentStage']);
        });
    }
}
