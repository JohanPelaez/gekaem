<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->where('initialPlan', 'standard')
        );
    }

    public function test_registration_screen_detects_pro_plan_from_query(): void
    {
        $response = $this->get('/register?plan=pro');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->where('initialPlan', 'pro')
        );
    }

    public function test_authenticated_user_is_redirected_to_dashboard_from_register(): void
    {
        $user = User::where('email', 'dueno@gekaem.test')->firstOrFail();

        $response = $this->actingAs($user)->get('/register');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_new_owner_can_register_tenant_with_standard_plan(): void
    {
        $response = $this->post('/register', [
            'owner_name' => 'Carlos Rodríguez',
            'workshop_name' => 'Taller de Confección Alfa',
            'email' => 'carlos@tallercreativo.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'plan' => 'standard',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        /** @var User $user */
        $user = auth()->user();
        $this->assertEquals('Carlos Rodríguez', $user->name);
        $this->assertEquals('carlos@tallercreativo.test', $user->email);

        /** @var Tenant $tenant */
        $tenant = Tenant::where('name', 'Taller de Confección Alfa')->first();
        $this->assertNotNull($tenant);
        $this->assertStringStartsWith('taller-de-confeccion-alfa-', $tenant->slug);
        $this->assertEquals($tenant->id, $user->current_tenant_id);

        // Verificar vinculación como Dueño
        $this->assertTrue($user->isOwnerOf($tenant));
        $this->assertEquals('owner', $user->currentRole());

        // Verificar configuración inicial del Tenant
        $this->assertEquals(480, $tenant->daily_productive_minutes);
        $this->assertEquals(20, $tenant->default_buffer_percent);
        $this->assertEquals('COP', $tenant->settings['currency'] ?? null);
        $this->assertEquals(1, $tenant->settings['parallel_workers'] ?? null);
        $this->assertEquals('standard', $tenant->settings['plan'] ?? null);

        // Verificar datos por defecto (ensureDefaultData)
        $this->assertGreaterThanOrEqual(4, OrderStatus::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThanOrEqual(1, Workflow::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThanOrEqual(1, UnitOfMeasure::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }

    public function test_new_owner_can_register_tenant_with_pro_plan(): void
    {
        $response = $this->post('/register', [
            'owner_name' => 'María Directora',
            'workshop_name' => 'Marroquinería Pro Alta Gama',
            'email' => 'maria@marroquineria.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'plan' => 'pro',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        /** @var Tenant $tenant */
        $tenant = Tenant::where('name', 'Marroquinería Pro Alta Gama')->first();
        $this->assertNotNull($tenant);
        $this->assertEquals(5, $tenant->settings['parallel_workers'] ?? null);
        $this->assertEquals('pro', $tenant->settings['plan'] ?? null);
    }

    public function test_registration_validation_errors(): void
    {
        $response = $this->post('/register', [
            'owner_name' => '',
            'workshop_name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors([
            'owner_name',
            'workshop_name',
            'email',
            'password',
        ]);

        $this->assertGuest();
    }

    public function test_register_in_local_environment_does_not_automatically_log_in_user(): void
    {
        $originalEnv = app()['env'];
        app()['env'] = 'local';

        try {
            $response = $this->get('/register');

            $response->assertStatus(200);
            $response->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
            );
            $this->assertGuest();
        } finally {
            app()['env'] = $originalEnv;
        }
    }

    public function test_login_in_local_environment_does_not_automatically_log_in_user(): void
    {
        $originalEnv = app()['env'];
        app()['env'] = 'local';

        try {
            $response = $this->get('/login');

            $response->assertStatus(200);
            $response->assertInertia(fn ($page) => $page
                ->component('Auth/Login')
            );
            $this->assertGuest();
        } finally {
            app()['env'] = $originalEnv;
        }
    }
}
