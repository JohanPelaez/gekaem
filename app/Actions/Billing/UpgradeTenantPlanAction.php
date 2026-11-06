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

        // Si la suscripción está activa, cobrar la diferencia restante hasta los 75.000 COP
        if ($tenant->subscription_status === 'active') {
            if (!$tenant->hasValidPaymentMethod()) {
                throw ValidationException::withMessages([
                    'plan' => 'Debes registrar un método de pago válido antes de cambiar al Plan Taller Pro.',
                ]);
            }

            $currentPaid = 30000 + (max(0, ((int) $tenant->paid_seats) - 1) * 15000);
            $difference = max(0, 75000 - $currentPaid);

            if ($difference > 0) {
                $this->boldService->chargeToken(
                    $tenant,
                    $difference,
                    "Upgrade a Plan Taller Pro (Diferencia de Ciclo)"
                );
            }
        }

        DB::transaction(function () use ($tenant): void {
            $settings = $tenant->settings ?? [];
            $settings['plan'] = 'pro';
            $settings['plan_name'] = 'Plan Taller Pro';
            $settings['parallel_workers'] = max(5, $settings['parallel_workers'] ?? 1);

            $tenant->update([
                'plan_type' => 'pro',
                'paid_seats' => max(5, (int) $tenant->paid_seats),
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

