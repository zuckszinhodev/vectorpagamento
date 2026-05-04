<?php

namespace Database\Seeders;

use App\Domain\Acquirer\Entities\Acquirer;
use Illuminate\Database\Seeder;

class AcquirerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $acquirers = [
            [
                'name' => 'Stripe',
                'slug' => 'stripe',
                'is_active' => true,
                'supported_methods' => json_encode(['credit_card', 'debit_card']),
                'settings' => json_encode([
                    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
                    'secret_key' => env('STRIPE_SECRET_KEY', ''),
                    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
                    'currencies' => ['BRL', 'USD', 'EUR'],
                    'max_installments' => 12,
                ]),
            ],
            [
                'name' => 'Pagar.me',
                'slug' => 'pagarme',
                'is_active' => true,
                'supported_methods' => json_encode(['credit_card', 'debit_card', 'pix', 'boleto']),
                'settings' => json_encode([
                    'secret_key' => env('PAGARME_SECRET_KEY', ''),
                    'public_key' => env('PAGARME_PUBLIC_KEY', ''),
                    'currencies' => ['BRL'],
                    'max_installments' => 12,
                    'pix_expires_in' => 3600,
                    'boleto_days_to_expire' => 3,
                ]),
            ],
            [
                'name' => 'Mercado Pago',
                'slug' => 'mercadopago',
                'is_active' => false,
                'supported_methods' => json_encode(['credit_card', 'debit_card', 'pix', 'boleto']),
                'settings' => json_encode([
                    'access_token' => env('MERCADOPAGO_ACCESS_TOKEN', ''),
                    'public_key' => env('MERCADOPAGO_PUBLIC_KEY', ''),
                    'currencies' => ['BRL', 'ARS', 'MXN'],
                    'max_installments' => 12,
                ]),
            ],
            [
                'name' => 'Banco Babylon',
                'slug' => 'babylon',
                'is_active' => true,
                'supported_methods' => json_encode(['credit_card', 'pix', 'boleto']),
                'settings' => json_encode([
                    'secret_key' => env('BABYLON_SECRET_KEY', ''),
                    'company_id' => env('BABYLON_COMPANY_ID', ''),
                    'public_key' => env('BABYLON_PUBLIC_KEY', ''),
                    'webhook_secret' => env('BABYLON_WEBHOOK_SECRET', ''),
                    'currencies' => ['BRL'],
                    'max_installments' => 12,
                    'pix_expires_in' => 1800,
                    'boleto_days_to_expire' => 3,
                ]),
            ],
        ];

        foreach ($acquirers as $acquirerData) {
            Acquirer::updateOrCreate(
                ['slug' => $acquirerData['slug']],
                $acquirerData
            );
        }
    }
}
