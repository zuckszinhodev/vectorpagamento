<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Infrastructure\Acquirers\BabylonAcquirer;
use App\Infrastructure\Acquirers\PayFortAcquirer;
use App\Models\Charge;
use Illuminate\Support\Facades\DB;

$dryRun = !in_array('--apply', $argv, true);
$limit = 20;
$accountId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $parsed = (int) substr($arg, 8);
        if ($parsed > 0) {
            $limit = $parsed;
        }
    }

    if (str_starts_with($arg, '--account=')) {
        $accountId = (string) substr($arg, 10);
    }
}

$payFort = new PayFortAcquirer([
    'api_key' => (string) config('acquirers.payfort.api_key', ''),
    'api_secret' => (string) config('acquirers.payfort.api_secret', ''),
    'base_url' => (string) config('acquirers.payfort.base_url', 'https://api.payfortbank.com'),
    'webhook_secret' => (string) config('acquirers.payfort.webhook_secret', ''),
]);

$babylon = new BabylonAcquirer([
    'secret_key' => (string) config('acquirers.babylon.secret_key', ''),
    'company_id' => (string) config('acquirers.babylon.company_id', ''),
]);

$query = Charge::query()
    ->where('status', 'expired')
    ->whereIn('acquirer', ['payfort', 'babylon'])
    ->whereNotNull('acquirer_transaction_id')
    ->orderByDesc('created_at');

if ($accountId) {
    $query->where('account_id', $accountId);
}

$charges = $query->limit($limit)->get();

$isPaidLike = static function (string $status): bool {
    return in_array(strtolower($status), ['paid', 'approved', 'authorized', 'completed', 'settled'], true);
};

$result = [
    'dry_run' => $dryRun,
    'filters' => [
        'limit' => $limit,
        'account_id' => $accountId,
        'acquirers' => ['payfort', 'babylon'],
    ],
    'checked' => 0,
    'confirmed_paid' => 0,
    'updated' => 0,
    'items' => [],
];

foreach ($charges as $charge) {
    $result['checked']++;

    $apiResponse = ['success' => false, 'error' => 'unsupported acquirer'];
    if ($charge->acquirer === 'payfort') {
        $apiResponse = $payFort->getTransaction((string) $charge->acquirer_transaction_id);
    } elseif ($charge->acquirer === 'babylon') {
        $apiResponse = $babylon->getTransaction((string) $charge->acquirer_transaction_id);
    }

    $remoteStatus = strtolower((string) ($apiResponse['status'] ?? 'unknown'));
    $isPaid = (bool) ($apiResponse['success'] ?? false) && $isPaidLike($remoteStatus);

    $entry = [
        'charge_id' => $charge->id,
        'account_id' => $charge->account_id,
        'acquirer' => $charge->acquirer,
        'acquirer_transaction_id' => $charge->acquirer_transaction_id,
        'local_status' => $charge->status,
        'remote_success' => (bool) ($apiResponse['success'] ?? false),
        'remote_status' => $remoteStatus,
        'will_update' => $isPaid,
        'update_result' => 'skipped',
    ];

    if ($isPaid) {
        $result['confirmed_paid']++;

        if (!$dryRun) {
            $affected = DB::transaction(function () use ($charge): int {
                $locked = Charge::lockForUpdate()->find($charge->id);
                if (!$locked || $locked->status !== 'expired') {
                    return 0;
                }

                $paidAt = DB::table('transactions')
                    ->where('charge_id', $locked->id)
                    ->where('status', 'approved')
                    ->orderBy('processed_at')
                    ->value('processed_at');

                $updatedCharge = DB::table('charges')
                    ->where('id', $locked->id)
                    ->where('status', 'expired')
                    ->update([
                        'status' => 'approved',
                        'paid_at' => $paidAt ?: now(),
                        'failure_reason' => null,
                        'updated_at' => now(),
                    ]);

                if ($updatedCharge > 0) {
                    DB::table('transactions')
                        ->where('charge_id', $locked->id)
                        ->whereIn('status', ['pending', 'processing', 'authorized'])
                        ->update([
                            'status' => 'approved',
                            'type' => DB::raw("CASE WHEN type = 'authorization' THEN 'capture' ELSE type END"),
                            'processed_at' => DB::raw('COALESCE(processed_at, NOW())'),
                            'updated_at' => now(),
                        ]);
                }

                return $updatedCharge;
            });

            if ($affected > 0) {
                $result['updated']++;
                $entry['update_result'] = 'updated';
            } else {
                $entry['update_result'] = 'not_updated';
            }
        }
    }

    if (!($apiResponse['success'] ?? false)) {
        $entry['api_error'] = $apiResponse['error'] ?? 'unknown';
    }

    $result['items'][] = $entry;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
