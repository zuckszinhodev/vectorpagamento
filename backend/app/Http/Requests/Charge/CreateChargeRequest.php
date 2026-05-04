<?php

namespace App\Http\Requests\Charge;

use Illuminate\Foundation\Http\FormRequest;

class CreateChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Amount
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|in:BRL,USD',
            
            // Payment method
            'payment_method' => 'required|in:pix,boleto,credit_card,debit_card',
            
            // Customer
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_document' => 'required|string|min:11|max:14',
            'customer_phone' => 'nullable|string|max:20',
            
            // Address
            'customer_address' => 'nullable|array',
            'customer_address.street' => 'required_with:customer_address|string',
            'customer_address.number' => 'required_with:customer_address|string',
            'customer_address.complement' => 'nullable|string',
            'customer_address.neighborhood' => 'required_with:customer_address|string',
            'customer_address.city' => 'required_with:customer_address|string',
            'customer_address.state' => 'required_with:customer_address|string|size:2',
            'customer_address.zipcode' => 'required_with:customer_address|string',
            
            // Card (if payment_method is credit_card or debit_card)
            'card' => 'required_if:payment_method,credit_card,debit_card|array',
            'card.number' => 'required_with:card|string',
            'card.holder_name' => 'required_with:card|string',
            'card.exp_month' => 'required_with:card|integer|min:1|max:12',
            'card.exp_year' => 'required_with:card|integer|min:' . date('Y'),
            'card.cvv' => 'required_with:card|string|size:3',
            'card.installments' => 'nullable|integer|min:1|max:12',
            
            // Products
            'products' => 'nullable|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            
            // Coupon
            'coupon_code' => 'nullable|string|exists:coupons,code',
            
            // Metadata
            'metadata' => 'nullable|array',
            'description' => 'nullable|string|max:500',
            
            // Checkout
            'checkout_id' => 'nullable|exists:checkouts,id',
            
            // Affiliate
            'affiliate_code' => 'nullable|string|exists:affiliates,code',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'O valor é obrigatório',
            'amount.min' => 'O valor mínimo é R$ 0,01',
            'payment_method.required' => 'O método de pagamento é obrigatório',
            'payment_method.in' => 'Método de pagamento inválido',
            'customer_name.required' => 'O nome do cliente é obrigatório',
            'customer_email.required' => 'O email do cliente é obrigatório',
            'customer_document.required' => 'O CPF/CNPJ do cliente é obrigatório',
            'card.required_if' => 'Os dados do cartão são obrigatórios',
        ];
    }
}
