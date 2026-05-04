<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('global_webhooks')) {
            return;
        }

        Schema::create('global_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('url');
            $table->json('events'); // ['payment.approved', 'payment.refunded', etc.]
            $table->string('secret')->nullable();
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->timestamps();

            $table->index('active');
        });

        // Create acquirer_configs table for detailed acquirer settings
        if (!Schema::hasTable('acquirer_configs')) {
            Schema::create('acquirer_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('acquirer_key')->unique(); // stripe, pagarme, mercadopago, babylon
            $table->string('display_name');
            $table->string('type'); // credit_card, pix, boleto, multiple
            $table->boolean('enabled')->default(false);
            $table->json('credentials')->nullable(); // encrypted credentials
            $table->json('supported_methods')->nullable(); // ['credit_card', 'pix', 'boleto']
            $table->decimal('fee_percentage', 5, 2)->default(0.00);
            $table->decimal('fee_fixed', 10, 2)->default(0.00);
            $table->integer('priority')->default(0);
            $table->json('settings')->nullable(); // additional settings
            $table->timestamps();

            $table->index('enabled');
            $table->index('priority');
        });

        // Insert default acquirer configs
        if (DB::table('acquirer_configs')->count() === 0) {
            DB::table('acquirer_configs')->insert([
                [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'acquirer_key' => 'stripe',
                    'display_name' => 'Stripe',
                    'type' => 'Cartão de Crédito',
                    'enabled' => false,
                    'credentials' => null,
                    'supported_methods' => json_encode(['credit_card']),
                    'fee_percentage' => 2.99,
                    'fee_fixed' => 0.30,
                    'priority' => 1,
                    'settings' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'acquirer_key' => 'pagarme',
                    'display_name' => 'Pagar.me',
                    'type' => 'Múltiplos',
                    'enabled' => false,
                    'credentials' => null,
                    'supported_methods' => json_encode(['credit_card', 'pix', 'boleto']),
                    'fee_percentage' => 2.49,
                    'fee_fixed' => 0.00,
                    'priority' => 2,
                    'settings' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'acquirer_key' => 'mercadopago',
                'display_name' => 'Mercado Pago',
                'type' => 'Múltiplos',
                'enabled' => false,
                'credentials' => null,
                'supported_methods' => json_encode(['credit_card', 'pix', 'boleto']),
                'fee_percentage' => 3.99,
                'fee_fixed' => 0.00,
                'priority' => 3,
                'settings' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'acquirer_key' => 'babylon',
                'display_name' => 'Banco Babylon',
                'type' => 'PIX / Boleto / Cartão',
                'enabled' => true,
                'credentials' => null,
                'supported_methods' => json_encode(['credit_card', 'pix', 'boleto']),
                'fee_percentage' => 1.99,
                'fee_fixed' => 0.00,
                'priority' => 0,
                'settings' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        }
    }
    }

    public function down(): void
    {
        Schema::dropIfExists('acquirer_configs');
        Schema::dropIfExists('global_webhooks');
    }
};
