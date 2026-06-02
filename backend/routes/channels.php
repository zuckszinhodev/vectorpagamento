<?php

use App\Models\Charge;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('account.{accountId}', function ($user, string $accountId) {
    return (string) ($user->account_id ?? '') === $accountId;
});

Broadcast::channel('account.{accountId}.stats', function ($user, string $accountId) {
    return (string) ($user->account_id ?? '') === $accountId;
});

Broadcast::channel('charge.{chargeId}', function ($user, string $chargeId) {
    return Charge::query()
        ->where('id', $chargeId)
        ->where('account_id', $user->account_id ?? null)
        ->exists();
});
