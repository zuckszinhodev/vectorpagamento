<?php

declare(strict_types=1);

use App\Infrastructure\Acquirers\KokuPayAcquirer;
use App\Models\Charge;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Entrega real publicada em https://docs.kokupay.com/webhooks/assinatura/ (segredo fictício da documentação)
const KOKU_DOC_SECRET = 'whsec_Zq4m8Tn2Vb7Lk1Xc9Rd5Hs3Wf6Jp0Ga2Ye8Ui4Ko7';
const KOKU_DOC_TIMESTAMP = 1791473531;
const KOKU_DOC_HEADER = 't=1791473531,v1=a735379a545698a02021820ddff8136ca623148267e128cf0ab6107e94dc3d96';
const KOKU_DOC_BODY = '{"id":"evt_2f6b1c9e8a4d4e7f9b3c5a1d0e6f8b27","type":"charge.paid","version":"v1","created_at":"2026-10-07T15:32:11.000Z","environment":"production","data":{"charge_id":"8d3f6a12-4b9e-4c71-a2d5-6e0f1b3c9a47","order_reference":"PEDIDO-1001","amount_cents":"15990","fee_cents":"320","net_cents":"15670","reserve_cents":"0","paid_at":"2026-10-07T15:32:10.000Z","end_to_end_id":"E12345678202610071532a1B2c3D4e5F","late_payment":false,"case_id":null}}';

function kokuCharge(array $overrides = []): Charge
{
    $charge = new Charge(array_merge([
        'amount' => 15990,
        'payment_method' => 'pix',
        'description' => 'Pedido 1001',
        'customer' => [
            'name' => 'Maria Souza',
            'email' => 'maria.souza@example.com',
            'document' => '52998224725',
            'phone' => '11987654321',
        ],
    ], $overrides));
    $charge->id = '0b6f1d2a-3c4e-4f50-8a61-7b8c9d0e1f23';

    return $charge;
}

function kokuChargeResponse(array $overrides = []): array
{
    return array_merge([
        'id' => '8d3f6a12-4b9e-4c71-a2d5-6e0f1b3c9a47',
        'order_reference' => '0b6f1d2a-3c4e-4f50-8a61-7b8c9d0e1f23',
        'amount_cents' => '15990',
        'status' => 'pending',
        'expires_at' => '2026-10-07T16:00:00.000Z',
        'pix' => ['qr_code_payload' => '00020101021226850014br.gov.bcb.pix...6304A1B2', 'txid' => null, 'expires_at' => '2026-10-07T16:00:00.000Z'],
        'failure_message' => null,
    ], $overrides);
}

it('aceita a assinatura do exemplo oficial da Koku', function () {
    expect(KokuPayAcquirer::verifySignature(KOKU_DOC_BODY, KOKU_DOC_HEADER, KOKU_DOC_SECRET, KOKU_DOC_TIMESTAMP))->toBeTrue();
});

it('recusa corpo alterado, segredo errado, carimbo fora da tolerância e cabeçalho ausente', function () {
    $tampered = str_replace('"15990"', '"99990"', KOKU_DOC_BODY);

    expect(KokuPayAcquirer::verifySignature($tampered, KOKU_DOC_HEADER, KOKU_DOC_SECRET, KOKU_DOC_TIMESTAMP))->toBeFalse()
        ->and(KokuPayAcquirer::verifySignature(KOKU_DOC_BODY, KOKU_DOC_HEADER, 'whsec_outro', KOKU_DOC_TIMESTAMP))->toBeFalse()
        ->and(KokuPayAcquirer::verifySignature(KOKU_DOC_BODY, KOKU_DOC_HEADER, KOKU_DOC_SECRET, KOKU_DOC_TIMESTAMP + 301))->toBeFalse()
        ->and(KokuPayAcquirer::verifySignature(KOKU_DOC_BODY, null, KOKU_DOC_SECRET, KOKU_DOC_TIMESTAMP))->toBeFalse()
        ->and(KokuPayAcquirer::verifySignature(KOKU_DOC_BODY, KOKU_DOC_HEADER, '', KOKU_DOC_TIMESTAMP))->toBeFalse();
});

it('cria o Pix com order_reference, Idempotency-Key estável e dados do pagador', function () {
    Http::fake(['api.kokupay.com/v1/pix/charges' => Http::response(kokuChargeResponse(), 201)]);

    $result = (new KokuPayAcquirer(['api_key' => 'koku_live_teste']))->generatePixQrCode(kokuCharge());

    expect($result['success'])->toBeTrue()
        ->and($result['transaction_id'])->toBe('8d3f6a12-4b9e-4c71-a2d5-6e0f1b3c9a47')
        ->and($result['pix_copy_paste'])->toStartWith('000201');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.kokupay.com/v1/pix/charges'
            && $request->hasHeader('Authorization', 'Bearer koku_live_teste')
            && $request->hasHeader('Idempotency-Key', 'charge-0b6f1d2a-3c4e-4f50-8a61-7b8c9d0e1f23')
            && $request['order_reference'] === '0b6f1d2a-3c4e-4f50-8a61-7b8c9d0e1f23'
            && $request['amount_cents'] === 15990
            && $request['payer'] === ['name' => 'Maria Souza', 'document' => '52998224725', 'email' => 'maria.souza@example.com', 'phone' => '11987654321'];
    });
});

it('devolve a recusa da Koku como falha definitiva com o motivo dela', function () {
    Http::fake(['api.kokupay.com/*' => Http::response(kokuChargeResponse([
        'status' => 'failed', 'pix' => null, 'failure_message' => 'Emissão recusada pela análise de risco.',
    ]), 201)]);

    $result = (new KokuPayAcquirer(['api_key' => 'k']))->generatePixQrCode(kokuCharge());

    expect($result['success'])->toBeFalse()
        ->and($result['failure_reason'])->toBe('declined')
        ->and($result['error'])->toBe('Emissão recusada pela análise de risco.');
});

it('não aceita cobrança emitida com valor diferente do pedido', function () {
    Http::fake(['api.kokupay.com/*' => Http::response(kokuChargeResponse(['amount_cents' => '100']), 201)]);

    $result = (new KokuPayAcquirer(['api_key' => 'k']))->generatePixQrCode(kokuCharge());

    expect($result['success'])->toBeFalse()->and($result['failure_reason'])->toBe('ambiguous_result');
});

it('trata indisponibilidade e conta não habilitada de forma diferente', function () {
    Http::fake(['api.kokupay.com/*' => Http::sequence()
        ->push(['error' => ['code' => 'provider_unavailable', 'message' => 'x', 'details' => ['reason' => 'processing_unavailable']]], 503)
        ->push(['error' => ['code' => 'provider_unavailable', 'message' => 'x', 'details' => ['reason' => 'account_not_enabled']]], 503),
    ]);

    $koku = new KokuPayAcquirer(['api_key' => 'k']);

    expect($koku->generatePixQrCode(kokuCharge())['error'])->toContain('indisponível')
        ->and($koku->generatePixQrCode(kokuCharge())['error'])->toContain('não configurado');
});

it('normaliza a consulta da cobrança paga', function () {
    Http::fake(['api.kokupay.com/v1/pix/charges/*' => Http::response(kokuChargeResponse([
        'status' => 'paid', 'fee_cents' => '320', 'net_cents' => '15670', 'paid_at' => '2026-10-07T15:32:10.000Z',
        'end_to_end_id' => 'E123', 'late_payment' => false,
    ]))]);

    $result = (new KokuPayAcquirer(['api_key' => 'k']))->getTransaction('8d3f6a12-4b9e-4c71-a2d5-6e0f1b3c9a47');

    expect($result)->toMatchArray([
        'success' => true, 'status' => 'paid', 'amount_cents' => 15990, 'fee_cents' => 320,
        'net_cents' => 15670, 'end_to_end_id' => 'E123', 'late_payment' => false,
        'order_reference' => '0b6f1d2a-3c4e-4f50-8a61-7b8c9d0e1f23',
    ]);
});

it('recusa pagador sem documento antes de chamar a Koku', function () {
    Http::fake();

    $charge = kokuCharge(['customer' => ['name' => 'Maria', 'email' => 'm@example.com', 'document' => '']]);
    $result = (new KokuPayAcquirer(['api_key' => 'k']))->generatePixQrCode($charge);

    expect($result['success'])->toBeFalse()->and($result['failure_reason'])->toBe('validation_error');
    Http::assertNothingSent();
});
