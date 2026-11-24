<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrashPurgeService
{
    public const RETENTION_DAYS = 8;

    /**
     * Permanently purge all soft-deleted records older than 8 calendar days.
     */
    public function purgeExpired(?int $tenantId = null): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $purgedCount = 0;

        /** @var list<class-string<Model>> $models */
        $models = [
            Order::class,
            Product::class,
            RawMaterial::class,
            Workflow::class,
            Customer::class,
        ];

        foreach ($models as $modelClass) {
            $query = $modelClass::withoutGlobalScope('tenant')
                ->onlyTrashed()
                ->where('deleted_at', '<=', $cutoff);

            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            $records = $query->get();

            foreach ($records as $record) {
                if ($this->forceDeleteModel($record)) {
                    $purgedCount++;
                }
            }
        }

        return $purgedCount;
    }

    /**
     * Run purgeExpired for a tenant with a cache throttle (default 10 minutes).
     */
    public function purgeExpiredThrottled(int $tenantId, int $throttleMinutes = 10): int
    {
        $cacheKey = "trash_purge_tenant_{$tenantId}";

        return (int) Cache::remember($cacheKey, now()->addMinutes($throttleMinutes), function () use ($tenantId): int {
            return $this->purgeExpired($tenantId);
        });
    }

    /**
     * Permanently delete a single soft-deleted model and safely clean up orphaned children.
     */
    public function forceDeleteModel(Model $model): bool
    {
        try {
            DB::transaction(function () use ($model): void {
                if ($model instanceof Order) {
                    $model->paymentSchedules()->delete();
                    $model->payments()->delete();
                    $model->productionTasks()->delete();
                    $model->forceDelete();

                    return;
                }

                if ($model instanceof Workflow) {
                    $stageIds = $model->stages()->pluck('id')->all();

                    // Reasignar cualquier tarea huérfana (incluyendo pedidos en papelera) si existe otro flujo activo
                    $fallbackWorkflow = Workflow::withoutGlobalScope('tenant')
                        ->where('tenant_id', $model->tenant_id)
                        ->where('id', '!=', $model->id)
                        ->with('stages')
                        ->orderByDesc('is_default')
                        ->first();

                    $fallbackStage = $fallbackWorkflow?->stages?->sortBy('position')->first();

                    if ($fallbackWorkflow && $fallbackStage) {
                        Product::withoutGlobalScope('tenant')
                            ->withTrashed()
                            ->where('workflow_id', $model->id)
                            ->update(['workflow_id' => $fallbackWorkflow->id]);

                        ProductionTask::withoutGlobalScope('tenant')
                            ->where('workflow_id', $model->id)
                            ->orWhereIn('current_stage_id', $stageIds)
                            ->update([
                                'workflow_id' => $fallbackWorkflow->id,
                                'current_stage_id' => $fallbackStage->id,
                            ]);
                    }

                    $model->stages()->delete();
                    $model->forceDelete();

                    return;
                }

                if ($model instanceof Product) {
                    $model->materialRequirements()->delete();

                    foreach ($model->variants()->get() as $variant) {
                        $hasTasks = ProductionTask::withoutGlobalScope('tenant')
                            ->where('product_variant_id', $variant->id)
                            ->exists();

                        if (!$hasTasks) {
                            $variant->delete();
                        }
                    }

                    $model->forceDelete();

                    return;
                }

                if ($model instanceof RawMaterial) {
                    $model->rawMaterialSuppliers()->delete();
                    $model->movements()->delete();
                    $model->materialRequests()->delete();
                    $model->forceDelete();

                    return;
                }

                $model->forceDelete();
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('TrashPurgeService: No se pudo purgar el registro por restricción de integridad.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

