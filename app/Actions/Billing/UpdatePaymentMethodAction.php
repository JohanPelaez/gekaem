<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePaymentMethodAction
{
    public function __construct(
        protected BoldPaymentService $boldService
    ) {}

    /**
     * Validate, tokenize, and persist a recurring payment method for the given tenant.
     * Executes immediate charge if the tenant is disabled due to non-payment.
     *
     * @param array{
     *     card_number: string,
     *     cardholder_name: string,
     *     expiration_month: string,
     *     expiration_year: string,
     *     cvv: string,
     *     payment_method_type?: string|null
     * } $data
     * @return array{
     *     tenant: Tenant,
     *     charged_immediately: bool,
     *     amount_charged: int
     * }
     * @throws ValidationException
     */
    public function execute(array $data, Tenant $tenant): array
    {
        // 1. Validar tarjeta bajo reglas de recurrencia (Luhn, marcas autorizadas, expiración futura)
        $validatedCard = $this->boldService->validateCard($data);

        // 2. Tokenizar la tarjeta a través de la pasarela Bold
        $tokenized = $this->boldService->tokenizeCard($validatedCard);

        // Determinar si el taller está inhabilitado por falta de pago o prueba expirada sin suscripción activa
        $isPastDueOrExpired = in_array($tenant->subscription_status, ['past_due', 'expired', 'canceled', 'unpaid'], true);
        $isTrialExpiredWithoutActive = $tenant->isTrialExpired() && $tenant->subscription_status !== 'active';
        $isDisabled = $isPastDueOrExpired || $isTrialExpiredWithoutActive;

        if ($isDisabled) {
            // Caso Taller Inhabilitado: Ejecutar Cobro Inmediato
            $monthlyTotal = $tenant->calculateMonthlyTotal();
            $description = "Reactivación y Suscripción Mensual - {$tenant->name}";

            // Asignar transitoriamente los datos tokenizados para permitir el cargo en pasarela
            $tenant->payment_method_token = $tokenized['payment_method_token'];
            $tenant->bold_customer_id = $tokenized['bold_customer_id'];
            $tenant->card_brand = $tokenized['card_brand'];
            $tenant->card_last_four = $tokenized['card_last_four'];

            try {
                $this->boldService->chargeToken($tenant, $monthlyTotal, $description);
            } catch (ValidationException $e) {
                $messages = $e->errors();
                $firstMessage = reset($messages)[0] ?? 'La tarjeta fue rechazada durante el cobro de reactivación. Por favor intenta con otra tarjeta.';
                throw ValidationException::withMessages([
                    'card_number' => $firstMessage,
                ]);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'card_number' => 'Error al procesar el cobro de reactivación en Bold: ' . $e->getMessage(),
                ]);
            }

            // Cobro exitoso: actualizar y activar la suscripción del taller
            DB::transaction(function () use ($tenant, $tokenized): void {
                $tenant->update([
                    'payment_method_token' => $tokenized['payment_method_token'],
                    'bold_customer_id' => $tokenized['bold_customer_id'],
                    'card_brand' => $tokenized['card_brand'],
                    'card_last_four' => $tokenized['card_last_four'],
                    'subscription_status' => 'active',
                    'current_period_ends_at' => now()->addMonth(),
                    'paid_seats' => max($tenant->includedBaseSeats(), $tenant->activeUsersCount()),
                ]);
            });

            return [
                'tenant' => $tenant->refresh(),
                'charged_immediately' => true,
                'amount_charged' => $monthlyTotal,
            ];
        }

        // Caso Taller Activo o aún con Días de Prueba Vigentes:
        // Deja configurada la tarjeta para el cobro automático el día del corte
        DB::transaction(function () use ($tenant, $tokenized): void {
            $tenant->update([
                'payment_method_token' => $tokenized['payment_method_token'],
                'bold_customer_id' => $tokenized['bold_customer_id'],
                'card_brand' => $tokenized['card_brand'],
                'card_last_four' => $tokenized['card_last_four'],
            ]);
        });

        return [
            'tenant' => $tenant->refresh(),
            'charged_immediately' => false,
            'amount_charged' => 0,
        ];
    }
}
