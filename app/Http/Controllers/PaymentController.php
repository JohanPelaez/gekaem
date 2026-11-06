<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Billing\UpdatePaymentMethodAction;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Models\Tenant;
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

        $isPro = $tenant->plan_type === 'pro';

        return Inertia::render('Settings/Billing', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan_type' => $tenant->plan_type,
                'plan_name' => $isPro ? 'Plan Taller Pro (Hasta 5 Usuarios)' : 'Plan Estándar (1 Usuario)',
                'plan_price' => $isPro ? 75000 : 30000,
                'subscription_status' => $tenant->subscription_status,
                'trial_ends_at' => $tenant->trial_ends_at?->format('d/m/Y'),
                'trial_days_remaining' => $tenant->trialDaysRemaining(),
                'is_trial_expired' => $tenant->isTrialExpired(),
                'has_payment_method' => $tenant->hasValidPaymentMethod(),
                'card_brand' => $tenant->card_brand,
                'card_last_four' => $tenant->card_last_four,
            ],
            'isTrialExpired' => $tenant->isTrialExpired(),
            'billingHistory' => $this->getBillingHistory($tenant),
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

        $action->execute($request->validated(), $tenant);

        return redirect()->route('settings.billing')->with(
            'success',
            '¡Tarjeta tokenizada con éxito en Bold! Tu método de pago recurrente ha sido registrado y tu taller está habilitado.'
        );
    }

    /**
     * Generate simulated/real billing history receipts for monthly subscriptions.
     *
     * @return list<array{id: string, date: string, description: string, amount: int, status: string, card_brand: string|null, card_last_four: string|null}>
     */
    protected function getBillingHistory(Tenant $tenant): array
    {
        if (!$tenant->hasValidPaymentMethod() && $tenant->subscription_status === 'trialing') {
            return [];
        }

        $amount = $tenant->plan_type === 'pro' ? 75000 : 30000;
        $planTitle = $tenant->plan_type === 'pro' ? 'Suscripción Mensual - Plan Taller Pro' : 'Suscripción Mensual - Plan Estándar';

        return [
            [
                'id' => 'REC-' . strtoupper(substr(md5((string) $tenant->id), 0, 8)),
                'date' => now()->format('d/m/Y'),
                'description' => $planTitle,
                'amount' => $amount,
                'status' => 'Pagado',
                'card_brand' => $tenant->card_brand,
                'card_last_four' => $tenant->card_last_four,
            ],
        ];
    }
}

