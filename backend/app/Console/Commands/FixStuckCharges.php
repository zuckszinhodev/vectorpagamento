<?php

namespace App\Console\Commands;

use App\Infrastructure\Acquirers\PayFortAcquirer;
use App\Models\Charge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FixStuckCharges extends Command
{
    protected $signature = 'charges:fix-stuck
        {--email= : Filter by user/account email (merchant owner) OR customer email}
        {--account= : Filter by account_id}
        {--charge= : Fix a specific charge by ID}
        {--acquirer= : Filter by acquirer (empty = all acquirers)}
        {--status=pending : Filter by status (default: pending). Use "all-pending" for pending+processing+waiting_payment}
        {--dry-run : Show what would be fixed without actually fixing}
        {--force : Skip confirmation prompts}
        {--sync-api : Verify status with acquirer API before fixing}';

    protected $description = 'Fix charges that are paid at the acquirer but stuck as pending locally. Credits wallet and dispatches notifications.';

    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        parent::__construct();
        $this->paymentService = $paymentService;
    }

    public function handle(): int
    {
        $acquirerKey = $this->option('acquirer');
        $email = $this->option('email');
        $accountId = $this->option('account');
        $chargeId = $this->option('charge');
        $statusFilter = $this->option('status');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $syncApi = $this->option('sync-api');

        $this->info("🔍 Buscando charges pendentes...");

        // If a specific charge ID is given, just fix that one
        if ($chargeId) {
            return $this->fixSpecificCharge($chargeId, $dryRun, $force, $syncApi);
        }

        // Resolve email to account_id (user/merchant email)
        if ($email && !$accountId) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $accountId = $user->account_id;
                $this->info("  👤 Usuário encontrado: {$user->name} (account: {$accountId})");
            } else {
                $this->warn("  ⚠ Nenhum usuário encontrado com email: {$email}");
                $this->info("  🔍 Buscando por customer->email no JSON...");
            }
        }

        // Build query for stuck charges
        $query = Charge::query()
            ->whereNotNull('acquirer_transaction_id')
            ->orderByDesc('created_at');

        // Acquirer filter (optional now)
        if ($acquirerKey) {
            $query->where('acquirer', $acquirerKey);
            $this->info("  🏦 Filtro por acquirer: {$acquirerKey}");
        }

        // Status filter
        if ($statusFilter === 'all-pending') {
            $query->whereIn('status', ['pending', 'processing', 'waiting_payment']);
            $this->info("  📊 Status: pending, processing, waiting_payment");
        } else {
            $query->where('status', $statusFilter);
            $this->info("  📊 Status: {$statusFilter}");
        }

        // Account or customer email filter
        if ($accountId) {
            $query->where('account_id', $accountId);
            $this->info("  🏢 Filtro por account: {$accountId}");
        } elseif ($email) {
            // Fallback: search by customer email in JSON field (SECURITY: Using parameterized queries)
            $query->where(function ($q) use ($email) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(customer, '$.email')) = ?", [$email])
                  ->orWhereRaw("customer LIKE ?", ['%"email":"' . addslashes($email) . '"%'])
                  ->orWhereRaw("customer LIKE ?", ['%"email": "' . addslashes($email) . '"%']);
            });
            $this->info("  📧 Filtro por customer email: {$email}");
        }

        $charges = $query->limit(100)->get();

        if ($charges->isEmpty()) {
            $this->info('✅ Nenhuma charge pendente encontrada com esses filtros.');
            $this->newLine();

            // Helpful diagnostics
            if ($email) {
                $this->info('💡 Diagnóstico:');
                $user = User::where('email', $email)->first();
                if ($user) {
                    $totalCharges = Charge::where('account_id', $user->account_id)->count();
                    $pendingCharges = Charge::where('account_id', $user->account_id)->where('status', 'pending')->count();
                    $approvedCharges = Charge::where('account_id', $user->account_id)->where('status', 'approved')->count();
                    $this->info("  Usuário: {$user->name} | Account: {$user->account_id}");
                    $this->info("  Total charges: {$totalCharges} | Pending: {$pendingCharges} | Approved: {$approvedCharges}");

                    if ($acquirerKey) {
                        $acqPending = Charge::where('account_id', $user->account_id)
                            ->where('acquirer', $acquirerKey)
                            ->where('status', 'pending')
                            ->count();
                        $acqAll = Charge::where('account_id', $user->account_id)
                            ->where('acquirer', $acquirerKey)
                            ->count();
                        $this->info("  Charges {$acquirerKey}: {$acqAll} total | {$acqPending} pending");
                    }

                    // Show all statuses breakdown
                    $statusBreakdown = Charge::where('account_id', $user->account_id)
                        ->select('status', DB::raw('count(*) as count'))
                        ->groupBy('status')
                        ->pluck('count', 'status');
                    $this->info("  Status breakdown: " . json_encode($statusBreakdown));

                    // Show acquirers
                    $acquirerBreakdown = Charge::where('account_id', $user->account_id)
                        ->where('status', 'pending')
                        ->select('acquirer', DB::raw('count(*) as count'))
                        ->groupBy('acquirer')
                        ->pluck('count', 'acquirer');
                    if ($acquirerBreakdown->isNotEmpty()) {
                        $this->info("  Pending por acquirer: " . json_encode($acquirerBreakdown));
                    }
                }
            }
            return 0;
        }

        $this->info("📋 Encontradas {$charges->count()} charges pendentes:");
        $this->newLine();

        // Build acquirer for API checks if needed
        $payfortAcquirer = null;
        if ($syncApi) {
            $apiKey = config('acquirers.payfort.api_key');
            $apiSecret = config('acquirers.payfort.api_secret');
            $baseUrl = config('acquirers.payfort.base_url', 'https://api.payfortbank.com');

            if ($apiKey && $apiSecret) {
                $payfortAcquirer = new PayFortAcquirer([
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                    'base_url' => $baseUrl,
                    'webhook_secret' => config('acquirers.payfort.webhook_secret', ''),
                ]);
            } else {
                $this->warn('⚠ Credenciais PayFort não configuradas — sync-api disponível apenas para PayFort');
            }
        }

        $toFix = collect();

        foreach ($charges as $charge) {
            $customer = $charge->customer ?? [];
            $customerEmail = $customer['email'] ?? 'N/A';
            $customerName = $customer['name'] ?? 'N/A';

            $this->line("─────────────────────────────────────────");
            $this->info("  Charge ID:    {$charge->id}");
            $this->info("  Valor:        R$ " . number_format($charge->amount / 100, 2, ',', '.'));
            $this->info("  Cliente:      {$customerName} ({$customerEmail})");
            $this->info("  Criado em:    {$charge->created_at}");
            $this->info("  Transaction:  {$charge->acquirer_transaction_id}");
            $this->info("  Status local: {$charge->status}");

            $paidAtAcquirer = false;
            $acquirerStatus = 'N/A';
            $paidAt = null;

            if ($syncApi && $charge->acquirer === 'payfort' && $payfortAcquirer) {
                $result = $payfortAcquirer->getTransaction($charge->acquirer_transaction_id);
                if ($result['success']) {
                    $acquirerStatus = $result['status'] ?? 'unknown';
                    $this->info("  Status API:   {$acquirerStatus}");

                    if (in_array(strtolower($acquirerStatus), ['paid', 'completed', 'approved', 'confirmed', 'settled'])) {
                        $paidAtAcquirer = true;
                        $paidAt = $result['data']['paidAt'] ?? null;
                        $this->warn("  ⚠ PAGO na adquirente mas PENDENTE localmente!");
                    } else {
                        $this->info("  ℹ Também pendente na adquirente — ignorando.");
                        continue;
                    }
                } else {
                    $this->warn("  ⚠ Não foi possível consultar API: {$result['error']}");
                    $this->info("  ℹ Pulando esta charge (use sem --sync-api para forçar).");
                    continue;
                }
            } elseif ($syncApi && $charge->acquirer !== 'payfort') {
                $this->warn("  ⚠ --sync-api não suportado para acquirer '{$charge->acquirer}' — incluindo na lista.");
                $paidAtAcquirer = true;
            } else {
                // Without API sync, assume all pending charges need fixing
                // (the user explicitly filtered for this)
                $paidAtAcquirer = true;
            }

            if ($paidAtAcquirer) {
                $toFix->push([
                    'charge' => $charge,
                    'paid_at' => $paidAt,
                ]);
            }
        }

        $this->newLine();
        $this->line("═════════════════════════════════════════");
        $this->info("📊 Total para corrigir: {$toFix->count()} charges");

        if ($toFix->isEmpty()) {
            $this->info('✅ Nenhuma charge precisa ser corrigida.');
            return 0;
        }

        $totalAmount = $toFix->sum(fn($item) => $item['charge']->amount);
        $this->info("💰 Valor total: R$ " . number_format($totalAmount / 100, 2, ',', '.'));

        if ($dryRun) {
            $this->warn('🏃 Modo DRY-RUN — nenhuma alteração foi feita.');
            return 0;
        }

        if (!$force && !$this->confirm('Deseja corrigir essas charges? (status → approved + creditar wallet + notificações)', false)) {
            $this->info('Operação cancelada.');
            return 0;
        }

        // Fix each charge
        $fixed = 0;
        $errors = 0;

        foreach ($toFix as $item) {
            $charge = $item['charge'];
            $paidAt = $item['paid_at'];

            try {
                // Step 1: Update charge status
                DB::transaction(function () use ($charge, $paidAt) {
                    $lockedCharge = Charge::lockForUpdate()->find($charge->id);
                    if (!$lockedCharge || $lockedCharge->status === 'approved') {
                        return;
                    }

                    $lockedCharge->update([
                        'status' => 'approved',
                        'paid_at' => $paidAt ? \Carbon\Carbon::parse($paidAt) : now(),
                    ]);

                    // Update transaction record
                    Transaction::where('charge_id', $lockedCharge->id)
                        ->where('type', 'authorization')
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'approved',
                            'type' => 'capture',
                        ]);
                });

                $charge->refresh();

                if ($charge->status !== 'approved') {
                    $this->warn("  ⚠ Charge {$charge->id}: status não foi atualizado (possivelmente já aprovada).");
                    continue;
                }

                // Step 2: Credit wallet (if not already credited)
                try {
                    if (!$charge->net_amount || $charge->net_amount <= 0) {
                        $this->paymentService->updateWalletOnPayment($charge);
                        $this->info("  💳 Wallet creditada para charge {$charge->id}");
                    } else {
                        $this->info("  💳 Wallet já creditada para charge {$charge->id} (net_amount: {$charge->net_amount})");
                    }
                } catch (\Exception $e) {
                    $this->error("  ❌ Erro ao creditar wallet: {$e->getMessage()}");
                    Log::error('FixStuckCharges: Wallet credit failed', [
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Step 3: Update checkout analytics
                try {
                    $this->paymentService->updateCheckoutOnPayment($charge);
                } catch (\Exception $e) {
                    // Non-critical
                    Log::warning('FixStuckCharges: Checkout update failed', [
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Step 4: Dispatch notifications
                \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.approved');

                $this->info("  ✅ Charge {$charge->id} corrigida → approved");
                $fixed++;

                Log::info('FixStuckCharges: Charge fixed', [
                    'charge_id' => $charge->id,
                    'amount' => $charge->amount,
                    'customer_email' => $charge->customer['email'] ?? 'N/A',
                ]);
            } catch (\Exception $e) {
                $this->error("  ❌ Erro ao corrigir charge {$charge->id}: {$e->getMessage()}");
                Log::error('FixStuckCharges: Fix failed', [
                    'charge_id' => $charge->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errors++;
            }
        }

        $this->newLine();
        $this->line("═════════════════════════════════════════");
        $this->info("📊 Resultado: {$fixed} corrigidas, {$errors} erros");

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Fix a specific charge by ID
     */
    private function fixSpecificCharge(string $chargeId, bool $dryRun, bool $force, bool $syncApi): int
    {
        $charge = Charge::find($chargeId);
        if (!$charge) {
            // Try partial match
            $charge = Charge::where('id', 'like', $chargeId . '%')->first();
        }

        if (!$charge) {
            $this->error("Charge não encontrada: {$chargeId}");
            return 1;
        }

        $customer = $charge->customer ?? [];
        $this->info("  Charge ID:    {$charge->id}");
        $this->info("  Valor:        R$ " . number_format($charge->amount / 100, 2, ',', '.'));
        $this->info("  Cliente:      " . ($customer['name'] ?? 'N/A') . " (" . ($customer['email'] ?? 'N/A') . ")");
        $this->info("  Acquirer:     {$charge->acquirer}");
        $this->info("  Status local: {$charge->status}");
        $this->info("  Transaction:  {$charge->acquirer_transaction_id}");
        $this->info("  Net amount:   {$charge->net_amount}");

        if ($charge->status === 'approved') {
            $this->info('✅ Charge já está como approved.');

            // Check if wallet was credited
            if (!$charge->net_amount || $charge->net_amount <= 0) {
                $this->warn('⚠ Wallet possivelmente NÃO creditada (net_amount vazio).');
                if ($force || $this->confirm('Creditar wallet agora?', true)) {
                    $this->paymentService->updateWalletOnPayment($charge);
                    $charge->refresh();
                    $this->info("  💳 Wallet creditada (net_amount: {$charge->net_amount})");
                }
            }
            return 0;
        }

        if ($dryRun) {
            $this->warn('🏃 DRY-RUN — nenhuma alteração.');
            return 0;
        }

        if (!$force && !$this->confirm('Corrigir esta charge para approved?', false)) {
            return 0;
        }

        // Fix it
        DB::transaction(function () use ($charge) {
            $lockedCharge = Charge::lockForUpdate()->find($charge->id);
            if (!$lockedCharge || $lockedCharge->status === 'approved') {
                return;
            }

            $lockedCharge->update([
                'status' => 'approved',
                'paid_at' => now(),
            ]);

            Transaction::where('charge_id', $lockedCharge->id)
                ->where('type', 'authorization')
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'type' => 'capture']);
        });

        $charge->refresh();

        if ($charge->status === 'approved') {
            try {
                $this->paymentService->updateWalletOnPayment($charge);
                $charge->refresh();
                $this->info("  💳 Wallet creditada (net_amount: {$charge->net_amount})");
            } catch (\Exception $e) {
                $this->error("  ❌ Wallet: {$e->getMessage()}");
            }

            try {
                $this->paymentService->updateCheckoutOnPayment($charge);
            } catch (\Exception $e) {
                // non-critical
            }

            \App\Jobs\DispatchPaymentNotificationsJob::dispatch($charge->id, 'payment.approved');
            $this->info("  ✅ Charge corrigida → approved");
        }

        return 0;
    }
}
