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

    public function test_create_order_with_initial_deposit_and_voucher(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $user = User::first();
        $this->actingAs($user);

        $customer = Customer::firstOrFail();
        $variant = ProductVariant::firstOrFail();

        $file = \Illuminate\Http\UploadedFile::fake()->image('comprobante_inicial.jpg');

        $response = $this->post('/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
            'committed_delivery_at' => now()->addDays(3)->toDateTimeString(),
            'notes' => 'Pedido con abono inicial',
            'initial_deposit' => 50.0,
            'payment_method' => 'transfer',
            'voucher' => $file,
        ]);

        $response->assertRedirect('/orders');

        $order = Order::where('notes', 'Pedido con abono inicial')->firstOrFail();
        $this->assertCount(1, $order->payments);

        $payment = $order->payments->first();
        $this->assertSame(50.0, (float) $payment->amount);
        $this->assertSame('transfer', $payment->payment_method);
        $this->assertNotNull($payment->voucher_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($payment->voucher_path);

        $this->assertSame(50.0, (float) $order->paid_amount);
        $this->assertGreaterThanOrEqual(0, (float) $order->pending_balance);
    }

    public function test_record_additional_payment_for_order(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $order = Order::firstOrFail();
        $order->update(['total_amount' => 1000.0]);
        $order->refresh();

        $previousPaid = (float) $order->paid_amount;
        $advance = 50.0;

        $response = $this->post("/orders/{$order->id}/payments", [
            'amount' => $advance,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'notes' => 'Abono en efectivo en taller',
        ]);

        $response->assertRedirect();
        $order->refresh();

        $this->assertSame($previousPaid + $advance, (float) $order->paid_amount);
    }

    public function test_owner_can_void_payment(): void
    {
        $owner = User::where('is_super_admin', true)->first() ?? User::first();
        $this->actingAs($owner);

        $order = Order::firstOrFail();
        $payment = \App\Models\OrderPayment::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'amount' => 15000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'created_by' => $owner->id,
        ]);

        $this->assertDatabaseHas('order_payments', ['id' => $payment->id]);

        $response = $this->delete("/payments/{$payment->id}");
        $response->assertRedirect();

        $this->assertDatabaseMissing('order_payments', ['id' => $payment->id]);
    }

    public function test_update_order_synchronizes_tasks_and_shipping_type(): void
    {
        $user = User::first();
        $this->actingAs($user);

        $order = Order::with('tasks')->firstOrFail();
        $initialTask = $order->tasks->first();
        $customer = Customer::firstOrFail();
        $variants = ProductVariant::all();

        $variant1 = $variants->first();
        $variant2 = $variants->count() > 1 ? $variants->get(1) : $variant1;

        $response = $this->put("/orders/{$order->id}", [
            'customer_id' => $customer->id,
            'committed_delivery_at' => now()->addDays(7)->toDateTimeString(),
            'shipping_type' => 'envio_incluido',
            'notes' => 'Pedido actualizado exitosamente',
            'items' => [
                [
                    'product_variant_id' => $variant1->id,
                    'quantity' => 5,
                    'unit_price' => 50000,
                ],
                [
                    'product_variant_id' => $variant2->id,
                    'quantity' => 2,
                    'unit_price' => 30000,
                ],
            ],
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertSame('envio_incluido', $order->shipping_type);
        $this->assertSame('Pedido actualizado exitosamente', $order->notes);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'shipping_type' => 'envio_incluido',
        ]);

        // Verify task synchronization
        $this->assertDatabaseHas('production_tasks', [
            'order_id' => $order->id,
            'product_variant_id' => $variant1->id,
            'quantity' => 5,
        ]);
    }
}

