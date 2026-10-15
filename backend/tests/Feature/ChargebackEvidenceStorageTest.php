<?php

use App\Models\Account;
use App\Models\Charge;
use App\Models\Chargeback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('stores MED evidence privately and restricts upload and download to its account', function () {
    Storage::fake('private');
    $account = Account::create(['name' => 'MED Test', 'document' => '12345678901', 'document_type' => 'cpf', 'email' => 'med@example.com', 'status' => 'active']);
    $user = User::create(['account_id' => $account->id, 'email' => 'med@example.com', 'name' => 'MED User', 'password' => bcrypt('test-password')]);
    $charge = Charge::create(['account_id' => $account->id, 'idempotency_key' => (string) Str::uuid(), 'amount' => 10000, 'currency' => 'BRL', 'payment_method' => 'pix', 'status' => 'approved', 'customer' => ['name' => 'Comprador', 'email' => 'buyer@example.com']]);
    $cb = Chargeback::create(['account_id' => $account->id, 'charge_id' => $charge->id, 'amount' => 10000, 'status' => 'open']);
    // Exercise the actual controller routes with an authenticated user, independent of JWT/MFA middleware.
    $this->withoutMiddleware()->actingAs($user);
    $url = "/api/v1/chargebacks/{$cb->id}/evidence";
    $this->post($url, [
        'description' => 'Entrega comprovada',
        'med' => ['business_type' => 'physical', 'refund_status' => 'none', 'confirmed' => '1', 'order_reference' => 'Pedido 42'],
        'images' => [UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf')],
    ])->assertOk()->assertJsonPath('data.evidence.med.order_reference', 'Pedido 42');
    $cb->refresh();
    expect($cb->status)->toBe('evidence_sent');
    Storage::disk('private')->assertExists($cb->evidence['images'][0]['path']);
    $this->get($url . '/0')->assertOk()->assertDownload('nota.pdf');
    $this->get($url . '/9')->assertNotFound();

    $other = new User();
    $other->id = (string) Str::uuid();
    $other->account_id = (string) Str::uuid();
    $this->actingAs($other);
    $this->get($url . '/0')->assertNotFound();
    $this->postJson($url, ['description' => 'Tentativa de alteração'])->assertNotFound();
    expect($cb->fresh()->evidence['description'])->toBe('Entrega comprovada');
});
