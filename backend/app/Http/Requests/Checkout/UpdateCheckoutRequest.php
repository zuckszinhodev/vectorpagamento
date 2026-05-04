<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'design_config' => 'nullable|array',
            'design_config.primary_color' => 'nullable|string',
            'design_config.logo_url' => 'nullable|url',
            'design_config.background_image' => 'nullable|url',
            'payment_methods' => 'sometimes|required|array',
            'payment_methods.*' => 'in:pix,boleto,credit_card,debit_card',
            'is_active' => 'nullable|boolean',
        ];
    }
}
