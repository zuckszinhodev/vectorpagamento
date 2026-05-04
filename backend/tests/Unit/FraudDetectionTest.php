<?php

declare(strict_types=1);

use App\Domain\Fraud\Services\FraudDetectionService;
use App\Domain\Fraud\Services\DeviceFingerprintService;
use App\Domain\Fraud\Services\RiskScoringService;
use App\Models\Account;
use App\Models\Charge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $this->charge = Charge::create([
        'id'              => Str::uuid()->toString(),
        'account_id'      => $this->account->id,
        'amount'          => 10000,
        'currency'        => 'BRL',
        'status'          => 'pending',
        'payment_method'  => 'pix',
        'idempotency_key' => Str::random(32),
        'customer'        => ['name' => 'Test Customer', 'email' => 'customer@test.com', 'document' => '12345678900'],
    ]);

    $deviceService = Mockery::mock(DeviceFingerprintService::class);
    $deviceService->shouldIgnoreMissing(null);

    $riskService = Mockery::mock(RiskScoringService::class);
    $riskService->shouldIgnoreMissing(null);

    $this->service = new FraudDetectionService($deviceService, $riskService);
});

it('has analyzeTransaction method', function () {
    expect(method_exists($this->service, 'analyzeTransaction'))->toBeTrue();
});

it('analyzes transaction and returns array', function () {
    $result = $this->service->analyzeTransaction(
        $this->charge->id,
        $this->account->id,
        [
            'customer_email'    => 'test@test.com',
            'customer_document' => '12345678900',
            'amount'            => 10000,
            'method'            => 'pix',
        ]
    );

    expect($result)->toBeArray();
    expect($result)->toHaveKey('risk_score');
    expect($result)->toHaveKey('risk_level');
    expect($result)->toHaveKey('should_block');
});

it('returns numeric risk score', function () {
    $result = $this->service->analyzeTransaction(
        $this->charge->id,
        $this->account->id,
        [
            'customer_email' => 'clean@test.com',
            'amount'         => 5000,
            'method'         => 'pix',
        ]
    );

    expect($result['risk_score'])->toBeNumeric();
});

it('returns boolean should_block', function () {
    $result = $this->service->analyzeTransaction(
        $this->charge->id,
        $this->account->id,
        [
            'customer_email' => 'test@test.com',
            'amount'         => 10000,
            'method'         => 'pix',
        ]
    );

    expect($result['should_block'])->toBeBool();
});
