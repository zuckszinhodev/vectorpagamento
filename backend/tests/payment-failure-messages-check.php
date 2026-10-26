<?php

declare(strict_types=1);

// Standalone regression checks: fake HTTP only, no database or real charges.
$base = getenv('PAYMENT_TEST_BASE') ?: dirname(__DIR__);
$source = getenv('PAYMENT_TEST_SOURCE') ?: $base;
require $base.'/vendor/autoload.php';
require $source.'/app/Services/PaymentFailureMessage.php';
require $source.'/app/Infrastructure/Acquirers/AkkadPagAcquirer.php';
require $source.'/app/Services/PaymentService.php';

use App\Infrastructure\Acquirers\AkkadPagAcquirer;
use App\Models\Charge;
use App\Services\PaymentFailureMessage as Message;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

$container = new Container();
Container::setInstance($container);
$container->instance('config', new Illuminate\Config\Repository(['app' => ['url' => 'https://example.test']]));
Facade::setFacadeApplication($container);
$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($label);
    }
    $checks++;
}
function fakeProvider(array $body, int $status): void
{
    Http::swap(new Factory());
    Http::preventStrayRequests();
    Http::fake(['https://example.test/*' => Http::response($body, $status)]);
}
function charge(array $customer, int $amount = 1500): Charge
{
    return new Charge(['id' => 'test-charge', 'amount' => $amount, 'customer' => $customer]);
}
$valid = ['name' => 'Cliente Teste', 'email' => 'cliente@example.test', 'phone' => '11999999999', 'document' => '12345678901'];
$provider = new AkkadPagAcquirer(['base_url' => 'https://example.test', 'public_key' => 'test-public', 'secret_key' => 'test-secret']);
fakeProvider([], 500);
foreach (['phone' => 'Telefone', 'email' => 'E-mail', 'document' => 'CPF/CNPJ', 'name' => 'Nome'] as $field => $label) {
    $customer = $valid;
    unset($customer[$field]);
    $result = $provider->generatePixQrCode(charge($customer));
    check(! $result['success'] && str_contains($result['error'], $label.' do cliente ausente'), $field.' absent');
}
foreach (['phone' => '123', 'email' => 'invalid', 'document' => '123'] as $field => $value) {
    $result = $provider->generatePixQrCode(charge(array_replace($valid, [$field => $value])));
    check(! $result['success'] && str_contains($result['error'], 'inválido'), $field.' invalid');
}
check(str_contains($provider->generatePixQrCode(charge($valid, 499))['error'], 'R$ 5,00'), 'Minimum Pix amount');
check(Http::recorded()->isEmpty(), 'Validation failures send no HTTP requests');

fakeProvider(['message' => 'Valor máximo permitido é R$ 1.500,00'], 400);
$result = $provider->generatePixQrCode(charge($valid));
check($result['error'] === 'O valor máximo para gerar Pix na adquirente AkkadPag é R$ 1.500,00.', 'Provider maximum preserved');
fakeProvider(['message' => 'Valor excede o limite permitido'], 400);
$result = $provider->generatePixQrCode(charge($valid));
check(str_contains($result['error'], 'máximo não foi informado') && ! str_contains($result['error'], 'R$'), 'Unknown maximum never invented');
fakeProvider(['message' => 'Validation error', 'errors' => ['customer.phone' => ['Telefone inválido']]], 422);
$result = $provider->generatePixQrCode(charge($valid));
check(str_contains($result['error'], 'Telefone inválido'), 'Nested field error preserved');
fakeProvider(['message' => 'Too many requests'], 429);
check(str_contains($provider->generatePixQrCode(charge($valid))['error'], 'Limite de solicitações'), 'Rate limit differs from amount limit');
fakeProvider(['message' => 'Falha ao processar pagamento. Tente novamente mais tarde.'], 400);
check(str_contains($provider->generatePixQrCode(charge($valid))['error'], 'não informou um motivo específico'), 'Unspecified provider refusal');
fakeProvider(['message' => 'Internal server error'], 503);
$result = $provider->generatePixQrCode(charge($valid));
check($result['failure_reason'] === 'ambiguous_result' && str_contains($result['error'], 'Confira o status'), 'Ambiguous result remains ambiguous');
fakeProvider(['message' => 'test-secret rejected'], 400);
check(! str_contains($provider->generatePixQrCode(charge($valid))['error'], 'test-secret'), 'Credentials redacted');
fakeProvider(['data' => ['id' => 'provider-test', 'amount' => 1500, 'pix' => ['copy_paste' => 'test-pix']]], 200);
$result = $provider->generatePixQrCode(charge($valid));
check($result['success'] && $result['pix_copy_paste'] === 'test-pix', 'Successful Pix unchanged');

$service = (new ReflectionClass(App\Services\PaymentService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($service, 'extractPixAmountLimits');
foreach ([
    [['min_amount_cents' => 500, 'max_amount_cents' => 10000], [500, 10000]],
    [['pix' => ['min_amount_cents' => '500', 'max_amount_cents' => '150000']], [500, 150000]],
    [['min_amount' => 5, 'max_amount' => '1.500,00'], [500, 150000]],
    [[], [null, null]],
] as [$settings, $expected]) {
    check($method->invoke($service, $settings) === $expected, 'Configured amount units');
}
check(Message::amountLimit(10000, 'Teste') === 'O valor máximo para gerar Pix na adquirente Teste é R$ 100,00.', 'Cents formatted as reais');
check(Message::fromProvider('Telefone inválido', 'Teste', ['phone' => 'abc']) === 'Telefone do cliente inválido. Informe o número com DDD, entre 10 e 13 dígitos.', 'Invalid phone explained');
echo "{$checks} payment failure checks passed (fake HTTP; no real charges).\n";
