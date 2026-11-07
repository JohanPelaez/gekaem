<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Billing\UpdatePaymentMethodAction;
use App\Actions\Billing\UpgradeTenantPlanAction;
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

        return Inertia::render('Settings/Billing', [
            'tenant' => $this->formatTenantData($tenant),
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

        $result = $action->execute($request->validated(), $tenant);

        if ($result['charged_immediately']) {
            return redirect()->route('settings.billing')->with(
                'success',
                '¡Pago procesado con éxito! Tu taller ha sido reactivado y tu suscripción mensual quedó configurada.'
            );
        }

        return redirect()->route('settings.billing')->with(
            'success',
            '¡Tarjeta tokenizada con éxito en Bold! Tu método de pago recurrente ha sido registrado y tu taller está habilitado.'
        );
    }

    /**
     * Upgrade subscription plan to Plan Taller Pro.
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
            '¡Tu taller ha sido actualizado a Plan Taller Pro con éxito! Ahora tienes 5 cupos incluidos.'
        );
    }

    /**
     * Format tenant subscription and trial attributes.
     *
     * @return array<string, mixed>
     */
    protected function formatTenantData(Tenant $tenant): array
    {
        $isPro = $tenant->plan_type === 'pro';
        $activeUsers = $tenant->activeUsersCount();
        $baseSeats = $tenant->includedBaseSeats();
        $extraSeats = max(0, $activeUsers - $baseSeats);

        return [
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
            'active_users_count' => $activeUsers,
            'paid_seats' => max((int) ($tenant->paid_seats ?? 1), $baseSeats),
            'included_base_seats' => $baseSeats,
            'available_seats' => $tenant->availableSeats(),
            'extra_seats_count' => $extraSeats,
            'extra_seats_amount' => $extraSeats * 15000,
            'total_monthly_amount' => $tenant->calculateMonthlyTotal(),
            'requires_extra_seat_charge' => $tenant->requiresImmediateExtraSeatCharge(),
        ];
    }

    /**
     * Generate simulated/real billing history receipts for monthly subscriptions.
     *
     * @return list<array{id: string, date: string, description: string, amount: int, status: string, card_brand: string|null, card_last_four: string|null}>
     */
    protected function getBillingHistory(Tenant $tenant): array
    {
        $history = $tenant->settings['billing_history'] ?? [];

        if (!$tenant->hasValidPaymentMethod() && $tenant->subscription_status === 'trialing') {
            return $history;
        }

        $baseReceipt = [
            'id' => 'REC-' . strtoupper(substr(md5((string) $tenant->id), 0, 8)),
            'date' => now()->format('d/m/Y'),
            'description' => $tenant->plan_type === 'pro' ? 'Suscripción Mensual - Plan Taller Pro' : 'Suscripción Mensual - Plan Estándar',
            'amount' => $tenant->plan_type === 'pro' ? 75000 : 30000,
            'status' => 'Pagado',
            'card_brand' => $tenant->card_brand,
            'card_last_four' => $tenant->card_last_four,
        ];

        return array_values([$baseReceipt, ...$history]);
    }
}
