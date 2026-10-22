<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_kanban_loads_order_statuses_and_dependencies(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $response = $this->get('/kanban');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Kanban/Index')
            ->has('orderStatuses')
            ->has('customers')
            ->has('products')
            ->has('workflows')
            ->has('allScheduledOrders')
        );
    }

    public function test_can_create_new_order_status(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $response = $this->postJson('/order-statuses', [
            'name' => 'empacado especial',
            'color' => '#8b5cf6',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('order_statuses', [
            'name' => 'Empacado Especial',
            'color' => '#8b5cf6',
            'system_key' => 'custom',
        ]);
    }

    public function test_can_update_order_status(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $status = OrderStatus::firstOrFail();

        $response = $this->putJson("/order-statuses/{$status->id}", [
            'name' => 'espera de materiales',
            'color' => '#f59e0b',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $status->refresh();
        $this->assertSame('Espera De Materiales', $status->name);
        $this->assertSame('#f59e0b', $status->color);
    }

    public function test_can_reorder_order_statuses(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $statuses = OrderStatus::all();
        $this->assertGreaterThanOrEqual(2, $statuses->count());

        $reversedIds = $statuses->pluck('id')->reverse()->values()->all();

        $response = $this->postJson('/order-statuses/reorder', [
            'status_ids' => $reversedIds,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $firstItem = OrderStatus::findOrFail($reversedIds[0]);
        $this->assertSame(1, $firstItem->position);
    }

    public function test_can_update_order_status_via_kanban_patch(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $order = Order::firstOrFail();
        $newStatus = OrderStatus::where('id', '!=', $order->order_status_id)->firstOrFail();

        $response = $this->patchJson("/kanban/orders/{$order->id}/status", [
            'order_status_id' => $newStatus->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame($newStatus->id, $order->order_status_id);
    }

    public function test_delete_order_status_reassigns_orders(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $targetStatus = OrderStatus::create([
            'tenant_id' => $user->current_tenant_id ?? 1,
            'name' => 'Para Eliminar',
            'system_key' => 'custom',
            'color' => '#64748b',
            'position' => 99,
        ]);

        $order = Order::firstOrFail();
        $order->update(['order_status_id' => $targetStatus->id]);

        $response = $this->deleteJson("/order-statuses/{$targetStatus->id}");

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseMissing('order_statuses', ['id' => $targetStatus->id]);

        $order->refresh();
        $this->assertNotNull($order->order_status_id);
        $this->assertNotEquals($targetStatus->id, $order->order_status_id);
    }
}
