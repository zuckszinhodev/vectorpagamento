<?php

use App\Http\Requests\ChargebackEvidenceRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

it('accepts legacy evidence without MED fields', function () {
    expect(Validator::make(['description' => 'Entrega realizada.'], ChargebackEvidenceRules::rules())->passes())->toBeTrue();
});

it('validates and preserves the structured MED defense with a PDF', function () {
    $validator = Validator::make([
        'description' => 'Venda e entrega documentadas.',
        'med' => ['business_type' => 'digital', 'refund_status' => 'none', 'confirmed' => '1', 'fulfillment' => 'Acesso registrado em 01/10/2026.'],
        'images' => [UploadedFile::fake()->create('comprovante.pdf', 100, 'application/pdf')],
    ], ChargebackEvidenceRules::rules());
    expect($validator->passes())->toBeTrue();
    expect($validator->validated()['med']['fulfillment'])->toBe('Acesso registrado em 01/10/2026.');
});

it('requires confirmation and operation details for MED', function () {
    $validator = Validator::make(['description' => 'Defesa', 'med' => ['confirmed' => '0']], ChargebackEvidenceRules::rules());
    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->keys())->toContain('med.confirmed', 'med.business_type', 'med.refund_status');
});

it('rejects unsupported oversized or excessive attachments', function () {
    foreach ([
        [UploadedFile::fake()->create('script.html', 1, 'text/html')],
        [UploadedFile::fake()->create('grande.pdf', 5121, 'application/pdf')],
        array_fill(0, 11, UploadedFile::fake()->create('arquivo.pdf', 1, 'application/pdf')),
    ] as $files) {
        expect(Validator::make(['description' => 'Defesa', 'images' => $files], ChargebackEvidenceRules::rules())->fails())->toBeTrue();
    }
});

it('rejects unrecognized MED fields', function () {
    expect(Validator::make(['description' => 'Defesa', 'med' => [
        'business_type' => 'service', 'refund_status' => 'full', 'confirmed' => '1', 'approved' => true,
    ]], ChargebackEvidenceRules::rules())->fails())->toBeTrue();
});
