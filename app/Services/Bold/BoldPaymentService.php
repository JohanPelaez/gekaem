<?php

declare(strict_types=1);

namespace App\Services\Bold;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BoldPaymentService
{
    /**
     * Allowed recurring card brands.
     *
     * @var array<string, string>
     */
    public const ALLOWED_BRANDS = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'amex' => 'American Express',
        'diners' => 'Diners Club',
    ];

    /**
     * Non-recurring payment types explicitly rejected for subscriptions.
     *
     * @var list<string>
     */
    public const NON_RECURRING_TYPES = [
        'pse',
        'nequi',
        'efectivo',
        'cash',
        'transferencia',
        'daviplata',
        'bancolombia_qr',
    ];

    /**
     * Validate card details specifically for recurring subscription billing.
     *
     * @param array{
     *     card_number: string,
     *     expiration_month: int|string,
     *     expiration_year: int|string,
     *     cvv: string,
     *     cardholder_name?: string|null,
     *     payment_method_type?: string|null
     * } $cardData
     * @return array{
     *     clean_card_number: string,
     *     brand_code: string,
     *     brand_label: string,
     *     last_four: string,
     *     expiration_month: string,
     *     expiration_year: string,
     *     cvv: string,
     *     cardholder_name: string
     * }
     * @throws ValidationException
     */
    public function validateCard(array $cardData): array
    {
        // 1. Rechazo explícito de medios de pago no recurrentes
        $methodType = strtolower(trim((string) ($cardData['payment_method_type'] ?? '')));
        if ($methodType !== '' && in_array($methodType, self::NON_RECURRING_TYPES, true)) {
            throw ValidationException::withMessages([
                'payment_method_type' => 'Los medios de pago de un solo uso como PSE, Nequi manual o efectivo no son válidos para suscripciones recurrentes. Solo se admiten tarjetas de crédito o débito Visa, Mastercard, Amex o Diners.',
            ]);
        }

        // 2. Limpieza del número de tarjeta
        $rawNumber = (string) ($cardData['card_number'] ?? '');
        $cleanNumber = preg_replace('/[^\d]/', '', $rawNumber) ?? '';

        if (strlen($cleanNumber) < 13 || strlen($cleanNumber) > 19) {
            throw ValidationException::withMessages([
                'card_number' => 'El número de tarjeta debe tener entre 13 y 19 dígitos numéricos.',
            ]);
        }

        // 3. Validación Algoritmo de Luhn
        if (!$this->validateLuhn($cleanNumber)) {
            throw ValidationException::withMessages([
                'card_number' => 'El número de tarjeta no es válido (verificación de algoritmo Luhn fallida).',
            ]);
        }

        // 4. Verificación estricta de marca habilitada para pagos recurrentes
        $brandCode = $this->detectBrand($cleanNumber);
        if ($brandCode === null || !array_key_exists($brandCode, self::ALLOWED_BRANDS)) {
            throw ValidationException::withMessages([
                'card_number' => 'Solo se permiten tarjetas de crédito o débito habilitadas para pagos recurrentes: Visa, Mastercard, American Express o Diners Club.',
            ]);
        }

        // 5. Validación de Expiración en Fecha Futura
        $expMonth = (int) ($cardData['expiration_month'] ?? 0);
        $expYearRaw = (int) ($cardData['expiration_year'] ?? 0);

        if ($expMonth < 1 || $expMonth > 12) {
            throw ValidationException::withMessages([
                'expiration_month' => 'El mes de expiración debe ser un número entre 01 y 12.',
            ]);
        }

        $expYear = $expYearRaw < 100 ? (2000 + $expYearRaw) : $expYearRaw;

        // Fin de mes en hora de Bogotá / local
        $cardExpiryDate = Carbon::createFromDate($expYear, $expMonth, 1)->endOfMonth();
        if ($cardExpiryDate->isPast()) {
            throw ValidationException::withMessages([
                'expiration_month' => 'La fecha de expiración de la tarjeta debe ser futura.',
            ]);
        }

        // 6. Validación de CVV según marca
        $cvv = trim((string) ($cardData['cvv'] ?? ''));
        $expectedCvvLen = ($brandCode === 'amex') ? 4 : 3;
        if (!ctype_digit($cvv) || strlen($cvv) !== $expectedCvvLen) {
            throw ValidationException::withMessages([
                'cvv' => "El código CVV para {$this->brandLabel($brandCode)} debe tener exactamente {$expectedCvvLen} dígitos.",
            ]);
        }

        $cardholderName = trim((string) ($cardData['cardholder_name'] ?? ''));
        if ($cardholderName === '') {
            $cardholderName = 'Titular del Taller';
        }

        return [
            'clean_card_number' => $cleanNumber,
            'brand_code' => $brandCode,
            'brand_label' => self::ALLOWED_BRANDS[$brandCode],
            'last_four' => substr($cleanNumber, -4),
            'expiration_month' => str_pad((string) $expMonth, 2, '0', STR_PAD_LEFT),
            'expiration_year' => (string) $expYear,
            'cvv' => $cvv,
            'cardholder_name' => $cardholderName,
        ];
    }

    /**
     * Tokenize a validated card using Bold's Payment Gateway API.
     *
     * @param array{
     *     clean_card_number: string,
     *     brand_code: string,
     *     brand_label: string,
     *     last_four: string,
     *     expiration_month: string,
     *     expiration_year: string,
     *     cvv: string,
     *     cardholder_name: string
     * } $validatedCard
     * @return array{
     *     payment_method_token: string,
     *     bold_customer_id: string,
     *     card_brand: string,
     *     card_last_four: string
     * }
     * @throws ValidationException
     */
    public function tokenizeCard(array $validatedCard): array
    {
        $apiKey = config('services.bold.api_key');
        $baseUrl = rtrim((string) config('services.bold.base_url', 'https://integrations.api.bold.co'), '/');

        // Si se cuenta con API Key de Bold configurada, llamamos a la API oficial de Bold
        if (!empty($apiKey)) {
            try {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->timeout(12)->post("{$baseUrl}/online/v1/cards", [
                    'card_number' => $validatedCard['clean_card_number'],
                    'expiration_month' => $validatedCard['expiration_month'],
                    'expiration_year' => $validatedCard['expiration_year'],
                    'cvv' => $validatedCard['cvv'],
                    'card_holder' => $validatedCard['cardholder_name'],
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    $token = $json['token'] ?? $json['payload']['token'] ?? $json['id'] ?? null;
                    $customerId = $json['customer_id'] ?? $json['payload']['customer_id'] ?? null;

                    if (!empty($token)) {
                        return [
                            'payment_method_token' => (string) $token,
                            'bold_customer_id' => (string) ($customerId ?? ('bld_cus_' . Str::random(16))),
                            'card_brand' => $validatedCard['brand_label'],
                            'card_last_four' => $validatedCard['last_four'],
                        ];
                    }
                }

                $errorMessage = $response->json('message')
                    ?? $response->json('errors.0.message')
                    ?? 'No fue posible tokenizar la tarjeta en la pasarela Bold.';

                Log::warning('Bold tokenization failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw ValidationException::withMessages([
                    'card_number' => 'Error de la pasarela Bold: ' . $errorMessage,
                ]);
            } catch (\Exception $e) {
                if ($e instanceof ValidationException) {
                    throw $e;
                }
                Log::error('Bold API connection exception', ['error' => $e->getMessage()]);
                throw ValidationException::withMessages([
                    'card_number' => 'No se pudo conectar con el servicio de tokenización de Bold: ' . $e->getMessage(),
                ]);
            }
        }

        // Modo Simulación/Sandbox (cuando BOLD_API_KEY no está configurada en local o pruebas)
        $simulatedToken = 'bld_tok_' . Str::random(26);
        $simulatedCustomerId = 'bld_cus_' . Str::random(16);

        Log::info("Bold tokenization simulated for card {$validatedCard['brand_label']} ending in {$validatedCard['last_four']}");

        return [
            'payment_method_token' => $simulatedToken,
            'bold_customer_id' => $simulatedCustomerId,
            'card_brand' => $validatedCard['brand_label'],
            'card_last_four' => $validatedCard['last_four'],
        ];
    }

    /**
     * Validate card number using Luhn Algorithm (Mod 10).
     */
    public function validateLuhn(string $number): bool
    {
        $sum = 0;
        $numDigits = strlen($number);
        $parity = $numDigits % 2;

        for ($i = 0; $i < $numDigits; $i++) {
            $digit = (int) $number[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return ($sum % 10 === 0);
    }

    /**
     * Detect card brand using IIN/BIN patterns.
     */
    public function detectBrand(string $number): ?string
    {
        // Visa: Starts with 4, length 13, 16, 19
        if (preg_match('/^4[0-9]{12}(?:[0-9]{3})?(?:[0-9]{3})?$/', $number)) {
            return 'visa';
        }

        // Mastercard: 51-55 or 2221-2720, length 16
        if (preg_match('/^(5[1-5][0-9]{14}|2(22[1-9][0-9]{12}|2[3-9][0-9]{13}|[3-6][0-9]{14}|7[0-1][0-9]{13}|720[0-9]{12}))$/', $number)) {
            return 'mastercard';
        }

        // American Express: Starts with 34 or 37, length 15
        if (preg_match('/^3[47][0-9]{13}$/', $number)) {
            return 'amex';
        }

        // Diners Club: Starts with 300-305, 36, 38, 39, length 14 or 16
        if (preg_match('/^3(?:0[0-5]|[68][0-9])[0-9]{11,13}$/', $number)) {
            return 'diners';
        }

        return null;
    }

    /**
     * Get label for brand code.
     */
    public function brandLabel(string $code): string
    {
        return self::ALLOWED_BRANDS[$code] ?? ucfirst($code);
    }
}

