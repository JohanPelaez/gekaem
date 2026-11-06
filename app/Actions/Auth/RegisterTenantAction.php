<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterTenantAction
{
    public function __construct(
        protected BoldPaymentService $boldService
    ) {}

    /**
     * Register a new Tenant with default settings, create the owner User,
     * seed default workflow/stages/statuses, optionally tokenize card via Bold, and link the owner.
     *
     * @param array{
     *     owner_name: string,
     *     workshop_name: string,
     *     email: string,
     *     password: string,
     *     plan?: string|null,
     *     with_card?: bool|string|null,
     *     card_number?: string|null,
     *     cardholder_name?: string|null,
     *     expiration_month?: string|null,
     *     expiration_year?: string|null,
     *     cvv?: string|null,
     *     payment_method_type?: string|null
     * } $data
     * @return array{0: User, 1: Tenant}
     */
    public function execute(array $data): array
    {
        $tokenized = null;
        $hasCard = !empty($data['with_card']) || !empty($data['card_number']);

        if ($hasCard && !empty($data['card_number'])) {
            $validatedCard = $this->boldService->validateCard([
                'card_number' => (string) $data['card_number'],
                'cardholder_name' => (string) ($data['cardholder_name'] ?? $data['owner_name']),
                'expiration_month' => (string) ($data['expiration_month'] ?? ''),
                'expiration_year' => (string) ($data['expiration_year'] ?? ''),
                'cvv' => (string) ($data['cvv'] ?? ''),
                'payment_method_type' => (string) ($data['payment_method_type'] ?? ''),
            ]);

            $tokenized = $this->boldService->tokenizeCard($validatedCard);
        }

        return DB::transaction(function () use ($data, $tokenized): array {
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

            $trialEnds = now()->addDays(15);

            $settings = [
                'currency' => 'COP',
                'parallel_workers' => $isPro ? 5 : 1,
                'workdays' => [1, 2, 3, 4, 5, 6],
                'plan' => $isPro ? 'pro' : 'standard',
                'plan_name' => $isPro ? 'Plan Taller Pro' : 'Plan Estándar',
                'trial_ends_at' => $trialEnds->toIso8601String(),
                'trial_active' => true,
            ];

            /** @var Tenant $tenant */
            $tenant = Tenant::create([
                'name' => $workshopName,
                'slug' => $slug,
                'daily_productive_minutes' => 480,
                'default_buffer_percent' => 20,
                'settings' => $settings,
                'trial_ends_at' => $trialEnds,
                'subscription_status' => 'trialing',
                'plan_type' => $isPro ? 'pro' : 'standard',
                'payment_method_token' => $tokenized['payment_method_token'] ?? null,
                'bold_customer_id' => $tokenized['bold_customer_id'] ?? null,
                'card_brand' => $tokenized['card_brand'] ?? null,
                'card_last_four' => $tokenized['card_last_four'] ?? null,
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

