<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAffiliateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'document' => 'nullable|string|min:11|max:14',
            'phone' => 'nullable|string|max:20',
            'commission_type' => 'sometimes|required|in:percentage,fixed',
            'commission_value' => 'sometimes|required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }
}
