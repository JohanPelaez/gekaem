<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Billing\ChangeSubscriptionAction;
use App\Actions\Billing\PurchaseAddonAction;
use App\Actions\Billing\UpdatePaymentMethodAction;
use App\Actions\Billing\UpgradeTenantPlanAction;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    /**
     * Display the subscription and billing settings page.
     */
    public function billing(Request $request): Response|RedirectResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->user()->currentTenant();

        if ($tenant === null) {
            return redirect()->route('dashboard')->with('error', 'No tienes un taller activo asociado.');
        }

        $buyAddon = $request->query('buy_addon');
        if (!in_array($buyAddon, ['ceo', 'worker', 'workflow'], true)) {
            $buyAddon = null;
        }

        return Inertia::render('Settings/Billing', [
            'tenant' => $this->formatTenantData($tenant),
            'isTrialExpired' => $tenant->isTrialExpired(),
            'billingHistory' => $this->getBillingHistory($tenant),
            'plansCatalog' => config('plans'),
            'buyAddon' => $buyAddon,
        ]);
    }

    /**
     * Update the recurring payment method via Bold tokenization.
     */
    public function updatePaymentMethod(
        UpdatePaymentMethodRequest $request,
        UpdatePaymentMethodAction $action
    ): RedirectResponse {
        /** @var Tenant $tenant */
        $tenant = $request->user()->currentTenant();

        $result = $action->execute($request->validated(), $tenant);
        $cycleLabel = $result['tenant']->isAnnual() ? 'anual' : 'mensual';

        $queryParams = [];
        $buyAddon = $request->input('buy_addon');
        if (in_array($buyAddon, ['ceo', 'worker', 'workflow'], true)) {
            $queryParams['buy_addon'] = $buyAddon;
        }

        if ($result['charged_immediately']) {
            return redirect()->route('settings.billing', $queryParams)->with(
                'success',
                "¡Pago procesado con éxito! Tu taller ha sido reactivado y tu suscripción {$cycleLabel} quedó configurada."
            );
        }

        return redirect()->route('settings.billing', $queryParams)->with(
            'success',
            '¡Tarjeta tokenizada con éxito en Bold! Tu método de pago recurrente ha sido registrado y tu taller está habilitado.'
        );
    }

    /**
     * Change tenant plan (basic <-> pro) and/or billing cycle (monthly <-> annual).
     */
    public function changePlan(Request $request, ChangeSubscriptionAction $action): RedirectResponse|JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user()->currentTenant();

        $validated = $request->validate([
            'plan_type' => ['required', 'string', 'in:basic,pro'],
            'billing_cycle' => ['required', 'string', 'in:monthly,annual'],
        ]);

        $result = $action->execute($tenant, $validated['plan_type'], $validated['billing_cycle']);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'planQuotas' => $result['tenant']->getQuotaSummary(),
            ]);
        }

        return redirect()->route('settings.billing')->with('success', $result['message']);
    }

    /**
     * Upgrade subscription plan to Plan Profesional.
     */
    public function upgradePlan(Request $request, UpgradeTenantPlanAction $action): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user()->currentTenant();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['nullable', 'string', 'in:worker,owner'],
        ]);

        $action->execute($tenant, !empty($validated['email']) ? $validated : null);

        return redirect()->back()->with(
            'success',
            '¡Tu taller ha sido actualizado a Plan Profesional con éxito!'
        );
    }

    /**
     * Purchase an extra addon (ceo, worker, or workflow) for the active tenant.
     */
    public function purchaseAddon(Request $request, PurchaseAddonAction $action): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->isSuperAdmin() || $user->currentRole() === 'owner'), 403);

        $validated = $request->validate([
            'addon_type' => ['required', 'string', 'in:ceo,worker,workflow'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'billing_cycle' => ['nullable', 'string', 'in:monthly,annual'],
        ]);

        /** @var Tenant|null $tenant */
        $tenant = $user->currentTenant();
        abort_if($tenant === null, 404, 'No se encontró un taller activo.');

        if (!$tenant->hasValidPaymentMethod()) {
            return response()->json([
                'requires_payment_method' => true,
                'redirect_url' => '/settings/billing?buy_addon=' . $validated['addon_type'],
                'message' => 'Para comprar este adicional primero debes registrar una tarjeta en Facturación.',
            ], 422);
        }

        $result = $action->execute(
            $tenant,
            (string) $validated['addon_type'],
            (int) ($validated['quantity'] ?? 1),
            $validated['billing_cycle'] ?? null
        );

        return response()->json([
            'success' => true,
            'charged_immediately' => $result['charged_immediately'],
            'amount' => $result['amount'],
            'message' => $result['message'],
            'planQuotas' => $result['tenant']->getQuotaSummary(),
        ]);
    }

    /**
     * Format tenant subscription, quotas, and billing breakdown attributes.
     *
     * @return array<string, mixed>
     */
    protected function formatTenantData(Tenant $tenant): array
    {
        $planType = $tenant->normalizedPlanType();
        $planConfig = $tenant->planConfig();
        $isAnnual = $tenant->isAnnual();
        $cycle = $isAnnual ? 'annual' : 'monthly';
        $priceField = $isAnnual ? 'annual_price' : 'monthly_price';

        $planBasePrice = (int) ($planConfig[$priceField] ?? ($planType === 'pro' ? 65000 : 30000));
        $ceoUnitPrice = (int) config("plans.addons.ceo.{$priceField}", 20000);
        $workerUnitPrice = (int) config("plans.addons.worker.{$priceField}", 15000);
        $workflowUnitPrice = (int) config("plans.addons.workflow.{$priceField}", 15000);

        $extraCeo = (int) ($tenant->extra_ceo_seats ?? 0);
        $extraWorker = (int) ($tenant->extra_worker_seats ?? 0);
        $extraWorkflow = (int) ($tenant->extra_workflows ?? 0);

        $quotaSummary = $tenant->getQuotaSummary();

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'plan_type' => $planType,
            'plan_name' => (string) ($planConfig['name'] ?? ($planType === 'pro' ? 'Plan Profesional' : 'Plan Básico')),
            'billing_cycle' => $cycle,
            'is_annual' => $isAnnual,
            'plan_price' => $planBasePrice,
            'plan_monthly_price' => (int) ($planConfig['monthly_price'] ?? 30000),
            'plan_annual_price' => (int) ($planConfig['annual_price'] ?? 288000),
            'subscription_status' => $tenant->subscription_status,
            'trial_ends_at' => $tenant->trial_ends_at?->format('d/m/Y'),
            'trial_days_remaining' => $tenant->trialDaysRemaining(),
            'is_trial_expired' => $tenant->isTrialExpired(),
            'current_period_ends_at' => $tenant->current_period_ends_at?->format('d/m/Y'),
            'has_payment_method' => $tenant->hasValidPaymentMethod(),
            'card_brand' => $tenant->card_brand,
            'card_last_four' => $tenant->card_last_four,
            'extra_ceo_seats' => $extraCeo,
            'extra_worker_seats' => $extraWorker,
            'extra_workflows' => $extraWorkflow,
            'extra_ceo_unit_price' => $ceoUnitPrice,
            'extra_worker_unit_price' => $workerUnitPrice,
            'extra_workflow_unit_price' => $workflowUnitPrice,
            'extra_ceo_amount' => $extraCeo * $ceoUnitPrice,
            'extra_worker_amount' => $extraWorker * $workerUnitPrice,
            'extra_workflow_amount' => $extraWorkflow * $workflowUnitPrice,
            'subscription_total' => $tenant->calculateSubscriptionTotal($planType, $cycle),
            'total_monthly_amount' => $tenant->calculateSubscriptionTotal($planType, $cycle),
            'quotas' => $quotaSummary['quotas'],
        ];
    }

    /**
     * Generate billing history receipts for subscriptions.
     *
     * @return list<array{id: string, date: string, description: string, amount: int, status: string, card_brand: string|null, card_last_four: string|null}>
     */
    protected function getBillingHistory(Tenant $tenant): array
    {
        $history = $tenant->settings['billing_history'] ?? [];

        if (!$tenant->hasValidPaymentMethod() && $tenant->subscription_status === 'trialing') {
            return $history;
        }

        $planName = (string) ($tenant->planConfig()['name'] ?? 'Plan Básico');
        $cycleLabel = $tenant->isAnnual() ? 'Anual (20% OFF)' : 'Mensual';

        $baseReceipt = [
            'id' => 'REC-' . strtoupper(substr(md5((string) $tenant->id), 0, 8)),
            'date' => now()->format('d/m/Y'),
            'description' => "Suscripción {$cycleLabel} - {$planName}",
            'amount' => $tenant->calculateSubscriptionTotal(),
            'status' => 'Pagado',
            'card_brand' => $tenant->card_brand,
            'card_last_four' => $tenant->card_last_four,
        ];

        return array_values([$baseReceipt, ...$history]);
    }
}
