<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Account data
            'account_name' => 'required|string|max:255',
            'document' => 'required|string|min:11|max:14|unique:accounts,document',
            'phone' => 'required|string|max:20',
            
            // User data
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            
            // Optional
            'business_type' => 'nullable|in:individual,company',
            'website' => 'nullable|url|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'account_name.required' => 'O nome da conta é obrigatório',
            'document.required' => 'O CPF/CNPJ é obrigatório',
            'document.unique' => 'Este CPF/CNPJ já está cadastrado',
            'email.unique' => 'Este email já está cadastrado',
            'password.min' => 'A senha deve ter no mínimo 8 caracteres',
            'password.confirmed' => 'As senhas não coincidem',
        ];
    }
}
