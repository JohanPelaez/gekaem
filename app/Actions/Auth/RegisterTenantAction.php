<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterTenantAction
{
    /**
     * Register a new Tenant with default settings, create the owner User,
     * seed default workflow/stages/statuses, and link the owner.
     *
     * @param array{
     *     owner_name: string,
     *     workshop_name: string,
     *     email: string,
     *     password: string,
     *     plan?: string|null
     * } $data
     * @return array{0: User, 1: Tenant}
     */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $isPro = ($data['plan'] ?? 'standard') === 'pro';

            $workshopName = trim($data['workshop_name']);
            $baseSlug = Str::slug($workshopName);
            if (empty($baseSlug)) {
                $baseSlug = 'taller';
            }

            $slug = $baseSlug . '-' . Str::lower(Str::random(4));
            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . Str::lower(Str::random(4));
            }

            $settings = [
                'currency' => 'COP',
                'parallel_workers' => $isPro ? 5 : 1,
                'workdays' => [1, 2, 3, 4, 5, 6],
                'plan' => $isPro ? 'pro' : 'standard',
                'plan_name' => $isPro ? 'Plan Taller Pro' : 'Plan Estándar',
                'trial_ends_at' => now()->addDays(15)->toIso8601String(),
                'trial_active' => true,
            ];

            /** @var Tenant $tenant */
            $tenant = Tenant::create([
                'name' => $workshopName,
                'slug' => $slug,
                'daily_productive_minutes' => 480,
                'default_buffer_percent' => 20,
                'settings' => $settings,
            ]);

            // Inicializar estados Kanban, Flujo de producción y Unidades de medida
            Tenant::ensureDefaultData($tenant->id);

            /** @var User $user */
            $user = User::create([
                'name' => trim($data['owner_name']),
                'email' => Str::lower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'is_super_admin' => false,
                'current_tenant_id' => $tenant->id,
            ]);

            $user->tenants()->attach($tenant->id, [
                'role' => 'owner',
            ]);

            return [$user, $tenant];
        });
    }
}

