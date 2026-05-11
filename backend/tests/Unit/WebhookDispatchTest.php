<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Charge;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'account@test.com',
        'status'        => 'active',
    ]);

    $this->user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'email'      => 'user@test.com',
        'password'   => bcrypt('password'),
        'name'       => 'Test User',
        'status'     => 'active',
        'role'       => 'admin',
    ]);

    $this->webhook = Webhook::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'url'        => 'https://example.com/webhook',
        'events'     => ['charge.paid'],
        'secret'     => 'test_secret_key',
        'status'     => 'active',
    ]);

    $this->charge = Charge::create([
        'id'              => Str::uuid()->toString(),
        'account_id'      => $this->account->id,
        'amount'          => 10000,
        'currency'        => 'BRL',
        'status'          => 'approved',
        'payment_method'  => 'pix',
        'idempotency_key' => Str::random(32),
        'customer'        => ['name' => 'Test Customer', 'email' => 'customer@test.com', 'document' => '12345678900'],
    ]);

    $this->service = new WebhookDispatchService();
});

it('dispatches webhook for matching event', function () {
    Http::fake(['https://example.com/webhook' => Http::response(['ok' => true], 200)]);

    $this->service->dispatchPaymentEventSync('charge.paid', $this->charge);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/webhook'
            && $request->hasHeader('X-Webhook-Signature');
    });
});

it('does not dispatch for non-matching event', function () {
    Http::fake();

    $this->service->dispatchPaymentEventSync('charge.refunded', $this->charge);

    Http::assertNothingSent();
});

it('includes signature header in webhook request', function () {
    Http::fake(['https://example.com/webhook' => Http::response(['ok' => true], 200)]);

    $this->service->dispatchPaymentEventSync('charge.paid', $this->charge);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Webhook-Signature');
    });
});
