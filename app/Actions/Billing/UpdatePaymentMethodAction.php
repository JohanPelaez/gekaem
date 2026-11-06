<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Support\Facades\DB;

class UpdatePaymentMethodAction
{
    public function __construct(
        protected BoldPaymentService $boldService
    ) {}

    /**
     * Validate, tokenize, and persist a recurring payment method for the given tenant.
     *
     * @param array{
     *     card_number: string,
     *     cardholder_name: string,
     *     expiration_month: string,
     *     expiration_year: string,
     *     cvv: string,
     *     payment_method_type?: string|null
     * } $data
     */
    public function execute(array $data, Tenant $tenant): Tenant
    {
        // 1. Validar tarjeta bajo reglas de recurrencia (Luhn, marcas autorizadas, expiración futura)
        $validatedCard = $this->boldService->validateCard($data);

        // 2. Tokenizar la tarjeta a través de la pasarela Bold
        $tokenized = $this->boldService->tokenizeCard($validatedCard);

        return DB::transaction(function () use ($tenant, $tokenized): Tenant {
            $updateData = [
                'payment_method_token' => $tokenized['payment_method_token'],
                'bold_customer_id' => $tokenized['bold_customer_id'],
                'card_brand' => $tokenized['card_brand'],
                'card_last_four' => $tokenized['card_last_four'],
            ];

            // Si el periodo de prueba ya expiró o estaba moroso, activar la suscripción al registrar tarjeta válida
            if ($tenant->isTrialExpired() || in_array($tenant->subscription_status, ['past_due', 'canceled'], true)) {
                $updateData['subscription_status'] = 'active';
            }

            $tenant->update($updateData);

            return $tenant->refresh();
        });
    }
}

