<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PaymentController;
use App\Mail\MemberCredentialsMail;
use App\Models\Charge;
use App\Models\Checkout;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\MemberArea;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\MemberEnrollmentService;
use App\Services\PaymentService;
use App\Services\Shipping\MelhorEnvioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Helpers\TestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->account = TestHelpers::createAccount();
    $this->product = Product::create([
        'account_id' => $this->account->id, 'name' => 'Produto de teste',
        'slug' => 'regression-product', 'price' => 10000, 'type' => 'digital', 'is_active' => true,
    ]);
    $this->checkout = Checkout::create([
        'account_id' => $this->account->id, 'product_id' => $this->product->id,
        'name' => 'Checkout de teste', 'slug' => 'regression-checkout', 'status' => 'active', 'settings' => [],
    ]);
    $this->buyer = ['name' => 'Comprador', 'email' => 'buyer@example.test', 'document' => '52998224725'];
});

function regressionCharge($test, array $overrides = []): Charge
{
    return Charge::create(array_merge([
        'account_id' => $test->account->id, 'product_id' => $test->product->id,
        'checkout_id' => $test->checkout->id, 'amount' => 10000, 'currency' => 'BRL',
        'payment_method' => 'pix', 'status' => 'pending', 'customer' => $test->buyer,
        'idempotency_key' => (string) Str::uuid(), 'metadata' => [],
    ], $overrides));
}

function regressionRequest($test, array $payload = []): Request
{
    $request = Request::create('/api/v1/external/payments/test/process', 'POST', $payload);
    $request->setUserResolver(fn () => (object) ['account_id' => $test->account->id]);
    return $request;
}

it('uses the real payment service for an existing external charge without manufacturing captures', function () {
    $charge = regressionCharge($this);
    $service = Mockery::mock(PaymentService::class);
    $service->shouldReceive('processPayment')->once()->andReturnUsing(function (Charge $received, array $card) use ($charge) {
        expect($received->id)->toBe($charge->id);
        expect($received->status)->toBe('processing');
        $received->update(['status' => 'pending', 'acquirer_transaction_id' => 'provider-pix-1']);
        return ['success' => true, 'transaction_id' => 'provider-pix-1', 'pix_copy_paste' => 'fake-pix-code'];
    });
    $response = (new PaymentController($service))->process(regressionRequest($this), $charge->id);
    expect($response->getStatusCode())->toBe(202);
    expect($response->getData(true)['data']['pix_copy_paste'])->toBe('fake-pix-code');
    expect(Transaction::where('charge_id', $charge->id)->count())->toBe(0);
    expect($charge->fresh()->net_amount)->toBeNull();

    $retry = (new PaymentController($service))->process(regressionRequest($this), $charge->id);
    expect($retry->getStatusCode())->toBe(409);
});

it('does not resend an external charge even when the provider has not returned an identifier', function () {
    $charge = regressionCharge($this);
    $service = Mockery::mock(PaymentService::class);
    $service->shouldReceive('processPayment')->once()->andReturnUsing(function (Charge $received) {
        $received->update(['status' => 'pending']);
        return ['success' => true];
    });
    $controller = new PaymentController($service);
    expect($controller->process(regressionRequest($this), $charge->id)->getStatusCode())->toBe(202);
    expect($controller->process(regressionRequest($this), $charge->id)->getStatusCode())->toBe(409);
});

it('reports provider failure and does not expose provider details', function () {
    $charge = regressionCharge($this);
    $service = Mockery::mock(PaymentService::class);
    $service->shouldReceive('processPayment')->once()->andReturn(['success' => false, 'error' => 'private-provider-details']);
    $response = (new PaymentController($service))->process(regressionRequest($this), $charge->id);
    expect($response->getStatusCode())->toBe(400);
    expect($response->getContent())->not->toContain('private-provider-details');
    expect($charge->fresh()->status)->toBe('failed');
});

it('prevents processing a charge owned by another account', function () {
    $other = TestHelpers::createAccount(['document' => '98765432100', 'email' => 'other@example.test']);
    $charge = regressionCharge($this, ['account_id' => $other->id]);
    $service = Mockery::mock(PaymentService::class);
    $service->shouldNotReceive('processPayment');
    expect((new PaymentController($service))->process(regressionRequest($this), $charge->id)->getStatusCode())->toBe(404);
});

it('refuses unsupported capture without changing the financial records', function () {
    $charge = regressionCharge($this, ['status' => 'authorized']);
    $acquirer = \App\Domain\Acquirer\Entities\Acquirer::firstOrFail();
    $transaction = Transaction::create([
        'charge_id' => $charge->id, 'acquirer_id' => $acquirer->id, 'type' => 'authorization',
        'status' => 'approved', 'amount' => 10000, 'net_amount' => 10000,
    ]);
    $response = (new PaymentController(Mockery::mock(PaymentService::class)))
        ->capture(regressionRequest($this), $transaction->id);
    expect($response->getStatusCode())->toBe(422);
    expect($response->getData(true)['error']['code'])->toBe('capture_not_supported');
    expect($charge->fresh()->status)->toBe('authorized');
    expect(Transaction::where('charge_id', $charge->id)->count())->toBe(1);
});

it('adds the server fixed shipping price to the charge', function () {
    $this->product->update(['type' => 'physical', 'metadata' => ['shipping' => ['shipping_price' => 1500]]]);
    $carrier = Mockery::mock(MelhorEnvioService::class);
    $carrier->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(MelhorEnvioService::class, $carrier);
    $payments = Mockery::mock(PaymentService::class);
    $payments->shouldReceive('processPayment')->once()->withArgs(fn (Charge $charge) => $charge->amount === 11500)
        ->andReturn(['success' => true, 'status' => 'pending']);
    app()->instance(PaymentService::class, $payments);

    $this->postJson('/api/v1/checkout/regression-checkout/process', [
        'payment_method' => 'pix', 'customer' => $this->buyer,
        'address' => ['zipcode' => '01001-000', 'street' => 'Praça da Sé', 'number' => '10'], 'shipping_price' => 1,
    ])->assertCreated()->assertJsonPath('data.amount', 11500)->assertJsonPath('data.shipping_amount', 1500);
    expect(Charge::first()->metadata['shipping']['amount'])->toBe(1500);
});

it('recalculates the selected shipping option on the server', function () {
    $this->product->update(['type' => 'physical', 'metadata' => ['shipping' => ['weight' => 1]]]);
    $carrier = Mockery::mock(MelhorEnvioService::class);
    $carrier->shouldReceive('isConfigured')->andReturn(true);
    $carrier->shouldReceive('quote')->once()->with('01001000', ['weight' => 1], 10000)
        ->andReturn([['id' => 2, 'price' => 2300, 'name' => 'Entrega', 'company' => 'Transportadora']]);
    app()->instance(MelhorEnvioService::class, $carrier);
    $payments = Mockery::mock(PaymentService::class);
    $payments->shouldReceive('processPayment')->once()->withArgs(fn (Charge $charge) => $charge->amount === 12300)
        ->andReturn(['success' => true]);
    app()->instance(PaymentService::class, $payments);
    $this->postJson('/api/v1/checkout/regression-checkout/process', [
        'payment_method' => 'pix', 'customer' => $this->buyer,
        'address' => ['zipcode' => '01001-000'], 'shipping_service_id' => 2, 'shipping_price' => 0,
    ])->assertCreated()->assertJsonPath('data.amount', 12300);
});

it('rejects an unavailable delivery option before creating a charge', function () {
    $this->product->update(['type' => 'physical']);
    $carrier = Mockery::mock(MelhorEnvioService::class);
    $carrier->shouldReceive('isConfigured')->andReturn(true);
    $carrier->shouldReceive('quote')->andReturn([]);
    app()->instance(MelhorEnvioService::class, $carrier);
    $payments = Mockery::mock(PaymentService::class);
    $payments->shouldNotReceive('processPayment');
    app()->instance(PaymentService::class, $payments);
    $this->postJson('/api/v1/checkout/regression-checkout/process', [
        'payment_method' => 'pix', 'customer' => $this->buyer,
        'address' => ['zipcode' => '01001-000'], 'shipping_service_id' => 999,
    ])->assertUnprocessable();
    expect(Charge::count())->toBe(0);
});

it('requires a destination for physical products', function () {
    $this->product->update(['type' => 'physical']);
    $this->postJson('/api/v1/checkout/regression-checkout/process', [
        'payment_method' => 'pix', 'customer' => $this->buyer,
    ])->assertUnprocessable();
    expect(Charge::count())->toBe(0);
});

it('creates working member credentials and does not reset them on repeated approval', function () {
    $course = Course::create(['account_id' => $this->account->id, 'product_id' => $this->product->id, 'name' => 'Curso', 'slug' => 'course']);
    MemberArea::create(['account_id' => $this->account->id, 'name' => 'Membros', 'custom_domain' => 'members.example.test', 'domain_verified' => true, 'ssl_enabled' => true]);
    $charge = regressionCharge($this, ['status' => 'approved']);
    $service = app(MemberEnrollmentService::class);
    $enrollment = $service->createAutomaticEnrollment($charge);
    expect($enrollment)->not->toBeNull();
    $member = Member::where('email', $this->buyer['email'])->firstOrFail();
    Mail::assertQueued(MemberCredentialsMail::class, fn ($mail) => Hash::check($mail->tempPassword, $member->password)
        && $mail->loginUrl === 'https://members.example.test/members/login');
    expect($service->createAutomaticEnrollment($charge)->id)->toBe($enrollment->id);
    expect(Member::find($member->id)->password)->toBe($member->password);
    expect(Enrollment::where('course_id', $course->id)->count())->toBe(1);
    Mail::assertQueued(MemberCredentialsMail::class, 1);
});

it('repairs a missing member on an existing enrollment without changing the user password', function () {
    $user = TestHelpers::createUser($this->account, ['email' => $this->buyer['email']]);
    $originalPassword = $user->password;
    $course = Course::create(['account_id' => $this->account->id, 'product_id' => $this->product->id, 'name' => 'Curso', 'slug' => 'course']);
    $existing = Enrollment::create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'active', 'access_type' => 'lifetime', 'enrolled_at' => now()]);
    $enrollment = app(MemberEnrollmentService::class)->createAutomaticEnrollment(regressionCharge($this, ['status' => 'approved']));
    expect($enrollment->id)->toBe($existing->id);
    expect(Member::where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->password)->toBe($originalPassword);
    Mail::assertQueued(MemberCredentialsMail::class, 1);
});

it('does not enroll a buyer before payment approval', function () {
    expect(app(MemberEnrollmentService::class)->createAutomaticEnrollment(regressionCharge($this)))->toBeNull();
    expect(Member::count())->toBe(0);
    Mail::assertNothingQueued();
});
