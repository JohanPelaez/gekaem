<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Kanban\MoveProductionTaskAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrthogonalAttribute;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\UnitOfMeasure;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class KanbanController extends Controller
{
    /**
     * Display the Kanban Board with Swimlanes (US = Orders, Tasks = Products).
     */
    public function index(Request $request): Response
    {
        $selectedOrderId = $request->integer('order_id') ?: null;
        $tenant = auth()->user()?->currentTenant();

        $workflows = Workflow::with(['stages' => fn ($q) => $q->orderBy('position')])->orderBy('name')->get();
        if ($workflows->isNotEmpty()) {
            $this->repairTasksIfNeeded($workflows);
        }

        $orderStatuses = OrderStatus::orderBy('position')->get();
        if ($orderStatuses->isEmpty() && $tenant) {
            $orderStatuses = $this->seedDefaultStatuses($tenant->id);
        }

        $orders = Order::where('derived_status', '!=', 'delivered')
            ->with([
                'customer',
                'orderStatus',
                'payments',
                'productionTasks.productVariant.product.productType',
                'productionTasks.productVariant.product.materials.rawMaterial',
                'productionTasks.productVariant.attributeOptions.attribute',
                'productionTasks.productVariant.materialRequirements.rawMaterial',
                'productionTasks.workflow.stages' => fn ($q) => $q->orderBy('position'),
                'productionTasks.currentStage',
            ])
            ->orderBy('kanban_swimlane_position')
            ->orderBy('committed_delivery_at')
            ->get();

        $allScheduledOrders = Order::query()
            ->where(function ($q): void {
                $q->whereNotNull('committed_delivery_at')->orWhereNotNull('predicted_delivery_at');
            })
            ->where('derived_status', '!=', 'delivered')
            ->with([
                'customer:id,name',
                'orderStatus',
                'productionTasks:id,order_id,quantity,item_name_snapshot,current_stage_id',
                'productionTasks.currentStage:id,name,color,stage_category',
            ])
            ->select('id', 'order_number', 'customer_id', 'order_status_id', 'committed_delivery_at', 'predicted_delivery_at', 'derived_status', 'shipping_type')
            ->orderByRaw('COALESCE(committed_delivery_at, predicted_delivery_at) ASC')
            ->get();

        $ordersCountByDate = Order::query()
            ->where(function ($q): void {
                $q->whereNotNull('committed_delivery_at')->orWhereNotNull('predicted_delivery_at');
            })
            ->where('derived_status', '!=', 'delivered')
            ->selectRaw('DATE(COALESCE(committed_delivery_at, predicted_delivery_at)) as date_key, COUNT(*) as aggregate_count')
            ->groupBy('date_key')
            ->pluck('aggregate_count', 'date_key')
            ->all();

        return Inertia::render('Kanban/Index', [
            'workflows' => $workflows,
            'orders' => $orders,
            'orderStatuses' => $orderStatuses,
            'allScheduledOrders' => $allScheduledOrders,
            'ordersCountByDate' => $ordersCountByDate,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone', 'email', 'tax_id', 'department', 'city', 'neighborhood', 'address']),
            'products' => Product::where('is_active', true)->with(['variants.attributeOptions.attribute', 'workflow', 'materialRequirements.rawMaterial', 'productType'])->orderBy('name')->get(),
            'productTypes' => ProductType::orderBy('name')->get(['id', 'name']),
            'rawMaterials' => RawMaterial::orderBy('name')->get(['id', 'name', 'unit_of_measure', 'current_stock', 'reserved_stock']),
            'orthogonalAttributes' => OrthogonalAttribute::with('options')->orderBy('name')->get(),
            'attributes' => OrthogonalAttribute::with('options')->orderBy('name')->get(),
            'unitsOfMeasure' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']),
            'selectedOrderId' => $selectedOrderId,
        ]);
    }

    /**
     * Auto-repair tasks missing workflow or valid current stage.
     */
    private function repairTasksIfNeeded($workflows): void
    {
        $defaultWorkflow = $workflows->firstWhere('is_default', true) ?? $workflows->first();
        if (!$defaultWorkflow) {
            return;
        }

        $tasks = ProductionTask::with(['productVariant.product', 'workflow.stages'])->get();
        foreach ($tasks as $task) {
            $wf = $task->workflow ?? $workflows->firstWhere('id', $task->productVariant?->product?->workflow_id) ?? $defaultWorkflow;
            $stageIds = $wf->stages->pluck('id')->all();
            if (!$task->workflow_id || !in_array($task->current_stage_id, $stageIds, true)) {
                $firstStageId = $wf->stages->first()?->id;
                if ($firstStageId) {
                    $task->update([
                        'workflow_id' => $wf->id,
                        'current_stage_id' => $firstStageId,
                    ]);
                }
            }
        }
    }

    /**
     * Seed default statuses if none exist for tenant.
     */
    private function seedDefaultStatuses(int $tenantId)
    {
        $defaults = [
            ['name' => 'Pendiente', 'system_key' => 'pending', 'color' => '#64748b', 'position' => 1],
            ['name' => 'En Producción', 'system_key' => 'in_progress', 'color' => '#3b82f6', 'position' => 2],
            ['name' => 'Bloqueado', 'system_key' => 'blocked', 'color' => '#ef4444', 'position' => 3],
            ['name' => 'Listo para Entrega', 'system_key' => 'ready', 'color' => '#10b981', 'position' => 4],
        ];

        foreach ($defaults as $def) {
            OrderStatus::create([
                'tenant_id' => $tenantId,
                'name' => $def['name'],
                'system_key' => $def['system_key'],
                'color' => $def['color'],
                'position' => $def['position'],
            ]);
        }

        return OrderStatus::orderBy('position')->get();
    }

    /**
     * Move or update a Kanban production task to a new stage.
     */
    public function moveTask(
        Request $request,
        ProductionTask $productionTask,
        MoveProductionTaskAction $moveAction
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'target_stage_id' => ['nullable', 'integer', 'exists:workflow_stages,id'],
            'stage_id' => ['nullable', 'integer', 'exists:workflow_stages,id'],
            'column_position' => ['nullable', 'integer'],
        ]);

        $stageId = (int) ($validated['stage_id'] ?? $validated['target_stage_id']);
        if (!$stageId) {
            return response()->json(['error' => 'Se requiere el campo stage_id o target_stage_id.'], 422);
        }

        $updatedTask = $moveAction->execute(
            $productionTask,
            $stageId,
            (int) ($validated['column_position'] ?? 0)
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Etapa actualizada correctamente.',
                'task' => $updatedTask->load(['currentStage', 'workflow']),
                'order' => $updatedTask->order->fresh(['orderStatus', 'customer']),
            ]);
        }

        return redirect()->back()->with('success', 'Tarea actualizada correctamente en el tablero Kanban.');
    }

    /**
     * Update an order's status and optionally advance its underlying tasks.
     */
    public function updateOrderStatus(
        Request $request,
        Order $order,
        MoveProductionTaskAction $moveAction
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'order_status_id' => ['nullable', 'integer', 'exists:order_statuses,id'],
            'status_id' => ['nullable', 'integer', 'exists:order_statuses,id'],
            'status' => ['nullable', 'string'],
        ]);

        $statusId = $validated['order_status_id'] ?? $validated['status_id'] ?? null;
        /** @var OrderStatus|null $orderStatus */
        $orderStatus = null;

        if ($statusId) {
            $orderStatus = OrderStatus::find($statusId);
        } elseif (!empty($validated['status'])) {
            $orderStatus = OrderStatus::where('system_key', $validated['status'])->first();
        }

        if (!$orderStatus) {
            return response()->json(['error' => 'Estado de pedido no encontrado.'], 422);
        }

        DB::transaction(function () use ($order, $orderStatus, $moveAction): void {
            $order->order_status_id = $orderStatus->id;

            if ($orderStatus->system_key && in_array($orderStatus->system_key, ['pending', 'in_progress', 'blocked', 'ready'], true)) {
                $targetStatus = $orderStatus->system_key;
                $targetCategory = ($targetStatus === 'ready') ? 'done' : $targetStatus;

                $tasks = $order->productionTasks()->with('workflow.stages')->get();
                foreach ($tasks as $task) {
                    $stage = $task->workflow?->stages->firstWhere('stage_category', $targetCategory);
                    if ($stage && $task->current_stage_id !== $stage->id) {
                        $moveAction->execute($task, $stage->id, 0);
                    }
                }
                $order->derived_status = $targetStatus;
            }

            $order->save();
        });

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Estado del pedido #{$order->order_number} actualizado.",
                'order' => $order->fresh(['orderStatus', 'customer', 'payments']),
            ]);
        }

        return redirect()->back()->with('success', "Estado del pedido #{$order->order_number} actualizado.");
    }

    /**
     * Legacy helper to update an order's macro status and advance its underlying tasks.
     */
    public function macroStatus(
        Request $request,
        Order $order,
        MoveProductionTaskAction $moveAction
    ): RedirectResponse|JsonResponse {
        return $this->updateOrderStatus($request, $order, $moveAction);
    }
}
