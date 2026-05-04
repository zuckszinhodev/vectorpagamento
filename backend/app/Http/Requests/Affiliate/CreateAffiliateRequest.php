<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Foundation\Http\FormRequest;

class CreateAffiliateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'document' => 'nullable|string|min:11|max:14',
            'phone' => 'nullable|string|max:20',
            'commission_type' => 'required|in:percentage,fixed',
            'commission_value' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome do afiliado é obrigatório',
            'email.required' => 'O email é obrigatório',
            'commission_type.required' => 'O tipo de comissão é obrigatório',
            'commission_value.required' => 'O valor da comissão é obrigatório',
        ];
    }
}
