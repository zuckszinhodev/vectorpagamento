<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Charge;
use App\Services\PaymentService;
use Illuminate\Console\Command;

class SyncPendingChargesCommand extends Command
{
    protected $signature = 'charges:sync-pending {--acquirer=sharkhubsub : Acquirer slug or all} {--limit=100 : Maximum charges to sync}';

    protected $description = 'Sync pending charges against acquirers when webhooks were missed';

    public function handle(PaymentService $paymentService): int
    {
        $acquirer = (string) $this->option('acquirer');
        $limit = max(1, (int) $this->option('limit'));

        $query = Charge::query()
            ->whereIn('status', ['pending', 'processing'])
            ->whereNotNull('acquirer_transaction_id')
            ->orderBy('updated_at')
            ->limit($limit);

        if ($acquirer !== 'all') {
            $query->where('acquirer', $acquirer);
        }

        $results = [
            'checked' => 0,
            'synced' => 0,
            'approved' => 0,
            'unchanged' => 0,
        ];

        foreach ($query->get() as $charge) {
            $results['checked']++;
            $previousStatus = (string) $charge->status;
            $sync = $paymentService->syncChargeStatus($charge);
            $charge->refresh();

            if ($sync['synced'] ?? false) {
                $results['synced']++;
            }

            if ($charge->status === 'approved' && $previousStatus !== 'approved') {
                $results['approved']++;
                $this->info("Charge {$charge->id} approved.");
            } elseif ($charge->status === $previousStatus) {
                $results['unchanged']++;
            }
        }

        $this->info('Pending charges sync completed.');
        $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
