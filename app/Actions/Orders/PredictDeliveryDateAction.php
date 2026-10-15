<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\ProductMaterialRequirement;
use App\Models\ProductVariant;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\RawMaterialSupplier;
use App\Models\Tenant;
use App\Support\ProductionTimeConverter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PredictDeliveryDateAction
{
    /**
     * Predict delivery date and calculate time metrics + material shortages.
     *
     * @param  list<array{product_variant_id: int, quantity: float|int}>  $items
     * @return array{
     *     order_production_minutes: int,
     *     wip_queue_minutes: int,
     *     buffer_minutes: int,
     *     total_minutes_with_buffer: int,
     *     formatted_duration: string,
     *     predicted_delivery_at: Carbon,
     *     material_shortages: list<array{
     *         raw_material_id: int,
     *         name: string,
     *         required: float,
     *         available: float,
     *         shortage: float,
     *         unit: string,
     *         lead_time_days: ?int
     *     }>
     * }
     */
    public function execute(Tenant $tenant, array $items, ?int $customBufferPercent = null): array
    {
        return DB::transaction(function () use ($tenant, $items, $customBufferPercent): array {
            $dailyMinutes = $tenant->daily_productive_minutes > 0 ? (int) $tenant->daily_productive_minutes : 480;

            // 1. Minutos en Cola WIP: Tareas activas del taller (pending o in_progress)
            $wipMinutes = (int) ProductionTask::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereHas('currentStage', function ($query): void {
                    $query->whereIn('stage_category', ['pending', 'in_progress']);
                })
                ->sum('estimated_minutes');

            // 2. Minutos de Producción del Nuevo Pedido y requerimientos de materia prima
            $orderProductionMinutes = 0;
            $materialRequirementsAggregate = [];
            $productBufferPercentages = [];

            foreach ($items as $item) {
                $variantId = (int) $item['product_variant_id'];
                $quantity = (float) $item['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                /** @var ProductVariant $variant */
                $variant = ProductVariant::withoutGlobalScope('tenant')
                    ->with('product')
                    ->findOrFail($variantId);

                $product = $variant->product;
                $productBufferPercentages[] = (int) $product->buffer_percentage;

                $unitMinutes = (int) $product->base_production_minutes + (int) $variant->production_minutes_delta;
                $orderProductionMinutes += (int) ceil($unitMinutes * $quantity);

                // Obtener recetas BOM (específicas de variante o genéricas del producto)
                $requirements = ProductMaterialRequirement::where(function ($query) use ($variant): void {
                    $query->where('product_variant_id', $variant->id)
                        ->orWhere(function ($sub) use ($variant): void {
                            $sub->where('product_id', $variant->product_id)
                                ->whereNull('product_variant_id');
                        });
                })->get();

                foreach ($requirements as $req) {
                    $rawMaterialId = (int) $req->raw_material_id;
                    $wasteMultiplier = 1 + (((float) $req->waste_percent) / 100);
                    $neededForThisItem = ((float) $req->quantity_required) * $wasteMultiplier * $quantity;

                    if (!isset($materialRequirementsAggregate[$rawMaterialId])) {
                        $materialRequirementsAggregate[$rawMaterialId] = 0.0;
                    }
                    $materialRequirementsAggregate[$rawMaterialId] += $neededForThisItem;
                }
            }

            // 3. Porcentaje de holgura
            if ($customBufferPercent !== null) {
                $bufferPercent = $customBufferPercent;
            } elseif (!empty($productBufferPercentages)) {
                $bufferPercent = (int) round(array_sum($productBufferPercentages) / count($productBufferPercentages));
            } else {
                $bufferPercent = (int) ($tenant->default_buffer_percent ?? 20);
            }

            // 4. Fórmula con holgura: Minutos Totales = (Cola WIP + Minutos Pedido) * (1 + Holgura)
            $baseTotalMinutes = $wipMinutes + $orderProductionMinutes;
            $totalMinutesWithBuffer = (int) ceil($baseTotalMinutes * (1 + ($bufferPercent / 100)));
            $bufferMinutes = max(0, $totalMinutesWithBuffer - $baseTotalMinutes);

            // 5. Cálculo de fecha estimada considerando jornadas laborales del taller (sin fines de semana)
            $predictedDeliveryAt = Carbon::now();
            $remainingMinutes = $totalMinutesWithBuffer;

            if ($remainingMinutes > 0) {
                while ($predictedDeliveryAt->isWeekend()) {
                    $predictedDeliveryAt->addDay()->setTime(8, 0);
                }

                while ($remainingMinutes > 0) {
                    if ($predictedDeliveryAt->isWeekend()) {
                        $predictedDeliveryAt->addDay()->setTime(8, 0);
                        continue;
                    }

                    if ($remainingMinutes <= $dailyMinutes) {
                        $hours = intdiv($remainingMinutes, 60);
                        $mins = $remainingMinutes % 60;
                        $predictedDeliveryAt->setTime(8, 0)->addHours($hours)->addMinutes($mins);
                        $remainingMinutes = 0;
                    } else {
                        $remainingMinutes -= $dailyMinutes;
                        $predictedDeliveryAt->addDay()->setTime(8, 0);
                    }
                }
            }

            // 6. Detección de escasez de materias primas
            $materialShortages = [];
            foreach ($materialRequirementsAggregate as $rawMaterialId => $neededQuantity) {
                /** @var RawMaterial|null $rawMaterial */
                $rawMaterial = RawMaterial::withoutGlobalScope('tenant')->find($rawMaterialId);
                if ($rawMaterial === null) {
                    continue;
                }

                $availableStock = (float) $rawMaterial->current_stock - (float) $rawMaterial->reserved_stock;
                if ($neededQuantity > $availableStock) {
                    $shortage = $neededQuantity - max(0, $availableStock);

                    $preferredSupplierLink = RawMaterialSupplier::with('supplier')
                        ->where('raw_material_id', $rawMaterialId)
                        ->orderByDesc('is_preferred')
                        ->first();

                    $leadTimeDays = $preferredSupplierLink?->supplier?->lead_time_days !== null
                        ? (int) $preferredSupplierLink->supplier->lead_time_days
                        : null;

                    $materialShortages[] = [
                        'raw_material_id' => $rawMaterial->id,
                        'name' => $rawMaterial->name,
                        'required' => round($neededQuantity, 3),
                        'available' => round(max(0, $availableStock), 3),
                        'shortage' => round($shortage, 3),
                        'unit' => $rawMaterial->unit_of_measure,
                        'lead_time_days' => $leadTimeDays,
                    ];
                }
            }

            return [
                'order_production_minutes' => $orderProductionMinutes,
                'wip_queue_minutes' => $wipMinutes,
                'buffer_minutes' => $bufferMinutes,
                'total_minutes_with_buffer' => $totalMinutesWithBuffer,
                'formatted_duration' => ProductionTimeConverter::formatHuman($totalMinutesWithBuffer, $dailyMinutes),
                'predicted_delivery_at' => $predictedDeliveryAt,
                'material_shortages' => $materialShortages,
            ];
        });
    }
}
