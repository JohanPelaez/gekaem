<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Team\AddTeamMemberAction;
use App\Models\Tenant;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpgradeTenantPlanAction
{
    public function __construct(
        protected BoldPaymentService $boldService,
        protected AddTeamMemberAction $addMemberAction
    ) {}

    /**
     * Upgrade tenant to Plan Taller Pro ($75.000 COP with 5 seats),
     * charging only the prorated/cycle difference if active, and optionally adding a member.
     *
     * @param array{
     *     name?: string|null,
     *     email?: string|null,
     *     password?: string|null,
     *     role?: string|null
     * }|null $newMemberData
     * @throws ValidationException
     */
    public function execute(Tenant $tenant, ?array $newMemberData = null): Tenant
    {
        if ($tenant->plan_type === 'pro') {
            if (!empty($newMemberData['email'])) {
                $this->addMemberAction->execute($newMemberData, $tenant);
            }
            return $tenant->refresh();
        }

        // Si la suscripción está activa, cobrar la diferencia del ciclo hacia Plan Profesional
        if ($tenant->subscription_status === 'active') {
            if (!$tenant->hasValidPaymentMethod()) {
                throw ValidationException::withMessages([
                    'plan' => 'Debes registrar un método de pago válido antes de cambiar al Plan Profesional.',
                ]);
            }

            $currentTotal = $tenant->calculateSubscriptionTotal('basic');
            $proTotal = $tenant->calculateSubscriptionTotal('pro');
            $difference = max(0, $proTotal - $currentTotal);

            if ($difference > 0) {
                $this->boldService->chargeToken(
                    $tenant,
                    $difference,
                    'Upgrade a Plan Profesional (Diferencia de Ciclo)'
                );
            }
        }

        DB::transaction(function () use ($tenant): void {
            $settings = $tenant->settings ?? [];
            $settings['plan'] = 'pro';
            $settings['plan_name'] = 'Plan Profesional';
            $settings['parallel_workers'] = max(3, $settings['parallel_workers'] ?? 1);

            $extraSeats = (int) ($tenant->extra_ceo_seats ?? 0) + (int) ($tenant->extra_worker_seats ?? 0);

            $tenant->update([
                'plan_type' => 'pro',
                'paid_seats' => max(4 + $extraSeats, (int) $tenant->paid_seats),
                'settings' => $settings,
            ]);
        });

        $tenant->refresh();

        // Si venía un nuevo usuario para registrar en el mismo flujo
        if (!empty($newMemberData['email'])) {
            $this->addMemberAction->execute($newMemberData, $tenant);
        }

        return $tenant->refresh();
    }
}

