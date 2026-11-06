<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BoldSubscriptionAndTrialLockTest extends TestCase
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
     * Test Luhn algorithm validation in BoldPaymentService.
     */
    public function test_bold_service_validates_luhn_algorithm(): void
    {
        // Tarjeta Visa válida (pasa Luhn)
        $this->assertTrue($this->boldService->validateLuhn('4111111111111111'));

        // Tarjeta inválida (falla Luhn)
        $this->assertFalse($this->boldService->validateLuhn('4111111111111112'));

        $this->expectException(ValidationException::class);
        $this->boldService->validateCard([
            'card_number' => '4111111111111112',
            'cardholder_name' => 'Carlos Mendoza',
            'expiration_month' => '12',
            'expiration_year' => '2028',
            'cvv' => '123',
        ]);
    }

    /**
     * Test recurring brands allowed and explicit rejection of non-recurring payment methods (PSE, Nequi, etc.).
     */
    public function test_bold_service_validates_recurring_brands_and_rejects_non_recurring(): void
    {
        // Rechazo explícito de PSE
        try {
            $this->boldService->validateCard([
                'payment_method_type' => 'pse',
                'card_number' => '4111111111111111',
                'cardholder_name' => 'Carlos Mendoza',
                'expiration_month' => '12',
                'expiration_year' => '2028',
                'cvv' => '123',
            ]);
            $this->fail('Se esperaba ValidationException por método no recurrente PSE.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method_type', $e->errors());
        }

        // Rechazo explícito de Efectivo
        try {
            $this->boldService->validateCard([
                'payment_method_type' => 'efectivo',
                'card_number' => '4111111111111111',
                'cardholder_name' => 'Carlos Mendoza',
                'expiration_month' => '12',
                'expiration_year' => '2028',
                'cvv' => '123',
            ]);
            $this->fail('Se esperaba ValidationException por método no recurrente Efectivo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method_type', $e->errors());
        }

        // Detección y aprobación de Visa
        $validatedVisa = $this->boldService->validateCard([
            'card_number' => '4111111111111111',
            'cardholder_name' => 'Carlos Mendoza',
            'expiration_month' => '12',
            'expiration_year' => '2028',
            'cvv' => '123',
        ]);
        $this->assertSame('Visa', $validatedVisa['brand_label']);
        $this->assertSame('1111', $validatedVisa['last_four']);

        // Detección y aprobación de Mastercard
        $validatedMastercard = $this->boldService->validateCard([
            'card_number' => '5555555555554444',
            'cardholder_name' => 'Ana Gomez',
            'expiration_month' => '10',
            'expiration_year' => '2029',
            'cvv' => '321',
        ]);
        $this->assertSame('Mastercard', $validatedMastercard['brand_label']);
        $this->assertSame('4444', $validatedMastercard['last_four']);
    }

    /**
     * Test validation of future expiration date.
     */
    public function test_bold_service_validates_expiration_date_in_future(): void
    {
        $this->expectException(ValidationException::class);

        $this->boldService->validateCard([
            'card_number' => '4111111111111111',
            'cardholder_name' => 'Carlos Mendoza',
            'expiration_month' => '01',
            'expiration_year' => '2020', // Pasada
            'cvv' => '123',
        ]);
    }

    /**
     * Test tokenization of card via Bold service.
     */
    public function test_bold_service_tokenizes_card_successfully(): void
    {
        $validated = $this->boldService->validateCard([
            'card_number' => '4111 1111 1111 1111',
            'cardholder_name' => 'MARIO BENEDETTI',
            'expiration_month' => '11',
            'expiration_year' => '2028',
            'cvv' => '789',
        ]);

        $tokenized = $this->boldService->tokenizeCard($validated);

        $this->assertNotEmpty($tokenized['payment_method_token']);
        $this->assertStringStartsWith('bld_', $tokenized['payment_method_token']);
        $this->assertSame('Visa', $tokenized['card_brand']);
        $this->assertSame('1111', $tokenized['card_last_four']);
    }

    /**
     * Test registration without card creates 15-day trial and null token.
     */
    public function test_registration_without_card_stores_15_day_trial(): void
    {
        $response = $this->post('/register', [
            'owner_name' => 'Laura Dueña',
            'workshop_name' => 'Taller Artesanal',
            'email' => 'laura@artesanal.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'plan' => 'standard',
        ]);

        $response->assertRedirect('/dashboard');

        $tenant = Tenant::where('name', 'Taller Artesanal')->firstOrFail();
        $this->assertSame('trialing', $tenant->subscription_status);
        $this->assertSame('standard', $tenant->plan_type);
        $this->assertNull($tenant->payment_method_token);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->isTrialActive());
        $this->assertFalse($tenant->isTrialExpired());
        $this->assertGreaterThan(13, $tenant->trialDaysRemaining());
    }

    /**
     * Test registration with optional card tokenizes and attaches to tenant.
     */
    public function test_registration_with_optional_card_stores_token_and_brand(): void
    {
        $response = $this->post('/register', [
            'owner_name' => 'Esteban Dueño',
            'workshop_name' => 'Taller Calzado Pro',
            'email' => 'esteban@calzado.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'plan' => 'pro',
            'with_card' => true,
            'card_number' => '4111111111111111',
            'cardholder_name' => 'Esteban Gomez',
            'expiration_month' => '12',
            'expiration_year' => '2028',
            'cvv' => '456',
        ]);

        $response->assertRedirect('/dashboard');

        $tenant = Tenant::where('name', 'Taller Calzado Pro')->firstOrFail();
        $this->assertSame('pro', $tenant->plan_type);
        $this->assertSame('trialing', $tenant->subscription_status);
        $this->assertNotNull($tenant->payment_method_token);
        $this->assertSame('Visa', $tenant->card_brand);
        $this->assertSame('1111', $tenant->card_last_four);
    }

    /**
     * Test active trial allows access to dashboard and orders without card.
     */
    public function test_tenant_in_active_trial_can_access_dashboard_and_orders(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Activo',
            'slug' => 'taller-activo',
            'trial_ends_at' => now()->addDays(10),
            'subscription_status' => 'trialing',
        ]);

        $user = User::factory()->create([
            'current_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertOk();

        $responseOrders = $this->actingAs($user)->get('/orders');
        $responseOrders->assertOk();
    }

    /**
     * Test expired trial without card blocks access and redirects to /settings/billing.
     */
    public function test_tenant_with_expired_trial_and_no_card_is_redirected_to_billing(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Vencido',
            'slug' => 'taller-vencido',
            'trial_ends_at' => now()->subDay(), // Expiró ayer
            'subscription_status' => 'trialing',
            'payment_method_token' => null,
        ]);

        $user = User::factory()->create([
            'current_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertRedirect('/settings/billing');
        $response->assertSessionHas('error');

        $responseKanban = $this->actingAs($user)->get('/kanban');
        $responseKanban->assertRedirect('/settings/billing');
    }

    /**
     * Test billing page is accessible even when trial is expired.
     */
    public function test_tenant_with_expired_trial_can_access_billing_page_and_update_card(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Vencido Billing',
            'slug' => 'taller-vencido-billing',
            'trial_ends_at' => now()->subDays(2),
            'subscription_status' => 'trialing',
            'payment_method_token' => null,
        ]);

        $user = User::factory()->create([
            'current_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Puede acceder a la pantalla de facturación para resolver el bloqueo
        $responseBilling = $this->actingAs($user)->get('/settings/billing');
        $responseBilling->assertOk();

        // Registra su tarjeta
        $responseUpdate = $this->actingAs($user)->post('/settings/billing/payment-method', [
            'card_number' => '4111111111111111',
            'cardholder_name' => 'Laura Duena',
            'expiration_month' => '12',
            'expiration_year' => '2028',
            'cvv' => '999',
        ]);

        $responseUpdate->assertRedirect('/settings/billing');
        $responseUpdate->assertSessionHas('success');

        $tenant->refresh();
        $this->assertSame('active', $tenant->subscription_status);
        $this->assertNotNull($tenant->payment_method_token);
        $this->assertSame('1111', $tenant->card_last_four);

        // Ahora el acceso al dashboard queda completamente desbloqueado
        $responseDashboard = $this->actingAs($user)->get('/dashboard');
        $responseDashboard->assertOk();
    }

    /**
     * Test superadmin bypasses trial expiration check.
     */
    public function test_superadmin_bypasses_trial_expiration_check(): void
    {
        $tenant = Tenant::create([
            'name' => 'Taller Expirado Admin',
            'slug' => 'taller-expirado-admin',
            'trial_ends_at' => now()->subDays(5),
            'subscription_status' => 'trialing',
            'payment_method_token' => null,
        ]);

        $admin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);
        $admin->tenants()->attach($tenant->id, ['role' => 'owner']);

        $response = $this->actingAs($admin)->get('/dashboard');
        $response->assertOk();
    }
}
