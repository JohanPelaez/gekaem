<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeSubscriptionAction
{
    public function __construct(
        protected BoldPaymentService $boldService
    ) {}

    /**
     * Change tenant plan (basic <-> pro) and/or billing cycle (monthly <-> annual)
     * with safe downgrade quota validation and Bold cycle/proration handling.
     *
     * @return array{
     *     tenant: Tenant,
     *     charged_immediately: bool,
     *     amount_charged: int,
     *     message: string
     * }
     * @throws ValidationException
     */
    public function execute(Tenant $tenant, string $targetPlan, string $targetCycle): array
    {
        $normalizedPlan = $targetPlan === 'pro' ? 'pro' : 'basic';
        $normalizedCycle = $targetCycle === 'annual' ? 'annual' : 'monthly';

        $currentPlan = $tenant->normalizedPlanType();
        $currentCycle = $tenant->isAnnual() ? 'annual' : 'monthly';

        if ($normalizedPlan === $currentPlan && $normalizedCycle === $currentCycle) {
            return [
                'tenant' => $tenant,
                'charged_immediately' => false,
                'amount_charged' => 0,
                'message' => 'Tu taller ya se encuentra en este plan y ciclo de facturación.',
            ];
        }

        // Validación segura al bajar de Profesional a Básico
        if ($normalizedPlan === 'basic' && $currentPlan === 'pro') {
            $this->validateDowngradeToBasic($tenant);
        }

        $planConfig = config("plans.plans.{$normalizedPlan}", config('plans.plans.basic', []));
        $planName = (string) ($planConfig['name'] ?? ($normalizedPlan === 'pro' ? 'Plan Profesional' : 'Plan Básico'));
        $cycleLabel = $normalizedCycle === 'annual' ? 'Anual (20% OFF)' : 'Mensual';

        $currentTotal = $tenant->calculateSubscriptionTotal($currentPlan, $currentCycle);
        $newTotal = $tenant->calculateSubscriptionTotal($normalizedPlan, $normalizedCycle);

        $chargedImmediately = false;
        $amountCharged = 0;

        if ($tenant->subscription_status === 'active' && $newTotal > $currentTotal) {
            if (!$tenant->hasValidPaymentMethod()) {
                throw ValidationException::withMessages([
                    'plan_type' => 'Debes registrar una tarjeta válida antes de actualizar tu plan o ciclo.',
                ]);
            }

            $difference = $newTotal - $currentTotal;
            $this->boldService->chargeToken(
                $tenant,
                $difference,
                "Cambio de suscripción a {$planName} · Ciclo {$cycleLabel}"
            );
            $chargedImmediately = true;
            $amountCharged = $difference;
        }

        DB::transaction(function () use ($tenant, $normalizedPlan, $normalizedCycle, $planName, $currentCycle): void {
            $settings = $tenant->settings ?? [];
            $settings['plan'] = $normalizedPlan;
            $settings['plan_name'] = $planName;
            $settings['billing_cycle'] = $normalizedCycle;

            $baseLimits = config("plans.plans.{$normalizedPlan}.limits", ['ceo' => 1, 'worker' => 0]);
            $baseSeats = (int) ($baseLimits['ceo'] ?? 1) + (int) ($baseLimits['worker'] ?? 0);
            $extraSeats = (int) ($tenant->extra_ceo_seats ?? 0) + (int) ($tenant->extra_worker_seats ?? 0);

            $updateData = [
                'plan_type' => $normalizedPlan,
                'billing_cycle' => $normalizedCycle,
                'paid_seats' => $baseSeats + $extraSeats,
                'settings' => $settings,
            ];

            if ($tenant->subscription_status === 'active' && ($normalizedCycle !== $currentCycle || $tenant->current_period_ends_at === null)) {
                $updateData['current_period_ends_at'] = $normalizedCycle === 'annual'
                    ? now()->addYear()
                    : now()->addMonth();
            }

            $tenant->update($updateData);
        });

        $formattedCharge = '$' . number_format($amountCharged, 0, ',', '.');
        $message = $chargedImmediately
            ? "¡Suscripción actualizada a {$planName} ({$cycleLabel})! Se procesó la diferencia de {$formattedCharge} COP en tu tarjeta."
            : "¡Suscripción actualizada a {$planName} ({$cycleLabel}) con éxito!";

        return [
            'tenant' => $tenant->fresh(),
            'charged_immediately' => $chargedImmediately,
            'amount_charged' => $amountCharged,
            'message' => $message,
        ];
    }

    /**
     * Ensure the tenant does not exceed Basic plan (+ active addons) quotas before downgrading.
     *
     * @throws ValidationException
     */
    protected function validateDowngradeToBasic(Tenant $tenant): void
    {
        $basicLimits = config('plans.plans.basic.limits', ['ceo' => 1, 'worker' => 0, 'workflows' => 2]);

        $extraCeo = (int) ($tenant->extra_ceo_seats ?? 0);
        $extraWorker = (int) ($tenant->extra_worker_seats ?? 0);
        $extraWorkflow = (int) ($tenant->extra_workflows ?? 0);

        $maxCeoInBasic = (int) ($basicLimits['ceo'] ?? 1) + $extraCeo;
        $maxWorkerInBasic = (int) ($basicLimits['worker'] ?? 0) + $extraWorker;
        $maxWorkflowInBasic = (int) ($basicLimits['workflows'] ?? 2) + $extraWorkflow;

        $usedCeo = $tenant->usedCeoSeats();
        $usedWorker = $tenant->usedWorkerSeats();
        $usedWorkflow = $tenant->usedWorkflowsCount();

        $issues = [];

        if ($usedWorker > $maxWorkerInBasic) {
            $excess = $usedWorker - $maxWorkerInBasic;
            $issues[] = "Tienes {$usedWorker} usuario(s) Operario activo(s) y en Plan Básico tendrías cupo para {$maxWorkerInBasic} (0 base + {$extraWorker} adicionales). Reduce {$excess} operario(s) o adquiere {$excess} adicional(es) de Operario.";
        }

        if ($usedWorkflow > $maxWorkflowInBasic) {
            $excess = $usedWorkflow - $maxWorkflowInBasic;
            $issues[] = "Tienes {$usedWorkflow} flujo(s) de trabajo activo(s) y en Plan Básico tendrías cupo para {$maxWorkflowInBasic} (2 base + {$extraWorkflow} adicionales). Reduce {$excess} flujo(s) o adquiere {$excess} adicional(es) de Flujo de Trabajo.";
        }

        if ($usedCeo > $maxCeoInBasic) {
            $excess = $usedCeo - $maxCeoInBasic;
            $issues[] = "Tienes {$usedCeo} usuario(s) CEO activo(s) y en Plan Básico tendrías cupo para {$maxCeoInBasic}. Reduce {$excess} usuario(s) CEO.";
        }

        if ($issues !== []) {
            throw ValidationException::withMessages([
                'plan_type' => implode(' ', $issues),
            ]);
        }
    }
}
