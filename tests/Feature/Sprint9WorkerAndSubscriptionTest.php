<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Sprint9WorkerAndSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected User $worker;
    protected User $owner;
    protected User $admin;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::where('email', 'dueno@gekaem.test')->firstOrFail();
        $this->worker = User::where('email', 'trabajador@gekaem.test')->firstOrFail();
        $this->admin = User::where('email', 'admin@gekaem.test')->firstOrFail();
        $this->tenant = $this->owner->currentTenant();
    }

    /**
     * Test worker visiting restricted routes is redirected to /kanban.
     */
    public function test_worker_is_redirected_to_kanban_from_restricted_pages(): void
    {
        $this->actingAs($this->worker);

        // Dashboard redirects to Kanban
        $response = $this->get('/dashboard');
        $response->assertRedirect(route('kanban.index'));

        // Orders redirects to Kanban
        $response = $this->get('/orders');
        $response->assertRedirect(route('kanban.index'));

        // Customers redirects to Kanban
        $response = $this->get('/customers');
        $response->assertRedirect(route('kanban.index'));

        // Products redirects to Kanban
        $response = $this->get('/products');
        $response->assertRedirect(route('kanban.index'));

        // Suppliers redirects to Kanban
        $response = $this->get('/suppliers');
        $response->assertRedirect(route('kanban.index'));

        // Workflows redirects to Kanban
        $response = $this->get('/workflows');
        $response->assertRedirect(route('kanban.index'));
    }

    /**
     * Test worker can view raw materials in consultation mode but cannot mutate.
     */
    public function test_worker_can_consult_raw_materials_but_cannot_mutate(): void
    {
        $this->actingAs($this->worker);

        // Can view index
        $response = $this->get('/raw-materials');
        $response->assertStatus(200);

        // Cannot create raw material
        $postResponse = $this->post('/raw-materials', [
            'name' => 'Intento Prohibido',
            'unit_of_measure' => 'metro',
            'current_stock' => 10,
            'min_stock_alert' => 2,
            'unit_cost' => 5000,
        ]);
        $postResponse->assertStatus(403);

        // Cannot adjust stock
        $material = RawMaterial::firstOrFail();
        $adjustResponse = $this->post("/raw-materials/{$material->id}/movements", [
            'type' => 'adjustment',
            'quantity' => 5,
            'direction' => 'add',
        ]);
        $adjustResponse->assertStatus(403);
    }

    /**
     * Test worker can submit material requests to the CEO.
     */
    public function test_worker_can_submit_material_request_and_owner_can_approve(): void
    {
        $this->actingAs($this->worker);
        $material = RawMaterial::firstOrFail();

        // Worker creates request for existing material
        $response = $this->post('/material-requests', [
            'type' => 'existing',
            'raw_material_id' => $material->id,
            'requested_quantity' => 25.5,
            'notes' => 'Urgente para pedido del viernes',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('material_requests', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->worker->id,
            'raw_material_id' => $material->id,
            'requested_quantity' => '25.50',
            'status' => 'pending',
            'notes' => 'Urgente para pedido del viernes',
        ]);

        $requestRecord = MaterialRequest::where('user_id', $this->worker->id)->latest()->firstOrFail();

        // Worker cannot approve the request
        $workerPatch = $this->patch("/material-requests/{$requestRecord->id}", [
            'status' => 'approved',
        ]);
        $workerPatch->assertStatus(403);

        // Owner can approve
        $this->actingAs($this->owner);
        $ownerPatch = $this->patch("/material-requests/{$requestRecord->id}", [
            'status' => 'approved',
        ]);
        $ownerPatch->assertRedirect();
        $ownerPatch->assertSessionHas('success');

        $this->assertSame('approved', $requestRecord->fresh()->status);
    }

    /**
     * Test worker can submit new material request not in catalog.
     */
    public function test_worker_can_submit_new_material_request(): void
    {
        $this->actingAs($this->worker);

        $response = $this->post('/material-requests', [
            'type' => 'new',
            'new_material_name' => 'Cremallera Reforzada 50cm',
            'requested_quantity' => 10,
            'unit' => 'unidades',
            'notes' => 'Insumo nuevo requerido para cliente especial',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('material_requests', [
            'user_id' => $this->worker->id,
            'new_material_name' => 'Cremallera Reforzada 50cm',
            'unit' => 'unidades',
            'status' => 'pending',
        ]);
    }

    /**
     * Test super admin can view next cut and extend trial for tenant.
     */
    public function test_super_admin_can_extend_trial(): void
    {
        $this->actingAs($this->admin);

        // Set tenant trial to expired in the past
        $this->tenant->update([
            'trial_ends_at' => now()->subDays(5),
            'subscription_status' => 'expired',
        ]);

        $this->assertTrue($this->tenant->fresh()->isTrialExpired());

        // Super Admin extends by 15 days
        $response = $this->post("/admin/tenants/{$this->tenant->id}/extend-trial", [
            'days' => 15,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $fresh = $this->tenant->fresh();
        $this->assertSame('trialing', $fresh->subscription_status);
        $this->assertTrue($fresh->trial_ends_at->isFuture());
        $this->assertFalse($fresh->isTrialExpired());
    }

    /**
     * Test disabled tenant gets charged immediately upon registering payment method.
     */
    public function test_disabled_tenant_charged_immediately_and_activated(): void
    {
        $this->actingAs($this->owner);

        // Simulate expired trial and past due status
        $this->tenant->update([
            'trial_ends_at' => now()->subDays(3),
            'subscription_status' => 'past_due',
            'payment_method_token' => null,
        ]);

        $this->assertTrue($this->tenant->isTrialExpired());

        $response = $this->post('/settings/billing/payment-method', [
            'card_number' => '4111 1111 1111 1111',
            'cardholder_name' => 'Carlos Mendoza',
            'expiration_month' => '12',
            'expiration_year' => '2028',
            'cvv' => '123',
        ]);

        $response->assertRedirect(route('settings.billing'));
        $response->assertSessionHas('success');

        $fresh = $this->tenant->fresh();
        $this->assertSame('active', $fresh->subscription_status);
        $this->assertNotNull($fresh->payment_method_token);
        $this->assertNotNull($fresh->current_period_ends_at);
        $this->assertTrue($fresh->current_period_ends_at->isFuture());

        // Verify billing history receipt was registered
        $history = $fresh->settings['billing_history'] ?? [];
        $this->assertNotEmpty($history);
        $this->assertSame('Pagado', $history[0]['status']);
    }
}

