<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'image_url' => 'nullable|url|max:500',
            'sku' => 'nullable|string|max:100',
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'max_installments' => 'nullable|integer|min:1|max:12',
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome do produto é obrigatório',
            'price.required' => 'O preço é obrigatório',
            'price.min' => 'O preço não pode ser negativo',
            'category_id.exists' => 'Categoria não encontrada',
        ];
    }
}
