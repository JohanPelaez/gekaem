<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Kanban\MoveProductionTaskAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionTask;
use App\Models\Workflow;
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

        $workflows = Workflow::with(['stages' => fn ($q) => $q->orderBy('position')])->get();
        if ($workflows->isNotEmpty()) {
            $this->repairTasksIfNeeded($workflows);
        }

        $orders = Order::where('derived_status', '!=', 'delivered')
            ->with([
                'customer',
                'productionTasks.productVariant.product',
                'productionTasks.workflow.stages' => fn ($q) => $q->orderBy('position'),
                'productionTasks.currentStage',
            ])
            ->orderBy('kanban_swimlane_position')
            ->orderBy('committed_delivery_at')
            ->get();

        return Inertia::render('Kanban/Index', [
            'workflows' => $workflows,
            'orders' => $orders,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->get(['id', 'name']),
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
     * Move a Kanban production task to a new stage.
     */
    public function moveTask(
        Request $request,
        ProductionTask $productionTask,
        MoveProductionTaskAction $moveAction
    ): RedirectResponse {
        $validated = $request->validate([
            'target_stage_id' => ['required', 'integer', 'exists:workflow_stages,id'],
            'column_position' => ['nullable', 'integer'],
        ]);

        $moveAction->execute(
            $productionTask,
            (int) $validated['target_stage_id'],
            (int) ($validated['column_position'] ?? 0)
        );

        return redirect()->back()->with('success', 'Tarea actualizada correctamente en el tablero Kanban.');
    }

    /**
     * Update an order's macro status and advance its underlying tasks.
     */
    public function macroStatus(
        Request $request,
        Order $order,
        MoveProductionTaskAction $moveAction
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,in_progress,blocked,ready'],
        ]);

        $status = $validated['status'];
        $targetCategory = ($status === 'ready') ? 'done' : $status;

        DB::transaction(function () use ($order, $status, $targetCategory, $moveAction): void {
            $tasks = $order->productionTasks()->with('workflow.stages')->get();
            foreach ($tasks as $task) {
                $stage = $task->workflow?->stages->firstWhere('stage_category', $targetCategory);
                if ($stage && $task->current_stage_id !== $stage->id) {
                    $moveAction->execute($task, $stage->id, 0);
                }
            }
            $order->update(['derived_status' => $status]);
        });

        return redirect()->back()->with('success', "Estado del pedido #{$order->order_number} actualizado.");
    }
}
