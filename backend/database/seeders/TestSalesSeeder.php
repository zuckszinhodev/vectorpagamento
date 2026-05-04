<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestSalesSeeder extends Seeder
{
    public function run(): void
    {
        $productSlug = '2l65gyCV';
        $now = now();

        // Get Whiter account
        $account = DB::table('accounts')->where('email', 'contatowhiter@gmail.com')->first();
        
        if (!$account) {
            $this->command->error('❌ Conta contatowhiter@gmail.com não encontrada!');
            return;
        }

        // Check if product exists, create if not
        $product = DB::table('products')->where('slug', $productSlug)->first();
        
        if (!$product) {
            // Create test product
            $productId = Str::uuid()->toString();
            DB::table('products')->insert([
                'id' => $productId,
                'account_id' => $account->id,
                'name' => 'Produto de Teste',
                'slug' => $productSlug,
                'description' => 'Produto criado para testes',
                'type' => 'digital',
                'price' => 9900, // R$ 99,00 in cents
                'currency' => 'BRL',
                'commission_percent' => 10,
                'commission_fixed' => 0,
                'affiliate_enabled' => true,
                'stock_enabled' => false,
                'images' => '[]',
                'metadata' => '{}',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $product = DB::table('products')->where('id', $productId)->first();
            $this->command->info("✅ Produto {$productSlug} criado!");
        }

        // Get acquirer (Stripe or first available)
        $acquirer = DB::table('acquirers')->where('slug', 'stripe')->first();
        if (!$acquirer) {
            $acquirer = DB::table('acquirers')->first();
        }

        if (!$acquirer) {
            $this->command->error('❌ Nenhum acquirer encontrado!');
            return;
        }

        // Create 2 test sales
        for ($i = 1; $i <= 2; $i++) {
            $chargeId = Str::uuid()->toString();
            $transactionId = Str::uuid()->toString();

            // Create charge
            DB::table('charges')->insert([
                'id' => $chargeId,
                'account_id' => $account->id,
                'product_id' => $product->id,
                'idempotency_key' => 'test_sale_' . $productSlug . '_' . $i . '_' . time(),
                'amount' => $product->price,
                'currency' => 'BRL',
                'payment_method' => 'credit_card',
                'status' => 'approved',
                'customer' => json_encode([
                    'name' => 'Cliente Teste ' . $i,
                    'email' => 'cliente' . $i . '@teste.com',
                    'document' => '12345678900',
                    'phone' => '11999999999',
                ]),
                'billing' => json_encode([
                    'address' => [
                        'street' => 'Rua Teste',
                        'number' => '123',
                        'city' => 'São Paulo',
                        'state' => 'SP',
                        'zip_code' => '01310100',
                        'country' => 'BR',
                    ],
                ]),
                'metadata' => '{}',
                'paid_at' => $now->copy()->subHours($i),
                'created_at' => $now->copy()->subHours($i),
                'updated_at' => $now,
            ]);

            // Create transaction
            $fee = (int)($product->price * 0.03); // 3% fee
            DB::table('transactions')->insert([
                'id' => $transactionId,
                'charge_id' => $chargeId,
                'acquirer_id' => $acquirer->id,
                'type' => 'sale',
                'status' => 'approved',
                'amount' => $product->price,
                'fee' => $fee,
                'net_amount' => $product->price - $fee,
                'acquirer_tid' => 'tid_test_' . Str::random(10),
                'acquirer_nsu' => 'nsu_' . rand(100000, 999999),
                'authorization_code' => rand(100000, 999999),
                'card_brand' => 'visa',
                'card_last_digits' => '4242',
                'installments' => 1,
                'gateway_response' => json_encode(['success' => true]),
                'processed_at' => $now->copy()->subHours($i),
                'created_at' => $now->copy()->subHours($i),
                'updated_at' => $now,
            ]);

            $this->command->info("✅ Venda #{$i} criada para o produto {$productSlug}");
        }

        $this->command->info('✅ 2 vendas de teste criadas com sucesso!');

        // Update or create wallet with available balance
        $totalNetAmount = 0;
        $charges = DB::table('charges')
            ->where('account_id', $account->id)
            ->where('status', 'approved')
            ->get();
        
        foreach ($charges as $charge) {
            $transaction = DB::table('transactions')
                ->where('charge_id', $charge->id)
                ->where('status', 'approved')
                ->first();
            if ($transaction) {
                $totalNetAmount += $transaction->net_amount;
            }
        }

        $existingWallet = DB::table('account_wallets')
            ->where('account_id', $account->id)
            ->where('currency', 'BRL')
            ->first();

        if ($existingWallet) {
            DB::table('account_wallets')
                ->where('id', $existingWallet->id)
                ->update([
                    'available_balance' => $totalNetAmount,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('account_wallets')->insert([
                'id' => Str::uuid()->toString(),
                'account_id' => $account->id,
                'currency' => 'BRL',
                'available_balance' => $totalNetAmount,
                'pending_balance' => 0,
                'blocked_balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->command->info('✅ Saldo disponível atualizado: R$ ' . number_format($totalNetAmount / 100, 2, ',', '.'));
    }
}
