<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;
    public function test_kanban_board_loads_successfully(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $response = $this->get('/kanban');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Kanban/Index')
            ->has('workflows')
            ->has('orders')
            ->has('auth.tenant')
        );
    }

    public function test_workflows_page_loads_successfully(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $response = $this->get('/workflows');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Workflows/Index')
            ->has('workflows')
        );
    }

    public function test_move_production_task_updates_stage_and_syncs_order(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $task = ProductionTask::firstOrFail();
        $targetStage = WorkflowStage::where('workflow_id', $task->workflow_id)
            ->where('id', '!=', $task->current_stage_id)
            ->firstOrFail();

        $response = $this->patch("/kanban/tasks/{$task->id}/move", [
            'target_stage_id' => $targetStage->id,
            'column_position' => 2,
        ]);

        $response->assertRedirect();
        $task->refresh();

        $this->assertSame($targetStage->id, $task->current_stage_id);
        $this->assertSame(2, $task->column_position);
    }

    public function test_updating_order_status_never_cascades_to_production_tasks(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $order = Order::with('productionTasks')->firstOrFail();
        $tasks = $order->productionTasks;
        $this->assertNotEmpty($tasks);

        $originalStages = $tasks->pluck('current_stage_id', 'id')->all();

        $statuses = OrderStatus::where('tenant_id', $order->tenant_id)
            ->where('is_history_status', false)
            ->orderBy('position')
            ->get();

        foreach ($statuses as $status) {
            $response = $this->patchJson("/kanban/orders/{$order->id}/status", [
                'order_status_id' => $status->id,
            ]);

            $response->assertOk();
            $response->assertJsonPath('success', true);
            $response->assertJsonPath('order.order_status_id', $status->id);

            $returnedTasks = collect($response->json('order.production_tasks'))->pluck('current_stage_id', 'id')->all();
            $this->assertSame($originalStages, $returnedTasks);

            foreach ($originalStages as $taskId => $stageId) {
                $this->assertSame(
                    $stageId,
                    ProductionTask::findOrFail($taskId)->current_stage_id,
                    "Task #{$taskId} stage must remain untouched when order moves to {$status->name}"
                );
            }
        }
    }

    public function test_moving_task_to_active_stage_promotes_pending_order_to_in_progress_only(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $order = Order::with('productionTasks.workflow.stages')->firstOrFail();
        $pendingStatus = OrderStatus::where('tenant_id', $order->tenant_id)
            ->where('system_key', 'pending')
            ->firstOrFail();
        $inProgressStatus = OrderStatus::where('tenant_id', $order->tenant_id)
            ->where('system_key', 'in_progress')
            ->firstOrFail();
        $blockedStatus = OrderStatus::where('tenant_id', $order->tenant_id)
            ->where('system_key', 'blocked')
            ->firstOrFail();

        $task = $order->productionTasks->firstOrFail();
        $stages = WorkflowStage::where('workflow_id', $task->workflow_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $firstStage = $stages->first();
        $activeStage = $stages->first(function (WorkflowStage $s) use ($firstStage): bool {
            $name = mb_strtolower(trim($s->name), 'UTF-8');
            return $s->id !== $firstStage->id
                && $s->stage_category !== 'blocked'
                && !str_contains($name, 'bloquead')
                && !str_contains($name, 'detenid');
        });
        $this->assertNotNull($activeStage);

        // Set order to Pending and task to initial stage
        $order->update([
            'order_status_id' => $pendingStatus->id,
            'derived_status' => 'pending',
        ]);
        $task->update(['current_stage_id' => $firstStage->id]);

        // 1. Moving task to active stage promotes Pending -> En Producción
        $response = $this->patchJson("/kanban/tasks/{$task->id}/stage", [
            'stage_id' => $activeStage->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('order.order_status_id', $inProgressStatus->id);
        $response->assertJsonPath('order.derived_status', 'in_progress');

        $order->refresh();
        $this->assertSame($inProgressStatus->id, $order->order_status_id);
        $this->assertSame('in_progress', $order->derived_status);

        // 2. Moving task back to initial stage does NOT regress the order back to Pending
        $this->patchJson("/kanban/tasks/{$task->id}/stage", [
            'stage_id' => $firstStage->id,
        ])->assertOk();

        $order->refresh();
        $this->assertSame($inProgressStatus->id, $order->order_status_id);

        // 3. When order is already in Blocked, moving a task to active stage does NOT override order status
        $order->update([
            'order_status_id' => $blockedStatus->id,
            'derived_status' => 'blocked',
        ]);

        $this->patchJson("/kanban/tasks/{$task->id}/stage", [
            'stage_id' => $activeStage->id,
        ])->assertOk();

        $order->refresh();
        $this->assertSame($blockedStatus->id, $order->order_status_id);
        $this->assertSame('blocked', $order->derived_status);
    }
}
