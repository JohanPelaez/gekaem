<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
