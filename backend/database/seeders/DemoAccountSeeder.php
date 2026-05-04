<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $email = 'felipeinew@hotmail.com';

        // Check if account already exists
        $existingAccount = DB::table('accounts')->where('email', $email)->first();

        if ($existingAccount) {
            $accountId = $existingAccount->id;

            // Mark as demo
            DB::table('accounts')->where('id', $accountId)->update([
                'is_demo' => true,
                'status' => 'active',
                'updated_at' => $now,
            ]);

            // Update user
            $ownerRole = DB::table('roles')->where('slug', 'owner')->first();
            DB::table('users')->where('account_id', $accountId)->update([
                'role_id' => $ownerRole?->id,
                'password' => Hash::make('Demo@2026!'),
                'kyc_status' => 'approved',
                'kyc_submitted_at' => $now,
                'kyc_approved_at' => $now,
                'kyc_rejection_reason' => null,
                'email_verified_at' => $now,
                'status' => 'active',
                'updated_at' => $now,
            ]);

            $this->command->info("✅ Conta demo {$email} já existia — atualizada.");
        } else {
            $accountId = Str::uuid()->toString();
            $userId = Str::uuid()->toString();
            $ownerRole = DB::table('roles')->where('slug', 'owner')->first();

            DB::table('accounts')->insert([
                'id' => $accountId,
                'name' => 'Felipe Demo Account',
                'document' => '98765432100',
                'document_type' => 'cpf',
                'email' => $email,
                'phone' => '11987654321',
                'status' => 'active',
                'is_demo' => true,
                'settings' => '{}',
                'metadata' => '{}',
                'created_at' => $now->copy()->subMonths(3),
                'updated_at' => $now,
            ]);

            DB::table('users')->insert([
                'id' => $userId,
                'account_id' => $accountId,
                'role_id' => $ownerRole?->id,
                'email' => $email,
                'password' => Hash::make('Demo@2026!'),
                'name' => 'Felipe',
                'phone' => '11987654321',
                'email_verified_at' => $now,
                'mfa_enabled' => false,
                'mfa_secret' => null,
                'kyc_status' => 'approved',
                'kyc_submitted_at' => $now->copy()->subMonths(3),
                'kyc_approved_at' => $now->copy()->subMonths(3),
                'kyc_rejection_reason' => null,
                'status' => 'active',
                'last_login_at' => $now->copy()->subHours(2),
                'last_login_ip' => '177.38.42.195',
                'remember_token' => null,
                'created_at' => $now->copy()->subMonths(3),
                'updated_at' => $now,
            ]);

            $this->command->info("✅ Conta demo {$email} criada.");
        }

        // Get first acquirer
        $acquirer = DB::table('acquirers')->first();
        if (!$acquirer) {
            $this->command->error('❌ Nenhum acquirer encontrado. Rode AcquirerSeeder primeiro.');
            return;
        }

        // Get category
        $category = DB::table('categories')->where('slug', 'digital-products')->first();

        // ─── PRODUCTS ────────────────────────────────────────────────
        // Delete old demo products for this account (idempotent)
        DB::table('products')->where('account_id', $accountId)->delete();

        $approvedProducts = [
            ['name' => 'Método Expert Digital 3.0', 'slug' => 'demo-expert-digital-30-' . substr($accountId, 0, 8), 'price' => 19700, 'original_price' => 29700, 'type' => 'digital', 'description' => 'Curso completo de marketing digital com mais de 200 aulas práticas'],
            ['name' => 'Fórmula de Lançamento PRO', 'slug' => 'demo-formula-lancamento-pro-' . substr($accountId, 0, 8), 'price' => 99700, 'original_price' => 149700, 'type' => 'digital', 'description' => 'Domine a arte de lançamentos digitais milionários'],
            ['name' => 'Pack Templates Canva Premium', 'slug' => 'demo-pack-canva-premium-' . substr($accountId, 0, 8), 'price' => 4990, 'original_price' => 9990, 'type' => 'digital', 'description' => '+500 templates editáveis para redes sociais'],
            ['name' => 'Mentoria Tráfego Pago Elite', 'slug' => 'demo-mentoria-trafego-elite-' . substr($accountId, 0, 8), 'price' => 297000, 'original_price' => 497000, 'type' => 'service', 'description' => 'Mentoria individual de 6 meses com acompanhamento semanal'],
            ['name' => 'E-book Copywriting Irresistível', 'slug' => 'demo-ebook-copy-' . substr($accountId, 0, 8), 'price' => 2990, 'original_price' => 4990, 'type' => 'digital', 'description' => 'Aprenda a escrever textos que vendem todos os dias'],
            ['name' => 'Curso SEO Avançado 2026', 'slug' => 'demo-seo-avancado-2026-' . substr($accountId, 0, 8), 'price' => 39700, 'original_price' => 59700, 'type' => 'digital', 'description' => 'Rankeie no Google e gere tráfego orgânico infinito'],
            ['name' => 'Kit Social Media Completo', 'slug' => 'demo-kit-socialmedia-' . substr($accountId, 0, 8), 'price' => 14700, 'original_price' => 19700, 'type' => 'digital', 'description' => 'Scripts, calendários e estratégias para social media'],
            ['name' => 'Workshop Funis de Venda', 'slug' => 'demo-workshop-funis-' . substr($accountId, 0, 8), 'price' => 7990, 'original_price' => 14990, 'type' => 'digital', 'description' => 'Workshop ao vivo gravado sobre funis de alta conversão'],
        ];

        $pendingProducts = [
            ['name' => 'Curso IA para Negócios (Em Revisão)', 'slug' => 'demo-ia-negocios-' . substr($accountId, 0, 8), 'price' => 49700, 'original_price' => 79700, 'type' => 'digital', 'description' => 'Como usar inteligência artificial para escalar seu negócio'],
            ['name' => 'Planilha Financeira Master (Aguardando)', 'slug' => 'demo-planilha-financeira-' . substr($accountId, 0, 8), 'price' => 9990, 'original_price' => 14990, 'type' => 'digital', 'description' => 'Planilha completa de controle financeiro empresarial'],
            ['name' => 'Comunidade Empreendedores VIP (Pendente)', 'slug' => 'demo-comunidade-vip-' . substr($accountId, 0, 8), 'price' => 19700, 'original_price' => 29700, 'type' => 'service', 'description' => 'Acesso vitalício à comunidade exclusiva de empreendedores'],
        ];

        $productIds = [];

        foreach ($approvedProducts as $p) {
            $id = Str::uuid()->toString();
            $productIds[] = $id;
            DB::table('products')->insert([
                'id' => $id,
                'account_id' => $accountId,
                'category_id' => $category?->id,
                'name' => $p['name'],
                'slug' => $p['slug'],
                'description' => $p['description'],
                'type' => $p['type'],
                'price' => $p['price'],
                'original_price' => $p['original_price'],
                'currency' => 'BRL',
                'commission_percent' => rand(5, 30),
                'commission_fixed' => 0,
                'affiliate_enabled' => rand(0, 1) === 1,
                'stock_enabled' => false,
                'images' => '[]',
                'metadata' => '{}',
                'is_active' => true,
                'created_at' => $now->copy()->subDays(rand(10, 80)),
                'updated_at' => $now,
            ]);
        }

        foreach ($pendingProducts as $p) {
            $id = Str::uuid()->toString();
            DB::table('products')->insert([
                'id' => $id,
                'account_id' => $accountId,
                'category_id' => $category?->id,
                'name' => $p['name'],
                'slug' => $p['slug'],
                'description' => $p['description'],
                'type' => $p['type'],
                'price' => $p['price'],
                'original_price' => $p['original_price'],
                'currency' => 'BRL',
                'commission_percent' => 10,
                'commission_fixed' => 0,
                'affiliate_enabled' => false,
                'stock_enabled' => false,
                'images' => '[]',
                'metadata' => '{}',
                'is_active' => false, // PENDENTE — não aprovado
                'created_at' => $now->copy()->subDays(rand(1, 5)),
                'updated_at' => $now,
            ]);
        }

        $this->command->info('✅ ' . count($approvedProducts) . ' produtos aprovados + ' . count($pendingProducts) . ' pendentes criados.');

        // ─── CHARGES (DEMO SALES) ─────────────────────────────────────
        // Delete old demo charges for this account (idempotent)
        $existingChargeIds = DB::table('charges')->where('account_id', $accountId)->pluck('id');
        if ($existingChargeIds->isNotEmpty()) {
            DB::table('transactions')->whereIn('charge_id', $existingChargeIds)->delete();
            DB::table('charges')->where('account_id', $accountId)->delete();
        }

        // Target: ~R$ 1.047.832,57 de faturamento (approved charges sum)
        // We'll create varied charges across different products, dates and methods
        $targetRevenueCents = 104783257; // R$ 1.047.832,57
        $totalChargedCents = 0;
        $chargesCreated = 0;

        $paymentMethods = ['pix', 'credit_card', 'credit_card', 'credit_card', 'pix', 'boleto'];
        $cardBrands = ['visa', 'mastercard', 'elo', 'amex'];
        $names = [
            'João Silva', 'Maria Oliveira', 'Carlos Santos', 'Ana Costa', 'Pedro Souza',
            'Juliana Lima', 'Rafael Almeida', 'Fernanda Rocha', 'Lucas Martins', 'Camila Ferreira',
            'Bruno Ribeiro', 'Larissa Gomes', 'Thiago Barbosa', 'Isabela Dias', 'Gabriel Araújo',
            'Mariana Castro', 'Felipe Mendes', 'Valentina Nunes', 'Gustavo Cardoso', 'Beatriz Pereira',
            'Daniel Correia', 'Letícia Moreira', 'Mateus Carvalho', 'Sophia Teixeira', 'Arthur Monteiro',
        ];

        while ($totalChargedCents < $targetRevenueCents) {
            $productId = $productIds[array_rand($productIds)];
            $product = DB::table('products')->where('id', $productId)->first();

            // Vary amounts: sometimes full price, sometimes with small random variation
            $amount = $product->price;

            // Check if adding this would exceed target by too much
            if ($totalChargedCents + $amount > $targetRevenueCents + 50000) {
                // Create a charge with the remaining amount
                $amount = $targetRevenueCents - $totalChargedCents;
                if ($amount < 100) break; // Don't create charges < R$1
            }

            $chargeId = Str::uuid()->toString();
            $transactionId = Str::uuid()->toString();
            $method = $paymentMethods[array_rand($paymentMethods)];
            $customerName = $names[array_rand($names)];
            $dayOffset = rand(1, 90);
            $hourOffset = rand(0, 23);
            $chargeDate = $now->copy()->subDays($dayOffset)->setHour($hourOffset)->setMinute(rand(0, 59));
            $fee = (int)($amount * 0.0399); // ~3.99% fee
            $installments = $method === 'credit_card' ? [1, 1, 1, 2, 3, 6, 12][array_rand([1, 1, 1, 2, 3, 6, 12])] : 1;

            DB::table('charges')->insert([
                'id' => $chargeId,
                'account_id' => $accountId,
                'product_id' => $productId,
                'idempotency_key' => 'demo_' . Str::random(20) . '_' . $chargesCreated,
                'amount' => $amount,
                'fee_amount' => $fee,
                'net_amount' => $amount - $fee,
                'currency' => 'BRL',
                'payment_method' => $method,
                'status' => 'approved',
                'installments' => $installments,
                'customer' => json_encode([
                    'name' => $customerName,
                    'email' => strtolower(str_replace(' ', '.', $customerName)) . rand(10, 99) . '@email.com',
                    'document' => str_pad((string)rand(10000000000, 99999999999), 11, '0', STR_PAD_LEFT),
                    'phone' => '11' . rand(900000000, 999999999),
                ]),
                'billing' => json_encode([
                    'address' => [
                        'street' => 'Rua Demonstração',
                        'number' => (string)rand(1, 2000),
                        'city' => ['São Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Curitiba', 'Salvador'][array_rand(['São Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Curitiba', 'Salvador'])],
                        'state' => ['SP', 'RJ', 'MG', 'PR', 'BA'][array_rand(['SP', 'RJ', 'MG', 'PR', 'BA'])],
                        'zip_code' => str_pad((string)rand(1000000, 99999999), 8, '0', STR_PAD_LEFT),
                        'country' => 'BR',
                    ],
                ]),
                'metadata' => '{}',
                'paid_at' => $chargeDate,
                'created_at' => $chargeDate,
                'updated_at' => $chargeDate,
            ]);

            // Create matching transaction
            DB::table('transactions')->insert([
                'id' => $transactionId,
                'charge_id' => $chargeId,
                'acquirer_id' => $acquirer->id,
                'type' => 'sale',
                'status' => 'approved',
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $amount - $fee,
                'acquirer_tid' => 'demo_tid_' . Str::random(12),
                'acquirer_nsu' => 'demo_nsu_' . rand(100000, 999999),
                'authorization_code' => (string)rand(100000, 999999),
                'card_brand' => $method === 'credit_card' ? $cardBrands[array_rand($cardBrands)] : null,
                'card_last_digits' => $method === 'credit_card' ? str_pad((string)rand(1000, 9999), 4, '0', STR_PAD_LEFT) : null,
                'installments' => $installments,
                'gateway_response' => json_encode(['success' => true, 'demo' => true]),
                'processed_at' => $chargeDate,
                'created_at' => $chargeDate,
                'updated_at' => $chargeDate,
            ]);

            $totalChargedCents += $amount;
            $chargesCreated++;
        }

        $this->command->info("✅ {$chargesCreated} vendas demo criadas — Faturado: R$ " . number_format($totalChargedCents / 100, 2, ',', '.'));

        // ─── WALLET ──────────────────────────────────────────────────
        // Available: ~R$ 637.284,93  |  Pending: remainder of net
        $availableCents = 63728493; // R$ 637.284,93
        $pendingCents = 18453761;   // R$ 184.537,61 (pendente de liquidação)
        $blockedCents = 892347;     // R$ 8.923,47 (bloqueado por disputa)

        $existingWallet = DB::table('account_wallets')
            ->where('account_id', $accountId)
            ->where('currency', 'BRL')
            ->first();

        if ($existingWallet) {
            DB::table('account_wallets')->where('id', $existingWallet->id)->update([
                'available_balance' => $availableCents,
                'pending_balance' => $pendingCents,
                'blocked_balance' => $blockedCents,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('account_wallets')->insert([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'currency' => 'BRL',
                'available_balance' => $availableCents,
                'pending_balance' => $pendingCents,
                'blocked_balance' => $blockedCents,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->command->info("✅ Wallet demo: Disponível R$ " . number_format($availableCents / 100, 2, ',', '.') .
            " | Pendente R$ " . number_format($pendingCents / 100, 2, ',', '.') .
            " | Bloqueado R$ " . number_format($blockedCents / 100, 2, ',', '.'));

        $this->command->info('');
        $this->command->info('🎯 Conta demo configurada com sucesso!');
        $this->command->info("   Email: {$email}");
        $this->command->info('   Senha: Demo@2026!');
        $this->command->info("   Faturado: R$ " . number_format($totalChargedCents / 100, 2, ',', '.'));
        $this->command->info("   Disponível: R$ " . number_format($availableCents / 100, 2, ',', '.'));
        $this->command->info("   Produtos aprovados: " . count($approvedProducts));
        $this->command->info("   Produtos pendentes: " . count($pendingProducts));
        $this->command->info("   ⚠️  Conta marcada como is_demo=true (excluída do faturamento admin)");
    }
}
