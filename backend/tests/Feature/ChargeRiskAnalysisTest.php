<?php

declare(strict_types=1);

use App\Jobs\AnalyzeChargeRiskJob;
use App\Models\Account;
use App\Models\Blacklist;
use App\Models\Charge;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function riskCharge(array $overrides = []): Charge
{
    $accountId = $overrides['account_id'] ?? Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Risk Account',
        'document' => (string) random_int(10000000000, 99999999999),
        'document_type' => 'cpf',
        'email' => Str::random(8) . '@risk.test',
        'status' => 'active',
    ])->id;

    return Charge::create(array_merge([
        'id' => Str::uuid()->toString(),
        'account_id' => $accountId,
        'idempotency_key' => Str::uuid()->toString(),
        'amount' => 4990,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'pending',
        'customer' => ['name' => 'Comprador', 'email' => 'comprador@exemplo.com', 'document' => '52998224725'],
    ], $overrides));
}

test('grava a nota de risco lendo o e-mail do json customer', function () {
    $charge = riskCharge();

    AnalyzeChargeRiskJob::dispatchSync($charge->id, '200.1.2.3');

    $score = DB::table('risk_scores')->where('charge_id', $charge->id)->first();
    expect($score)->not->toBeNull()
        ->and($score->ip_address)->toBe('200.1.2.3')
        ->and($score->recommendation)->toBe('approve');
    expect(json_decode($score->score_breakdown, true)['email_pattern']['flags'])->toContain('first_time_email');
});

test('valor é tratado em centavos', function () {
    $charge = riskCharge(['amount' => 500001]);
    AnalyzeChargeRiskJob::dispatchSync($charge->id);
    $flags = json_decode(DB::table('risk_scores')->where('charge_id', $charge->id)->value('score_breakdown'), true)['amount']['flags'];
    expect($flags)->toContain('high_amount');

    $small = riskCharge(['amount' => 6000]);
    AnalyzeChargeRiskJob::dispatchSync($small->id);
    $flags = json_decode(DB::table('risk_scores')->where('charge_id', $small->id)->value('score_breakdown'), true)['amount']['flags'];
    expect($flags)->toBe([]);
});

test('item da blacklist marca para revisão sem mexer no pagamento', function () {
    $charge = riskCharge();
    Blacklist::create(['type' => 'email', 'value' => 'comprador@exemplo.com', 'is_global' => true, 'reason' => 'teste']);

    AnalyzeChargeRiskJob::dispatchSync($charge->id, '200.1.2.3');

    $charge->refresh();
    expect(DB::table('risk_scores')->where('charge_id', $charge->id)->value('recommendation'))->toBe('decline')
        ->and((bool) DB::table('charges')->where('id', $charge->id)->value('requires_review'))->toBeTrue()
        ->and($charge->status)->toBe('pending');
});

test('velocidade por IP usa os IPs guardados em risk_scores', function () {
    $first = riskCharge(['customer' => ['email' => 'a@exemplo.com']]);
    AnalyzeChargeRiskJob::dispatchSync($first->id, '200.1.2.3');

    foreach (range(1, 11) as $i) {
        $charge = riskCharge(['account_id' => $first->account_id, 'customer' => ['email' => "c{$i}@exemplo.com"]]);
        AnalyzeChargeRiskJob::dispatchSync($charge->id, '200.1.2.3');
    }

    $violations = json_decode(DB::table('risk_scores')->where('charge_id', $charge->id)->value('score_breakdown'), true)['velocity']['violations'];
    expect($violations)->toContain('ip_velocity:11');
});

test('não analisa a mesma cobrança duas vezes', function () {
    $charge = riskCharge();

    AnalyzeChargeRiskJob::dispatchSync($charge->id);
    AnalyzeChargeRiskJob::dispatchSync($charge->id);

    expect(DB::table('risk_scores')->where('charge_id', $charge->id)->count())->toBe(1);
});

test('falha ao agendar a análise não derruba o pagamento', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('redis fora do ar'));
    $charge = riskCharge();

    $method = new ReflectionMethod(PaymentService::class, 'queueRiskAnalysis');
    $method->invoke(app(PaymentService::class), $charge);

    expect($charge->refresh()->status)->toBe('pending');
});
