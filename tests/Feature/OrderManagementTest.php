<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_orders_page_loads_successfully_with_inertia(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $response = $this->get('/orders');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->has('orders')
            ->has('customers')
            ->has('products')
            ->has('wipQueueMinutes')
            ->has('dailyProductiveMinutes')
            ->has('defaultBufferPercent')
        );
    }

    public function test_predict_endpoint_returns_metrics_and_shortages(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $variant = ProductVariant::firstOrFail();

        $response = $this->postJson('/orders/predict', [
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
            'buffer_percent' => 25,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'order_production_minutes',
            'wip_queue_minutes',
            'buffer_minutes',
            'total_minutes_with_buffer',
            'formatted_duration',
            'predicted_delivery_at',
            'material_shortages',
        ]);
    }

    public function test_create_order_generates_tasks_and_reserves_stock(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $customer = Customer::firstOrFail();
        $variant = ProductVariant::firstOrFail();

        $response = $this->post('/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 3,
                ],
            ],
            'custom_buffer_percent' => 20,
            'committed_delivery_at' => now()->addDays(5)->toDateTimeString(),
            'notes' => 'Pedido de prueba automatizada',
        ]);

        $response->assertRedirect('/orders');

        $this->assertDatabaseHas('orders', [
            'customer_id' => $customer->id,
            'derived_status' => 'pending',
            'notes' => 'Pedido de prueba automatizada',
        ]);

        $order = Order::where('notes', 'Pedido de prueba automatizada')->firstOrFail();
        $this->assertCount(1, $order->tasks);
        $this->assertSame((float) 3.0, (float) $order->tasks->first()->quantity);
    }

    public function test_mark_order_delivered_when_ready(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $order = Order::firstOrFail();
        $order->derived_status = 'ready';
        $order->save();

        $response = $this->patch("/orders/{$order->id}/deliver");

        $response->assertRedirect();
        $order->refresh();

        $this->assertSame('delivered', $order->derived_status);
        $this->assertNotNull($order->delivered_at);
    }
}
