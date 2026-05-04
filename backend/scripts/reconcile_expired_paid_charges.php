<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Charge;
use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv, true);
$accountId = null;
$limit = 500;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--account=')) {
        $accountId = substr($arg, 10);
    }

    if (str_starts_with($arg, '--limit=')) {
        $limitValue = (int) substr($arg, 8);
        if ($limitValue > 0) {
            $limit = $limitValue;
        }
    }
}

$query = Charge::query()
    ->where('status', 'expired')
    ->whereExists(function ($subQuery) {
        $subQuery->select(DB::raw(1))
            ->from('transactions')
            ->whereColumn('transactions.charge_id', 'charges.id')
            ->where('transactions.status', 'approved');
    })
    ->orderBy('created_at');

if ($accountId) {
    $query->where('account_id', $accountId);
}

$candidateIds = $query->limit($limit)->pluck('id')->toArray();

$result = [
    'dry_run' => $dryRun,
    'filters' => [
        'account_id' => $accountId,
        'limit' => $limit,
    ],
    'candidates_count' => count($candidateIds),
    'candidate_ids_preview' => array_slice($candidateIds, 0, 20),
    'updated_count' => 0,
];

if (!$dryRun && !empty($candidateIds)) {
    DB::transaction(function () use ($candidateIds, &$result): void {
        foreach ($candidateIds as $chargeId) {
            $paidAt = DB::table('transactions')
                ->where('charge_id', $chargeId)
                ->where('status', 'approved')
                ->orderBy('processed_at')
                ->value('processed_at');

            $affected = DB::table('charges')
                ->where('id', $chargeId)
                ->where('status', 'expired')
                ->update([
                    'status' => 'approved',
                    'paid_at' => $paidAt ?: now(),
                    'failure_reason' => null,
                    'updated_at' => now(),
                ]);

            if ($affected > 0) {
                $result['updated_count']++;
            }
        }
    });
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
