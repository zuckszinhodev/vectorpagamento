<?php

namespace App\Console\Commands;

use App\Infrastructure\Acquirers\PayFortAcquirer;
use App\Models\Charge;
use Illuminate\Console\Command;

class CheckPayFortTransaction extends Command
{
    protected $signature = 'payfort:check-transaction
        {charge_id? : Charge ID or acquirer_transaction_id to check}
        {--all-pending : Check all pending PayFort charges}';

    protected $description = 'Check transaction status at PayFort Bank and optionally sync';

    public function handle(): int
    {
        $apiKey = config('acquirers.payfort.api_key');
        $apiSecret = config('acquirers.payfort.api_secret');
        $baseUrl = config('acquirers.payfort.base_url', 'https://api.payfortbank.com');

        if (empty($apiKey) || empty($apiSecret)) {
            $this->error('PayFort credentials not configured.');
            return 1;
        }

        $acquirer = new PayFortAcquirer([
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'base_url' => $baseUrl,
            'webhook_secret' => config('acquirers.payfort.webhook_secret', ''),
        ]);

        if ($this->option('all-pending')) {
            return $this->checkAllPending($acquirer);
        }

        $chargeId = $this->argument('charge_id');
        if (!$chargeId) {
            $this->error('Please provide a charge_id or use --all-pending');
            return 1;
        }

        // Try as full UUID first, then partial match
        $charge = Charge::find($chargeId);
        if (!$charge && strlen($chargeId) < 36) {
            $charge = Charge::where('id', 'like', $chargeId . '%')->first();
            if ($charge) {
                $this->info("Matched partial ID to: {$charge->id}");
            }
        }
        $transactionId = $charge ? $charge->acquirer_transaction_id : $chargeId;

        $this->info("Checking transaction: {$transactionId}");
        if ($charge) {
            $this->info("Charge: {$charge->id} | Status: {$charge->status} | Acquirer: {$charge->acquirer}");
        }

        $result = $acquirer->getTransaction($transactionId);

        if ($result['success']) {
            $this->info("PayFort Status: {$result['status']}");
            $this->line(json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn("Could not fetch: {$result['error']}");
        }

        return 0;
    }

    private function checkAllPending(PayFortAcquirer $acquirer): int
    {
        $charges = Charge::where('acquirer', 'payfort')
            ->where('status', 'pending')
            ->whereNotNull('acquirer_transaction_id')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $this->info("Found {$charges->count()} pending PayFort charges");

        foreach ($charges as $charge) {
            $this->newLine();
            $this->info("Charge: {$charge->id}");
            $this->info("  Transaction: {$charge->acquirer_transaction_id}");
            $this->info("  Created: {$charge->created_at}");

            $result = $acquirer->getTransaction($charge->acquirer_transaction_id);

            if ($result['success']) {
                $this->info("  PayFort Status: {$result['status']}");
                
                if (in_array($result['status'], ['paid', 'completed', 'approved', 'confirmed'])) {
                    $this->warn("  ⚠ PAID at PayFort but PENDING locally!");
                    
                    if ($this->confirm("  Sync this charge to approved?", false)) {
                        $charge->update([
                            'status' => 'approved',
                            'paid_at' => $result['data']['paidAt'] ?? now(),
                        ]);
                        $this->info("  ✓ Updated to approved");
                    }
                }
            } else {
                $this->warn("  Could not fetch: {$result['error']}");
            }
        }

        return 0;
    }
}
