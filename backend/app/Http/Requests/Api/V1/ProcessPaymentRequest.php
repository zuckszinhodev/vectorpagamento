<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'payment_method' => ['sometimes', 'string', 'in:credit_card,debit_card,pix,boleto'],
            'installments' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'acquirer_id' => ['sometimes', 'uuid'],
        ];

        // Card rules when payment method is credit_card or debit_card
        $paymentMethod = $this->input('payment_method');
        if (in_array($paymentMethod, ['credit_card', 'debit_card'])) {
            $rules = array_merge($rules, [
                'card' => ['required', 'array'],
                'card.number' => ['required', 'string', 'regex:/^[0-9]{13,19}$/'],
                'card.holder_name' => ['required', 'string', 'max:100'],
                'card.expiration_month' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])$/'],
                'card.expiration_year' => ['required', 'string', 'regex:/^[0-9]{2,4}$/'],
                'card.cvv' => ['required', 'string', 'regex:/^[0-9]{3,4}$/'],
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'card.required' => 'Os dados do cartão são obrigatórios para este método de pagamento',
            'card.number.required' => 'O número do cartão é obrigatório',
            'card.number.regex' => 'Número do cartão inválido',
            'card.holder_name.required' => 'O nome do titular é obrigatório',
            'card.expiration_month.required' => 'O mês de validade é obrigatório',
            'card.expiration_month.regex' => 'Mês de validade inválido',
            'card.expiration_year.required' => 'O ano de validade é obrigatório',
            'card.expiration_year.regex' => 'Ano de validade inválido',
            'card.cvv.required' => 'O CVV é obrigatório',
            'card.cvv.regex' => 'CVV inválido',
            'installments.min' => 'O número mínimo de parcelas é 1',
            'installments.max' => 'O número máximo de parcelas é 12',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Os dados fornecidos são inválidos',
                    'details' => $validator->errors()->toArray(),
                ],
            ], 422)
        );
    }
}
