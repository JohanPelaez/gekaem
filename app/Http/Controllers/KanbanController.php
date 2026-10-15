<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Kanban\MoveProductionTaskAction;
use App\Models\Order;
use App\Models\ProductionTask;
use App\Models\Workflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KanbanController extends Controller
{
    /**
     * Display the Kanban Board with Swimlanes (US = Orders, Tasks = Products).
     */
    public function index(Request $request): Response
    {
        $workflows = Workflow::with(['stages' => fn ($q) => $q->orderBy('position')])->get();

        $selectedWorkflowId = $request->integer(
            'workflow_id',
            $workflows->firstWhere('is_default', true)?->id ?? $workflows->first()?->id ?? 0
        );

        $orders = Order::where('derived_status', '!=', 'delivered')
            ->with([
                'customer',
                'tasks' => fn ($query) => $query
                    ->where('workflow_id', $selectedWorkflowId)
                    ->with(['currentStage', 'productVariant.product'])
                    ->orderBy('column_position'),
            ])
            ->whereHas('tasks', fn ($query) => $query->where('workflow_id', $selectedWorkflowId))
            ->orderBy('kanban_swimlane_position')
            ->get();

        return Inertia::render('Kanban/Index', [
            'workflows' => $workflows,
            'selectedWorkflowId' => $selectedWorkflowId,
            'orders' => $orders,
        ]);
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
}
