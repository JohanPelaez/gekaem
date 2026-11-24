<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\Workflow;
use App\Services\TrashPurgeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    /**
     * Allowed trash types mapped to their Eloquent models.
     *
     * @var array<string, class-string<Model>>
     */
    private const MODEL_MAP = [
        'order' => Order::class,
        'customer' => Customer::class,
        'workflow' => Workflow::class,
        'raw_material' => RawMaterial::class,
        'product' => Product::class,
    ];

    /**
     * Display the Recycle Bin for CEO (owner) and Super Admin.
     */
    public function index(Request $request, TrashPurgeService $purgeService): Response
    {
        $tenant = $this->authorizeAndResolveTenant($request);
        $purgeService->purgeExpiredThrottled((int) $tenant->id, 10);

        $items = $this->collectTrashedItems((int) $tenant->id);

        $counts = [
            'all' => count($items),
            'order' => 0,
            'customer' => 0,
            'workflow' => 0,
            'raw_material' => 0,
            'product' => 0,
        ];

        foreach ($items as $item) {
            $counts[$item['type']]++;
        }

        return Inertia::render('Trash/Index', [
            'items' => $items,
            'retentionDays' => TrashPurgeService::RETENTION_DAYS,
            'counts' => $counts,
        ]);
    }

    /**
     * Restore a soft-deleted record back to active state.
     */
    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $tenant = $this->authorizeAndResolveTenant($request);
        $model = $this->findTrashedForTenant($type, $id, (int) $tenant->id);

        DB::transaction(function () use ($type, $model, $tenant): void {
            if ($type === 'order' && $model instanceof Order) {
                $model->restore();

                $statusExists = $model->order_status_id !== null
                    && OrderStatus::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenant->id)
                        ->where('id', $model->order_status_id)
                        ->exists();

                if (!$statusExists) {
                    $firstActiveStatus = OrderStatus::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenant->id)
                        ->where('is_history_status', false)
                        ->orderBy('position')
                        ->first();

                    if ($firstActiveStatus) {
                        $model->order_status_id = $firstActiveStatus->id;
                        $model->save();
                    }
                }

                return;
            }

            if ($type === 'product' && $model instanceof Product) {
                $workflowActive = $model->workflow_id !== null
                    && Workflow::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenant->id)
                        ->where('id', $model->workflow_id)
                        ->exists();

                if (!$workflowActive) {
                    $defaultWorkflow = Workflow::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenant->id)
                        ->orderByDesc('is_default')
                        ->orderBy('id')
                        ->first();

                    if ($defaultWorkflow) {
                        $model->workflow_id = $defaultWorkflow->id;
                    }
                }

                $model->restore();
                $model->save();

                return;
            }

            $model->restore();
        });

        return response()->json([
            'success' => true,
            'message' => 'Elemento restaurado correctamente.',
        ]);
    }

    /**
     * Permanently delete a single record from the Recycle Bin.
     */
    public function forceDelete(Request $request, string $type, int $id, TrashPurgeService $purgeService): JsonResponse
    {
        $tenant = $this->authorizeAndResolveTenant($request);
        $model = $this->findTrashedForTenant($type, $id, (int) $tenant->id);

        $deleted = $purgeService->forceDeleteModel($model);

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo eliminar definitivamente este elemento porque aún está referenciado por otros registros activos.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Elemento eliminado de forma permanente.',
        ]);
    }

    /**
     * Empty the entire Recycle Bin for the active tenant.
     */
    public function emptyTrash(Request $request, TrashPurgeService $purgeService): JsonResponse
    {
        $tenant = $this->authorizeAndResolveTenant($request);
        $deletedCount = 0;
        $failedCount = 0;

        /** @var list<class-string<Model>> $models */
        $models = [
            Order::class,
            Product::class,
            RawMaterial::class,
            Workflow::class,
            Customer::class,
        ];

        foreach ($models as $modelClass) {
            $records = $modelClass::withoutGlobalScope('tenant')
                ->onlyTrashed()
                ->where('tenant_id', $tenant->id)
                ->get();

            foreach ($records as $record) {
                if ($purgeService->forceDeleteModel($record)) {
                    $deletedCount++;
                } else {
                    $failedCount++;
                }
            }
        }

        $message = $failedCount > 0
            ? "Se eliminaron {$deletedCount} elementos. {$failedCount} no pudieron purgarse por referencias activas."
            : "Papelera vaciada correctamente ({$deletedCount} elementos eliminados definitivamente).";

        return response()->json([
            'success' => true,
            'deleted_count' => $deletedCount,
            'failed_count' => $failedCount,
            'message' => $message,
        ]);
    }

    /**
     * Authorize CEO (owner) or Super Admin and return the active tenant.
     */
    private function authorizeAndResolveTenant(Request $request): Tenant
    {
        $user = $request->user();
        $isAuthorized = $user !== null && ($user->isSuperAdmin() || $user->currentRole() === 'owner');
        abort_if(!$isAuthorized, 403, 'Acceso exclusivo para el rol CEO o Super Admin.');

        /** @var Tenant|null $tenant */
        $tenant = $user->currentTenant()
            ?? (session('current_tenant_id') ? Tenant::find(session('current_tenant_id')) : null)
            ?? $user->tenants()->first()
            ?? Tenant::first();

        abort_if($tenant === null, 403, 'No se pudo resolver el taller activo.');

        return $tenant;
    }

    /**
     * Find a trashed model strictly belonging to the active tenant.
     */
    private function findTrashedForTenant(string $type, int $id, int $tenantId): Model
    {
        abort_unless(isset(self::MODEL_MAP[$type]), 404, 'Tipo de elemento no válido.');

        $modelClass = self::MODEL_MAP[$type];

        /** @var Model|null $record */
        $record = $modelClass::withoutGlobalScope('tenant')
            ->onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        abort_if($record === null, 404, 'El elemento no existe en la papelera de tu taller.');

        return $record;
    }

    /**
     * Collect and normalize all trashed items across the 5 modules.
     *
     * @return list<array<string, mixed>>
     */
    private function collectTrashedItems(int $tenantId): array
    {
        $items = [];

        $orders = Order::withoutGlobalScope('tenant')
            ->onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->with(['customer', 'orderStatus'])
            ->withCount('productionTasks')
            ->get();

        foreach ($orders as $order) {
            $customerName = $order->customer?->name ?? 'Sin cliente';
            $totalFormatted = '$' . number_format((float) $order->total_amount, 0, ',', '.');
            $tasksLabel = $order->production_tasks_count === 1 ? '1 ítem' : "{$order->production_tasks_count} ítems";
            $items[] = $this->formatTrashItem(
                id: (int) $order->id,
                type: 'order',
                typeLabel: 'Pedido',
                title: "Pedido #{$order->order_number} — {$customerName}",
                subtitle: "{$totalFormatted} · {$tasksLabel}",
                deletedAt: $order->deleted_at
            );
        }

        $customers = Customer::withoutGlobalScope('tenant')
            ->onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->get();

        foreach ($customers as $customer) {
            $details = array_values(array_filter([
                $customer->document_number ? "Doc: {$customer->document_number}" : null,
                $customer->phone ? "Tel: {$customer->phone}" : null,
                $customer->email,
                $customer->city,
            ]));

            $items[] = $this->formatTrashItem(
                id: (int) $customer->id,
                type: 'customer',
                typeLabel: 'Cliente',
                title: (string) $customer->name,
                subtitle: !empty($details) ? implode(' · ', $details) : 'Cliente registrado',
                deletedAt: $customer->deleted_at
            );
        }

        $workflows = Workflow::withoutGlobalScope('tenant')
            ->onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->withCount('stages')
            ->get();

        foreach ($workflows as $workflow) {
            $stagesLabel = $workflow->stages_count === 1
                ? '1 etapa conservada'
                : "{$workflow->stages_count} etapas conservadas";
            $details = array_values(array_filter([
                $stagesLabel,
                $workflow->description,
            ]));

            $items[] = $this->formatTrashItem(
                id: (int) $workflow->id,
                type: 'workflow',
                typeLabel: 'Flujo de Trabajo',
                title: (string) $workflow->name,
                subtitle: implode(' · ', $details),
                deletedAt: $workflow->deleted_at
            );
        }

        $rawMaterials = RawMaterial::withoutGlobalScope('tenant')
            ->onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->get();

        foreach ($rawMaterials as $material) {
            $stockFormatted = rtrim(rtrim(number_format((float) $material->current_stock, 3, '.', ''), '0'), '.');
            $details = array_values(array_filter([
                $material->sku ? "SKU: {$material->sku}" : null,
                "Stock: {$stockFormatted} {$material->unit_of_measure}",
            ]));

            $items[] = $this->formatTrashItem(
                id: (int) $material->id,
                type: 'raw_material',
                typeLabel: 'Materia Prima',
                title: (string) $material->name,
                subtitle: implode(' · ', $details),
                deletedAt: $material->deleted_at
            );
        }

        $products = Product::withoutGlobalScope('tenant')
            ->onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->withCount('variants')
            ->get();

        foreach ($products as $product) {
            $priceFormatted = '$' . number_format((float) $product->base_price, 0, ',', '.');
            $variantsLabel = $product->variants_count === 1 ? '1 variante' : "{$product->variants_count} variantes";
            $details = array_values(array_filter([
                $product->barcode ? "Cód: {$product->barcode}" : null,
                $priceFormatted,
                $variantsLabel,
            ]));

            $items[] = $this->formatTrashItem(
                id: (int) $product->id,
                type: 'product',
                typeLabel: 'Producto',
                title: (string) $product->name,
                subtitle: implode(' · ', $details),
                deletedAt: $product->deleted_at
            );
        }

        usort($items, fn (array $a, array $b): int => strcmp($b['deleted_at'], $a['deleted_at']));

        return $items;
    }

    /**
     * Format a unified trash item payload.
     *
     * @return array<string, mixed>
     */
    private function formatTrashItem(
        int $id,
        string $type,
        string $typeLabel,
        string $title,
        string $subtitle,
        mixed $deletedAt
    ): array {
        $deletedCarbon = $deletedAt instanceof Carbon ? $deletedAt : Carbon::parse($deletedAt);
        $expiresCarbon = $deletedCarbon->copy()->addDays(TrashPurgeService::RETENTION_DAYS);
        $daysRemaining = max(
            0,
            (int) now()->startOfDay()->diffInDays($expiresCarbon->copy()->startOfDay(), false)
        );

        return [
            'id' => $id,
            'type' => $type,
            'type_label' => $typeLabel,
            'title' => $title,
            'subtitle' => $subtitle,
            'deleted_at' => $deletedCarbon->toIso8601String(),
            'expires_at' => $expiresCarbon->toIso8601String(),
            'days_remaining' => $daysRemaining,
        ];
    }
}

