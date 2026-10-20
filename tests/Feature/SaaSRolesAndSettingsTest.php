<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaaSRolesAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_login_screen_loads_successfully(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->has('canDemoLogin')
        );
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'dueno@gekaem.test',
            'password' => 'password',
        ]);

        $response->assertRedirect('/kanban');
        $this->assertAuthenticated();
    }

    public function test_demo_login_logs_in_appropriate_user(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $originalEnv = app()['env'];
        app()['env'] = 'local';

        $response = $this->post('/demo-login', [
            'role' => 'admin',
        ]);

        $response->assertRedirect('/admin');
        $this->assertAuthenticated();
        /** @var User $user */
        $user = auth()->user();
        $this->assertTrue($user->isSuperAdmin());
        $response->assertSessionHas('tenant_id');

        $this->post('/logout');

        // Test Demo login as Owner
        $ownerResponse = $this->post('/demo-login', [
            'role' => 'owner',
        ]);
        $ownerResponse->assertRedirect('/kanban');
        $this->assertAuthenticated();
        /** @var User $owner */
        $owner = auth()->user();
        $this->assertSame('owner', $owner->currentRole());
        $ownerResponse->assertSessionHas('tenant_id');

        $this->post('/logout');

        // Test Demo login as Worker
        $workerResponse = $this->post('/demo-login', [
            'role' => 'worker',
        ]);
        $workerResponse->assertRedirect('/kanban');
        $this->assertAuthenticated();
        /** @var User $worker */
        $worker = auth()->user();
        $this->assertSame('worker', $worker->currentRole());
        $workerResponse->assertSessionHas('tenant_id');

        app()['env'] = $originalEnv;
    }

    public function test_demo_login_aborts_404_when_not_in_local_environment(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $originalEnv = app()['env'];
        app()['env'] = 'production';

        $response = $this->post('/demo-login', [
            'role' => 'admin',
        ]);

        $response->assertStatus(404);

        app()['env'] = $originalEnv;
    }

    public function test_user_can_logout(): void
    {
        $user = User::where('email', 'dueno@gekaem.test')->firstOrFail();
        $this->actingAs($user);

        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_superadmin_can_access_admin_panel(): void
    {
        $admin = User::where('email', 'admin@gekaem.test')->firstOrFail();
        $this->actingAs($admin);

        $response = $this->get('/admin');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Index')
            ->has('tenants')
            ->has('users')
        );
    }

    public function test_non_admin_cannot_access_admin_panel(): void
    {
        $dueno = User::where('email', 'dueno@gekaem.test')->firstOrFail();
        $this->actingAs($dueno);

        $response = $this->get('/admin');

        $response->assertStatus(403);
    }

    public function test_superadmin_can_create_user_and_new_tenant(): void
    {
        $admin = User::where('email', 'admin@gekaem.test')->firstOrFail();
        $this->actingAs($admin);

        $response = $this->post('/admin/users', [
            'name' => 'Nuevo Emprendedor Beta',
            'email' => 'beta@emprendedor.com',
            'password' => 'password123',
            'role_type' => 'owner',
            'tenant_mode' => 'new',
            'new_tenant_name' => 'Calzado Fino Beta',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'beta@emprendedor.com']);
        $this->assertDatabaseHas('tenants', ['name' => 'Calzado Fino Beta']);
    }

    public function test_superadmin_can_switch_tenant(): void
    {
        $admin = User::where('email', 'admin@gekaem.test')->firstOrFail();
        $this->actingAs($admin);

        $tenant2 = Tenant::where('slug', 'carpinteria-artesanal-roble')->firstOrFail();

        $response = $this->post("/admin/switch-tenant/{$tenant2->id}");

        $response->assertRedirect('/kanban');
        $admin->refresh();
        $this->assertSame($tenant2->id, $admin->current_tenant_id);
    }

    public function test_owner_can_access_settings(): void
    {
        $dueno = User::where('email', 'dueno@gekaem.test')->firstOrFail();
        $this->actingAs($dueno);

        $response = $this->get('/settings');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Index')
            ->has('tenant')
            ->has('teamMembers')
        );
    }

    public function test_worker_cannot_access_settings(): void
    {
        $worker = User::where('email', 'trabajador@gekaem.test')->firstOrFail();
        $this->actingAs($worker);

        $response = $this->get('/settings');

        $response->assertStatus(403);
    }

    public function test_owner_can_update_settings_and_hours_convert_to_minutes(): void
    {
        $dueno = User::where('email', 'dueno@gekaem.test')->firstOrFail();
        $this->actingAs($dueno);

        $tenant = $dueno->currentTenant();

        $response = $this->patch('/settings', [
            'name' => 'Taller Creativo Renovado',
            'daily_hours' => 9.5,
            'default_buffer_percent' => 25,
            'parallel_workers' => 3,
            'workdays' => [1, 2, 3, 4, 5],
            'currency' => 'USD',
        ]);

        $response->assertRedirect();
        $tenant->refresh();

        $this->assertSame('Taller Creativo Renovado', $tenant->name);
        $this->assertSame(570, (int) $tenant->daily_productive_minutes); // 9.5 * 60 = 570
        $this->assertSame(25, (int) $tenant->default_buffer_percent);
        $this->assertSame('USD', $tenant->settings['currency']);
    }

    public function test_owner_can_add_and_remove_member(): void
    {
        $dueno = User::where('email', 'dueno@gekaem.test')->firstOrFail();
        $this->actingAs($dueno);

        $tenant = $dueno->currentTenant();

        $response = $this->post('/settings/members', [
            'name' => 'Nuevo Operario',
            'email' => 'operario.nuevo@gekaem.test',
            'password' => 'secret123',
            'role' => 'worker',
        ]);

        $response->assertRedirect();
        $newMember = User::where('email', 'operario.nuevo@gekaem.test')->firstOrFail();
        $this->assertTrue($tenant->users()->where('users.id', $newMember->id)->exists());

        // Remove member
        $deleteResponse = $this->delete("/settings/members/{$newMember->id}");
        $deleteResponse->assertRedirect();
        $this->assertFalse($tenant->fresh()->users()->where('users.id', $newMember->id)->exists());
    }
}
