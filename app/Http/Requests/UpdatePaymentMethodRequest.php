<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentMethodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'card_number' => ['required', 'string', 'min:13', 'max:25'],
            'cardholder_name' => ['required', 'string', 'max:150'],
            'expiration_month' => ['required', 'string', 'max:2'],
            'expiration_year' => ['required', 'string', 'max:4'],
            'cvv' => ['required', 'string', 'min:3', 'max:4'],
            'payment_method_type' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'card_number.required' => 'El número de tarjeta es obligatorio.',
            'cardholder_name.required' => 'El nombre del titular de la tarjeta es obligatorio.',
            'expiration_month.required' => 'El mes de expiración es obligatorio.',
            'expiration_year.required' => 'El año de expiración es obligatorio.',
            'cvv.required' => 'El código de seguridad CVV es obligatorio.',
        ];
    }
}

