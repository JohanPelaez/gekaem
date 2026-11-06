<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaidSeatsAndExtraUserBillingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected BoldPaymentService $boldService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boldService = app(BoldPaymentService::class);
    }

    /**
     * Test Tenant model seat calculation methods.
     */
    public function test_tenant_seat_calculation_helpers(): void
    {
        $tenantStandard = Tenant::create([
            'name' => 'Taller Estándar Seats',
            'slug' => 'taller-estandar-seats',
            'plan_type' => 'standard',
            'paid_seats' => 1,
            'subscription_status' => 'active',
        ]);

        $this->assertSame(1, $tenantStandard->includedBaseSeats());
        $this->assertSame(30000, $tenantStandard->calculateMonthlyTotal(1));
        $this->assertSame(45000, $tenantStandard->calculateMonthlyTotal(2));
        $this->assertSame(60000, $tenantStandard->calculateMonthlyTotal(3));
        $this->assertSame(90000, $tenantStandard->calculateMonthlyTotal(5));

        $tenantPro = Tenant::create([
            'name' => 'Taller Pro Seats',
            'slug' => 'taller-pro-seats',
            'plan_type' => 'pro',
            'paid_seats' => 5,
            'subscription_status' => 'active',
        ]);

        $this->assertSame(5, $tenantPro->includedBaseSeats());
        $this->assertSame(75000, $tenantPro->calculateMonthlyTotal(1));
        $this->assertSame(75000, $tenantPro->calculateMonthlyTotal(5));
        $this->assertSame(90000, $tenantPro->calculateMonthlyTotal(6));
        $this->assertSame(105000, $tenantPro->calculateMonthlyTotal(7));
    }

    /**
     * Test Case A: Trialing workshop allows up to 5 members with $0 charge, but rejects 6th.
     */
    public function test_case_a_trialing_allows_up_to_5_members_and_rejects_6th(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Trialing',
            'slug' => 'taller-trialing',
            'plan_type' => 'standard',
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $owner = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Agregar 4 miembros (total 5 con el dueño)
        for ($i = 1; $i <= 4; $i++) {
            $response = $this->actingAs($owner)->post('/settings/members', [
                'name' => "Trabajador {$i}",
                'email' => "trabajador{$i}@trial.com",
                'password' => 'secret123',
                'role' => 'worker',
            ]);
            $response->assertRedirect();
            $response->assertSessionHas('success');
        }

        $this->assertSame(5, $tenant->activeUsersCount());

        // Intentar agregar el 6º usuario en prueba gratis -> debe ser rechazado
        $response6 = $this->actingAs($owner)->post('/settings/members', [
            'name' => 'Trabajador 6',
            'email' => 'trabajador6@trial.com',
            'password' => 'secret123',
            'role' => 'worker',
        ]);

        $response6->assertSessionHasErrors(['email']);
        $this->assertSame(5, $tenant->activeUsersCount());
    }

    /**
     * Test Case B: Active subscription with available covered seats adds user for $0.
     */
    public function test_case_b_active_subscription_with_available_seats_adds_user_for_free(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Pro Active',
            'slug' => 'taller-pro-active',
            'plan_type' => 'pro',
            'paid_seats' => 5,
            'subscription_status' => 'active',
            'payment_method_token' => 'bld_tok_test123',
        ]);

        $owner = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        $this->assertSame(1, $tenant->activeUsersCount());
        $this->assertSame(4, $tenant->availableSeats());

        // Agregar segundo usuario (cubierto por los 5 base del plan Pro)
        $response = $this->actingAs($owner)->post('/settings/members', [
            'name' => 'Operario Pro',
            'email' => 'operario@pro.com',
            'password' => 'secret123',
            'role' => 'worker',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $tenant->refresh();
        $this->assertSame(2, $tenant->activeUsersCount());
        $this->assertSame(5, $tenant->paid_seats); // No requirió incremento ni cobro
    }

    /**
     * Test Case C: Active subscription without seats charges $15.000 COP via Bold and increments paid_seats.
     */
    public function test_case_c_active_subscription_charges_15000_cop_for_extra_seat(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Estándar Activo',
            'slug' => 'taller-estandar-activo',
            'plan_type' => 'standard',
            'paid_seats' => 1,
            'subscription_status' => 'active',
            'payment_method_token' => 'bld_tok_card4242',
            'card_brand' => 'Visa',
            'card_last_four' => '4242',
        ]);

        $owner = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        $this->assertSame(1, $tenant->activeUsersCount());
        $this->assertTrue($tenant->requiresImmediateExtraSeatCharge());

        // Agregar usuario adicional
        $response = $this->actingAs($owner)->post('/settings/members', [
            'name' => 'Primer Operario Extra',
            'email' => 'operario.extra@taller.com',
            'password' => 'secret123',
            'role' => 'worker',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $tenant->refresh();
        $this->assertSame(2, $tenant->activeUsersCount());
        $this->assertSame(2, $tenant->paid_seats); // Incrementado en +1

        // Verificar que se registró el recibo en el historial de facturación
        $history = $tenant->settings['billing_history'] ?? [];
        $this->assertNotEmpty($history);
        $this->assertSame(15000, $history[0]['amount']);
        $this->assertStringContainsString('Cupo Usuario Extra #2', $history[0]['description']);
    }

    /**
     * Test Case C error: Active subscription without payment method cannot add extra seats.
     */
    public function test_case_c_rejects_extra_seat_if_no_payment_method_token(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Sin Tarjeta',
            'slug' => 'taller-sin-tarjeta',
            'plan_type' => 'standard',
            'paid_seats' => 1,
            'subscription_status' => 'active',
            'payment_method_token' => null, // Sin tarjeta
        ]);

        $owner = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        $response = $this->actingAs($owner)->post('/settings/members', [
            'name' => 'Intento Extra',
            'email' => 'intento@taller.com',
            'password' => 'secret123',
            'role' => 'worker',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertSame(1, $tenant->activeUsersCount());
    }

    /**
     * Test Case D: Removing member decreases active users but preserves paid_seats for the rest of the cycle.
     */
    public function test_case_d_removing_member_preserves_paid_seats(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Reemplazo',
            'slug' => 'taller-reemplazo',
            'plan_type' => 'standard',
            'paid_seats' => 2,
            'subscription_status' => 'active',
            'payment_method_token' => 'bld_tok_valid',
        ]);

        $owner = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        $worker = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $worker->tenants()->attach($tenant->id, ['role' => 'worker']);

        $this->assertSame(2, $tenant->activeUsersCount());
        $this->assertSame(2, $tenant->paid_seats);

        // Eliminar trabajador
        $response = $this->actingAs($owner)->delete("/settings/members/{$worker->id}");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $tenant->refresh();
        $this->assertSame(1, $tenant->activeUsersCount());
        $this->assertSame(2, $tenant->paid_seats); // Se mantiene para no cobrar doble al reemplazar

        // Agregar reemplazo inmediatamente -> no requiere cobro extra porque paid_seats = 2
        $responseReplace = $this->actingAs($owner)->post('/settings/members', [
            'name' => 'Nuevo Reemplazo',
            'email' => 'reemplazo@taller.com',
            'password' => 'secret123',
            'role' => 'worker',
        ]);

        $responseReplace->assertRedirect();
        $tenant->refresh();
        $this->assertSame(2, $tenant->activeUsersCount());
        $this->assertSame(2, $tenant->paid_seats);
    }

    /**
     * Test intelligent upgrade to Plan Taller Pro via /settings/billing/upgrade.
     */
    public function test_intelligent_upgrade_to_pro_plan(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Upgrade',
            'slug' => 'taller-upgrade',
            'plan_type' => 'standard',
            'paid_seats' => 1,
            'subscription_status' => 'active',
            'payment_method_token' => 'bld_tok_card999',
            'card_brand' => 'Mastercard',
            'card_last_four' => '9999',
        ]);

        $owner = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Cambiar a Pro
        $response = $this->actingAs($owner)->post('/settings/billing/upgrade', [
            'name' => 'Nuevo Miembro Con Pro',
            'email' => 'nuevo.pro@taller.com',
            'password' => 'secret123',
            'role' => 'worker',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $tenant->refresh();
        $this->assertSame('pro', $tenant->plan_type);
        $this->assertSame(5, $tenant->paid_seats);
        $this->assertSame(2, $tenant->activeUsersCount());

        // Verificar recibo de upgrade en historial
        $history = $tenant->settings['billing_history'] ?? [];
        $this->assertNotEmpty($history);
        $this->assertSame(45000, $history[0]['amount']); // 75.000 - 30.000 = 45.000 COP diferencia
        $this->assertStringContainsString('Upgrade a Plan Taller Pro', $history[0]['description']);
    }
}

