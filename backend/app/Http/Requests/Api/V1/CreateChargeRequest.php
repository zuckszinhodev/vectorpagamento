<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:100'], // Minimum R$ 1.00
            'currency' => ['sometimes', 'string', 'in:BRL,USD,EUR'],
            'payment_method' => ['required', 'string', 'in:credit_card,debit_card,pix,boleto'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['required', 'email', 'max:255'],
            'customer.document' => ['required', 'string', 'max:20'],
            'customer.phone' => ['sometimes', 'string', 'max:20'],
            'description' => ['sometimes', 'string', 'max:500'],
            'metadata' => ['sometimes', 'array'],
            'expires_at' => ['sometimes', 'date', 'after:now'],
            'checkout_id' => ['sometimes', 'uuid'],
            'product_id' => ['sometimes', 'uuid'],
            'affiliate_code' => ['sometimes', 'string', 'max:50'],
            'installments' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'billing' => ['sometimes', 'array'],
            'billing.street' => ['required_with:billing', 'string', 'max:255'],
            'billing.number' => ['required_with:billing', 'string', 'max:20'],
            'billing.complement' => ['sometimes', 'string', 'max:100'],
            'billing.neighborhood' => ['sometimes', 'string', 'max:100'],
            'billing.city' => ['required_with:billing', 'string', 'max:100'],
            'billing.state' => ['required_with:billing', 'string', 'max:2'],
            'billing.zip_code' => ['required_with:billing', 'string', 'max:10'],
            'billing.country' => ['sometimes', 'string', 'max:2'],
            // Split de pagamento (opcional)
            'split_rules' => ['sometimes', 'array', 'min:1'],
            'split_rules.*.recipient_id' => ['required_with:split_rules', 'string'],
            'split_rules.*.amount' => ['required_without:split_rules.*.percentage', 'nullable', 'integer', 'min:1'],
            'split_rules.*.percentage' => ['required_without:split_rules.*.amount', 'nullable', 'numeric', 'min:0.01', 'max:100'],
            'split_rules.*.type' => ['required_with:split_rules', 'string', 'in:percentage,fixed,flat'],
            'split_rules.*.charge_processing_fee' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'O valor da cobrança é obrigatório',
            'amount.min' => 'O valor mínimo é R$ 1,00',
            'payment_method.required' => 'O método de pagamento é obrigatório',
            'payment_method.in' => 'Método de pagamento inválido',
            'customer.required' => 'Os dados do cliente são obrigatórios',
            'customer.name.required' => 'O nome do cliente é obrigatório',
            'customer.email.required' => 'O e-mail do cliente é obrigatório',
            'customer.email.email' => 'E-mail do cliente inválido',
            'customer.document.required' => 'O documento do cliente é obrigatório',
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
