<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductMaterialRequirement;
use App\Models\ProductVariant;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\WorkflowStage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateOrderAction
{
    public function __construct(
        protected PredictDeliveryDateAction $predictDeliveryDateAction
    ) {}

    /**
     * Create an order ("US") and generate its production tasks ("Tareas") in Kanban.
     *
     * @param  array{
     *     customer_id: int,
     *     items: list<array{product_variant_id: int, quantity: float|int}>,
     *     committed_delivery_at?: string|Carbon|null,
     *     notes?: ?string,
     *     custom_buffer_percent?: ?int
     * }  $data
     */
    public function execute(Tenant $tenant, array $data): Order
    {
        return DB::transaction(function () use ($tenant, $data): Order {
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

            // 1. Obtener predicción de entrega y tiempos
            $prediction = $this->predictDeliveryDateAction->execute(
                $tenant,
                $data['items'],
                $data['custom_buffer_percent'] ?? null
            );

            // 2. Calcular número consecutivo de orden por tenant
            $maxOrderNumber = (int) Order::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->max('order_number');
            $nextOrderNumber = $maxOrderNumber + 1;

            // 3. Calcular monto total
            $totalAmount = 0.0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $variantId = (int) $item['product_variant_id'];
                $quantity = (float) $item['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                /** @var ProductVariant $variant */
                $variant = ProductVariant::withoutGlobalScope('tenant')
                    ->with(['product.workflow.stages'])
                    ->findOrFail($variantId);

                $unitPrice = $variant->price_override !== null
                    ? (float) $variant->price_override
                    : (float) $variant->product->base_price;

                $totalAmount += $unitPrice * $quantity;
                $itemsData[] = [
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];
            }

            // 4. Crear la orden (la "US" en el tablero Kanban)
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

            /** @var Order $order */
            $order = Order::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'order_number' => $nextOrderNumber,
                'derived_status' => 'pending',
                'total_production_minutes' => $prediction['order_production_minutes'],
                'buffer_minutes' => $prediction['buffer_minutes'],
                'predicted_delivery_at' => $prediction['predicted_delivery_at'],
                'committed_delivery_at' => $committedDeliveryAt,
                'delivered_at' => null,
                'kanban_swimlane_position' => $nextOrderNumber,
                'total_amount' => round($totalAmount, 2),
                'notes' => $data['notes'] ?? null,
            ]);

            // 5. Crear las tareas de Kanban para cada producto/variante
            foreach ($itemsData as $entry) {
                /** @var ProductVariant $variant */
                $variant = $entry['variant'];
                $product = $variant->product;
                $quantity = $entry['quantity'];
                $unitPrice = $entry['unit_price'];

                // Primer estado del flujo según position ASC
                /** @var WorkflowStage|null $initialStage */
                $initialStage = $product->workflow?->stages()
                    ->orderBy('position', 'asc')
                    ->first();

                if ($initialStage === null) {
                    throw new InvalidArgumentException(
                        "El flujo '{$product->workflow?->name}' no tiene etapas definidas."
                    );
                }

                $unitMinutes = (int) $product->base_production_minutes + (int) $variant->production_minutes_delta;
                $estimatedMinutes = (int) ceil($unitMinutes * $quantity);

                // Posición de la columna en la etapa inicial
                $maxColPos = (int) ProductionTask::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('current_stage_id', $initialStage->id)
                    ->max('column_position');

                $itemNameSnapshot = "{$product->name} - {$variant->name_label}";

                $task = ProductionTask::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $tenant->id,
                    'order_id' => $order->id,
                    'product_variant_id' => $variant->id,
                    'workflow_id' => $product->workflow_id,
                    'current_stage_id' => $initialStage->id,
                    'item_name_snapshot' => mb_substr($itemNameSnapshot, 0, 150),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'estimated_minutes' => $estimatedMinutes,
                    'column_position' => $maxColPos + 1,
                    'materials_consumed' => false,
                    'started_at' => null,
                    'completed_at' => null,
                ]);

                // 6. Reservar stock en materias primas según BOM
                $requirements = ProductMaterialRequirement::where(function ($query) use ($variant): void {
                    $query->where('product_variant_id', $variant->id)
                        ->orWhere(function ($sub) use ($variant): void {
                            $sub->where('product_id', $variant->product_id)
                                ->whereNull('product_variant_id');
                        });
                })->get();

                foreach ($requirements as $req) {
                    $wasteMultiplier = 1 + (((float) $req->waste_percent) / 100);
                    $needed = ((float) $req->quantity_required) * $wasteMultiplier * $quantity;

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

            return $order->load(['tasks', 'customer']);
        });
    }
}
