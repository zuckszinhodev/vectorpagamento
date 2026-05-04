<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$inputIds = [
    '13e8ac2f-4881-4819-b9e1-38976ca96c24',
    'd114dcb6-1385-4595-81be-c69d50dbcbd1',
    '63af2bd7-24e2-46a1-9a3d-2fe0c4544dda',
];

$targetEmail = 'vectorpagamento@gmail.com';

$user = \App\Models\User::where('email', $targetEmail)->first();
if (!$user || !$user->account) {
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'User/account not found for target email',
            'email' => $targetEmail,
        ],
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$accountId = $user->account->id;

try {
    $result = DB::transaction(function () use ($inputIds, $accountId, $targetEmail) {
        $now = now();

        $chargeIdsFromInput = DB::table('charges')
            ->where('account_id', $accountId)
            ->whereIn('id', $inputIds)
            ->pluck('id')
            ->toArray();

        $transactionIdsFromInput = DB::table('transactions')
            ->whereIn('id', $inputIds)
            ->pluck('id')
            ->toArray();

        $transactionIdsFromChargeIds = [];
        if (!empty($chargeIdsFromInput)) {
            $transactionIdsFromChargeIds = DB::table('transactions')
                ->whereIn('charge_id', $chargeIdsFromInput)
                ->pluck('id')
                ->toArray();
        }

        $transactionIdsToUpdate = array_values(array_unique(array_merge(
            $transactionIdsFromInput,
            $transactionIdsFromChargeIds
        )));

        $relatedChargeIds = [];
        if (!empty($transactionIdsToUpdate)) {
            $relatedChargeIds = DB::table('transactions')
                ->whereIn('id', $transactionIdsToUpdate)
                ->pluck('charge_id')
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        $chargeIdsToUpdate = array_values(array_unique(array_merge($chargeIdsFromInput, $relatedChargeIds)));

        $updatedTransactions = 0;
        if (!empty($transactionIdsToUpdate)) {
            $updatedTransactions = DB::table('transactions')
                ->whereIn('id', $transactionIdsToUpdate)
                ->update([
                    'status' => 'approved',
                    'type' => 'capture',
                    'processed_at' => DB::raw("COALESCE(processed_at, '{$now->toDateTimeString()}')"),
                    'updated_at' => $now,
                ]);
        }

        $updatedCharges = 0;

        if (!empty($chargeIdsToUpdate)) {
            $updatedCharges = DB::table('charges')
                ->where('account_id', $accountId)
                ->whereIn('id', $chargeIdsToUpdate)
                ->update([
                    'status' => 'approved',
                    'paid_at' => DB::raw("COALESCE(paid_at, '{$now->toDateTimeString()}')"),
                    'updated_at' => $now,
                ]);
        }

        return [
            'target_email' => $targetEmail,
            'target_account_id' => $accountId,
            'input_ids' => $inputIds,
            'transaction_ids_to_update' => $transactionIdsToUpdate,
            'charge_ids_to_update' => $chargeIdsToUpdate,
            'updated_transactions_count' => $updatedTransactions,
            'updated_charges_count' => $updatedCharges,
            'transactions' => DB::table('transactions')
                ->whereIn('id', $transactionIdsToUpdate)
                ->get(['id', 'status', 'type', 'processed_at', 'charge_id']),
            'charges' => DB::table('charges')
                ->where('account_id', $accountId)
                ->whereIn('id', $chargeIdsToUpdate)
                ->get(['id', 'status', 'paid_at', 'account_id']),
        ];
    });

    echo json_encode(['success' => true, 'data' => $result], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ],
    ], JSON_PRETTY_PRINT) . PHP_EOL;

    exit(1);
}
