<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Support\Facades\DB;

class PurchaseAddonAction
{
    public function __construct(
        protected BoldPaymentService $boldService
    ) {}

    /**
     * Purchase an extra addon (ceo, worker, or workflow) for the given tenant.
     *
     * @return array{
     *     success: bool,
     *     charged_immediately: bool,
     *     amount: int,
     *     message: string,
     *     tenant: Tenant
     * }
     */
    public function execute(Tenant $tenant, string $addonType, int $quantity = 1, ?string $billingCycle = null): array
    {
        $cycle = ($billingCycle ?? ($tenant->billing_cycle ?: 'monthly')) === 'annual' ? 'annual' : 'monthly';
        $priceField = $cycle === 'annual' ? 'annual_price' : 'monthly_price';

        /** @var array<string, mixed> $addonConfig */
        $addonConfig = config("plans.addons.{$addonType}", []);
        $addonName = (string) ($addonConfig['name'] ?? ucfirst($addonType));
        $unitPrice = (int) ($addonConfig[$priceField] ?? 15000);
        $totalAmount = $unitPrice * $quantity;
        $formattedTotal = '$' . number_format($totalAmount, 0, ',', '.');
        $cycleLabel = $cycle === 'annual' ? 'anual' : 'mensual';

        $column = match ($addonType) {
            'ceo' => 'extra_ceo_seats',
            'worker' => 'extra_worker_seats',
            'workflow' => 'extra_workflows',
            default => throw new \InvalidArgumentException("Tipo de adicional no válido: {$addonType}"),
        };

        $chargedImmediately = false;

        if ($tenant->subscription_status === 'trialing') {
            DB::transaction(function () use ($tenant, $column, $addonType, $quantity): void {
                $tenant->increment($column, $quantity);
                if (in_array($addonType, ['ceo', 'worker'], true)) {
                    $tenant->increment('paid_seats', $quantity);
                }
            });

            $message = "¡Adicional activado! Se agregó {$quantity} × {$addonName} a tu taller. "
                . "Como estás en periodo de prueba, no se realizó cobro hoy: {$formattedTotal} COP ({$cycleLabel}) se sumarán a tu renovación al terminar los 15 días.";
        } else {
            $description = "Adicional: {$quantity} × {$addonName} ({$cycleLabel})";
            $this->boldService->chargeToken($tenant, $totalAmount, $description);
            $chargedImmediately = true;

            DB::transaction(function () use ($tenant, $column, $addonType, $quantity): void {
                $tenant->increment($column, $quantity);
                if (in_array($addonType, ['ceo', 'worker'], true)) {
                    $tenant->increment('paid_seats', $quantity);
                }
            });

            $message = "¡Compra exitosa! Se procesó el cobro de {$formattedTotal} COP en tu tarjeta y se habilitó {$quantity} × {$addonName} de inmediato.";
        }

        return [
            'success' => true,
            'charged_immediately' => $chargedImmediately,
            'amount' => $totalAmount,
            'message' => $message,
            'tenant' => $tenant->fresh(),
        ];
    }
}
