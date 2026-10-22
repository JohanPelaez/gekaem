<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Kanban\SyncOrderDerivedStatusAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductMaterialRequirement;
use App\Models\ProductVariant;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\Tenant;
use App\Models\WorkflowStage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateOrderAction
{
    public function __construct(
        protected PredictDeliveryDateAction $predictDeliveryDateAction,
        protected SyncOrderDerivedStatusAction $syncOrderDerivedStatusAction
    ) {}

    /**
     * Update an order and intelligently synchronize its production tasks in Kanban.
     *
     * @param array{
     *     customer_id: int,
     *     committed_delivery_at: string|Carbon,
     *     shipping_type?: string,
     *     notes?: ?string,
     *     items: list<array{product_variant_id: int, quantity: float|int, unit_price?: float|int|null}>
     * } $data
     */
    public function execute(Tenant $tenant, Order $order, array $data): Order
    {
        return DB::transaction(function () use ($tenant, $order, $data): Order {
            if (empty($data['customer_id'])) {
                throw new InvalidArgumentException('El campo customer_id es obligatorio.');
            }

            if (empty($data['items']) || !is_array($data['items'])) {
                throw new InvalidArgumentException('El pedido debe incluir al menos un producto.');
            }

            // Validar que el cliente pertenezca al Tenant
            /** @var Customer $customer */
            $customer = Customer::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->findOrFail((int) $data['customer_id']);

            // 1. Obtener predicción de entrega y tiempos recalculados
            $prediction = $this->predictDeliveryDateAction->execute(
                $tenant,
                $data['items'],
                null
            );

            // 2. Procesar y mapear ítems enviados
            $totalAmount = 0.0;
            $submittedVariantIds = [];
            $itemsMap = [];

            foreach ($data['items'] as $item) {
                $variantId = (int) $item['product_variant_id'];
                $quantity = (float) $item['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $submittedVariantIds[] = $variantId;

                /** @var ProductVariant $variant */
                $variant = ProductVariant::withoutGlobalScope('tenant')
                    ->with(['product.workflow.stages'])
                    ->findOrFail($variantId);

                $unitPrice = isset($item['unit_price']) && is_numeric($item['unit_price'])
                    ? (float) $item['unit_price']
                    : ($variant->price_override !== null ? (float) $variant->price_override : (float) $variant->product->base_price);

                $totalAmount += $unitPrice * $quantity;

                $itemsMap[$variantId] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];
            }

            // 3. Tareas existentes actualmente en este pedido
            $existingTasks = ProductionTask::withoutGlobalScope('tenant')
                ->where('order_id', $order->id)
                ->get();

            // 4. Sincronización inteligente de tareas
            // A) Actualizar existentes o crear nuevas
            foreach ($itemsMap as $variantId => $entry) {
                /** @var ProductVariant $variant */
                $variant = $entry['variant'];
                $product = $variant->product;
                $newQuantity = (float) $entry['quantity'];
                $unitPrice = (float) $entry['unit_price'];

                $unitMinutes = (int) $product->base_production_minutes + (int) $variant->production_minutes_delta;
                $estimatedMinutes = (int) ceil($unitMinutes * $newQuantity);

                /** @var ProductionTask|null $existingTask */
                $existingTask = $existingTasks->firstWhere('product_variant_id', $variantId);

                if ($existingTask !== null) {
                    $oldQuantity = (float) $existingTask->quantity;
                    $qtyDelta = $newQuantity - $oldQuantity;

                    // Mantener intacto current_stage_id para preservar avance en Kanban
                    $existingTask->quantity = $newQuantity;
                    $existingTask->unit_price = $unitPrice;
                    $existingTask->estimated_minutes = $estimatedMinutes;
                    $existingTask->save();

                    // Ajustar reserva de materias primas si los materiales aún no fueron consumidos
                    if (!$existingTask->materials_consumed && abs($qtyDelta) > 0.0001) {
                        $requirements = ProductMaterialRequirement::where(function ($query) use ($variant): void {
                            $query->where('product_variant_id', $variant->id)
                                ->orWhere(function ($sub) use ($variant): void {
                                    $sub->where('product_id', $variant->product_id)
                                        ->whereNull('product_variant_id');
                                });
                        })->get();

                        foreach ($requirements as $req) {
                            $wasteMultiplier = 1 + (((float) $req->waste_percent) / 100);
                            $deltaNeeded = ((float) $req->quantity_required) * $wasteMultiplier * $qtyDelta;

                            /** @var RawMaterial|null $rawMaterial */
                            $rawMaterial = RawMaterial::withoutGlobalScope('tenant')
                                ->where('tenant_id', $tenant->id)
                                ->lockForUpdate()
                                ->find($req->raw_material_id);

                            if ($rawMaterial !== null) {
                                $rawMaterial->reserved_stock = max(0.0, ((float) $rawMaterial->reserved_stock) + $deltaNeeded);
                                $rawMaterial->save();
                            }
                        }
                    }
                } else {
                    // Ítem nuevo que no estaba en el pedido: crear ProductionTask en la etapa inicial del flujo
                    /** @var WorkflowStage|null $initialStage */
                    $initialStage = $product->workflow?->stages()
                        ->orderBy('position', 'asc')
                        ->first();

                    if ($initialStage === null) {
                        throw new InvalidArgumentException(
                            "El flujo '{$product->workflow?->name}' no tiene etapas definidas."
                        );
                    }

                    $maxColPos = (int) ProductionTask::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenant->id)
                        ->where('current_stage_id', $initialStage->id)
                        ->max('column_position');

                    $itemNameSnapshot = "{$product->name} - {$variant->name_label}";

                    $newTask = ProductionTask::withoutGlobalScope('tenant')->create([
                        'tenant_id' => $tenant->id,
                        'order_id' => $order->id,
                        'product_variant_id' => $variant->id,
                        'workflow_id' => $product->workflow_id,
                        'current_stage_id' => $initialStage->id,
                        'item_name_snapshot' => mb_substr($itemNameSnapshot, 0, 150),
                        'quantity' => $newQuantity,
                        'unit_price' => $unitPrice,
                        'estimated_minutes' => $estimatedMinutes,
                        'column_position' => $maxColPos + 1,
                        'materials_consumed' => false,
                        'started_at' => null,
                        'completed_at' => null,
                    ]);

                    // Reservar stock de materias primas para la nueva tarea
                    $requirements = ProductMaterialRequirement::where(function ($query) use ($variant): void {
                        $query->where('product_variant_id', $variant->id)
                            ->orWhere(function ($sub) use ($variant): void {
                                $sub->where('product_id', $variant->product_id)
                                    ->whereNull('product_variant_id');
                            });
                    })->get();

                    foreach ($requirements as $req) {
                        $wasteMultiplier = 1 + (((float) $req->waste_percent) / 100);
                        $needed = ((float) $req->quantity_required) * $wasteMultiplier * $newQuantity;

                        /** @var RawMaterial|null $rawMaterial */
                        $rawMaterial = RawMaterial::withoutGlobalScope('tenant')
                            ->where('tenant_id', $tenant->id)
                            ->lockForUpdate()
                            ->find($req->raw_material_id);

                        if ($rawMaterial !== null) {
                            $rawMaterial->reserved_stock = ((float) $rawMaterial->reserved_stock) + $needed;
                            $rawMaterial->save();
                        }
                    }
                }
            }

            // B) Eliminar tareas de variantes que fueron retiradas del pedido
            $tasksToRemove = $existingTasks->reject(
                fn (ProductionTask $t) => in_array($t->product_variant_id, $submittedVariantIds, true)
            );

            foreach ($tasksToRemove as $task) {
                /** @var ProductVariant|null $variant */
                $variant = ProductVariant::withoutGlobalScope('tenant')->find($task->product_variant_id);

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
                        $qty = ((float) $req->quantity_required) * $wasteMultiplier * ((float) $task->quantity);

                        /** @var RawMaterial|null $rawMaterial */
                        $rawMaterial = RawMaterial::withoutGlobalScope('tenant')
                            ->where('tenant_id', $tenant->id)
                            ->lockForUpdate()
                            ->find($req->raw_material_id);

                        if ($rawMaterial !== null) {
                            if ($task->materials_consumed) {
                                // Devolver stock físico y asentar movimiento kardex de devolución
                                $rawMaterial->current_stock = ((float) $rawMaterial->current_stock) + $qty;
                                $rawMaterial->save();

                                RawMaterialMovement::withoutGlobalScope('tenant')->create([
                                    'tenant_id' => $tenant->id,
                                    'raw_material_id' => $rawMaterial->id,
                                    'production_task_id' => null,
                                    'type' => 'return',
                                    'quantity' => round($qty, 3),
                                    'stock_after' => round((float) $rawMaterial->current_stock, 3),
                                    'notes' => "Devolución por retiro de producto al editar pedido #{$order->order_number}",
                                ]);
                            } else {
                                // Liberar reserva de stock
                                $rawMaterial->reserved_stock = max(0.0, ((float) $rawMaterial->reserved_stock) - $qty);
                                $rawMaterial->save();
                            }
                        }
                    }
                }

                $task->delete();
            }

            // 5. Parsear fecha comprometida de entrega
            $committedDeliveryAt = null;
            if (!empty($data['committed_delivery_at'])) {
                $rawVal = trim((string) $data['committed_delivery_at']);
                if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $rawVal, $m)) {
                    $datePart = $m[1];
                    $timePart = '17:00:00';
                    if (preg_match('/[T ](\d{2}:\d{2}(?::\d{2})?)/', $rawVal, $tm)) {
                        $timePart = strlen($tm[1]) === 5 ? $tm[1] . ':00' : $tm[1];
                    }
                    $committedDeliveryAt = "{$datePart} {$timePart}";
                } else {
                    $committedDeliveryAt = Carbon::parse($rawVal)->format('Y-m-d H:i:s');
                }
            }

            // 6. Actualizar campos principales de la orden
            $order->customer_id = $customer->id;
            $order->committed_delivery_at = $committedDeliveryAt;
            $order->shipping_type = $data['shipping_type'] ?? ($order->shipping_type ?? 'contraentrega');
            $order->notes = $data['notes'] ?? null;
            $order->total_amount = round($totalAmount, 2);
            $order->total_production_minutes = $prediction['order_production_minutes'];
            $order->buffer_minutes = $prediction['buffer_minutes'];
            $order->predicted_delivery_at = $prediction['predicted_delivery_at'];
            $order->save();

            // 7. Recalcular el derived_status del pedido según las tareas vigentes
            $this->syncOrderDerivedStatusAction->execute($order);

            return $order->fresh(['tasks.currentStage', 'customer']);
        });
    }
}
