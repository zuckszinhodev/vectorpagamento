<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\TestHelpers;

uses(RefreshDatabase::class);

function makeSecurityTestUser(array $userOverrides = []): array
{
    return TestHelpers::createAuthenticatedUser([], $userOverrides);
}

// 1. Exception details must not leak in API responses

it('does not expose stack trace in 404 responses', function () {
    $response = $this->getJson('/api/v1/this-route-does-not-exist-at-all');
    $response->assertStatus(404);
    $body = json_encode($response->json());
    expect($body)
        ->not->toContain('vendor/')
        ->not->toContain('#0 ')
        ->not->toContain('Stack trace');
});

// 2. User enumeration prevention

it('returns same error for wrong email and wrong password', function () {
    makeSecurityTestUser(['email' => 'real@example.com']);

    $wrongEmail = $this->postJson('/api/v1/auth/login', [
        'email'    => 'nonexistent@example.com',
        'password' => 'wrongpassword',
    ]);
    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email'    => 'real@example.com',
        'password' => 'wrongpassword',
    ]);

    $wrongEmail->assertStatus(401);
    $wrongPassword->assertStatus(401);
    expect($wrongEmail->json('error.code'))->toBe($wrongPassword->json('error.code'));
    expect($wrongEmail->json('error.message'))->toBe($wrongPassword->json('error.message'));
});

// 3. Unauthenticated access blocked

it('rejects unauthenticated access to account endpoint', function () {
    $this->getJson('/api/v1/account')->assertStatus(401);
});

it('rejects unauthenticated access to withdrawals', function () {
    $this->getJson('/api/v1/withdrawals')->assertStatus(401);
});

it('rejects unauthenticated access to admin endpoints', function () {
    $this->getJson('/api/v1/admin/customers')->assertStatus(401);
});

// 4. Role-based access control

it('regular user cannot access admin customer list', function () {
    $auth = makeSecurityTestUser(['role' => 'user']);
    $this->withHeaders(TestHelpers::authHeader($auth['token']))
        ->getJson('/api/v1/admin/customers')
        ->assertStatus(403);
});

it('regular user cannot access admin acquirer management', function () {
    $auth = makeSecurityTestUser(['role' => 'user']);
    $this->withHeaders(TestHelpers::authHeader($auth['token']))
        ->getJson('/api/v1/admin/acquirers')
        ->assertStatus(403);
});

// 5. SQL injection protection in report group_by

it('report revenue does not crash on SQL injection attempt in group_by', function () {
    $auth = makeSecurityTestUser();
    $malicious = urlencode("day'; DROP TABLE charges; --");
    $response = $this->withHeaders(TestHelpers::authHeader($auth['token']))
        ->getJson("/api/v1/reports/revenue?group_by={$malicious}");
    expect($response->status())->not->toBe(500);
});

it('report revenue accepts valid group_by values', function () {
    $auth = makeSecurityTestUser();
    foreach (['hour', 'day', 'week', 'month'] as $groupBy) {
        $response = $this->withHeaders(TestHelpers::authHeader($auth['token']))
            ->getJson("/api/v1/reports/revenue?group_by={$groupBy}&period=7d");
        expect($response->status())->not->toBe(500);
    }
});

// 6. Security headers

it('API responses include X-Content-Type-Options header', function () {
    $response = $this->getJson('/api/v1/auth/login');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('API responses include X-Frame-Options header', function () {
    $response = $this->getJson('/api/v1/auth/login');
    $response->assertHeader('X-Frame-Options', 'DENY');
});

// 7. Password not returned in responses

it('login response does not include password hash', function () {
    makeSecurityTestUser(['email' => 'secure@example.com', 'password' => bcrypt('Password@123')]);
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'secure@example.com',
        'password' => 'Password@123',
    ]);
    $body = json_encode($response->json());
    expect($body)
        ->not->toContain('$2y$')
        ->not->toContain('$argon2')
        ->not->toContain('"password"');
});

it('register response does not include password hash', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Secure User',
        'email'                 => 'newuser@example.com',
        'password'              => 'Password@123!',
        'password_confirmation' => 'Password@123!',
    ]);
    $body = json_encode($response->json());
    expect($body)
        ->not->toContain('$2y$')
        ->not->toContain('$argon2')
        ->not->toContain('"password"');
});

// 8. Blocked user cannot authenticate

it('blocked user cannot login', function () {
    ['user' => $user] = makeSecurityTestUser([
        'email'    => 'blocked@example.com',
        'password' => bcrypt('Password@123'),
        'status'   => 'blocked',
    ]);
    $user->forceFill(['status' => 'blocked'])->save();
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'blocked@example.com',
        'password' => 'Password@123',
    ]);
    $response->assertStatus(403);
    expect($response->json('error.code'))->toBe('account_blocked');
});

// 9. Webhook does not expose internals

it('webhook endpoint does not expose internal exception details', function () {
    $response = $this->postJson('/api/v1/webhook', [
        'invalid'      => 'payload',
        'no_signature' => true,
    ]);
    $body = json_encode($response->json());
    expect($body)
        ->not->toContain('vendor/')
        ->not->toContain('Stack trace')
        ->not->toContain('Illuminate\\');
});

// 10. Sensitive cache headers on auth endpoints

it('auth endpoint response has no-store cache header', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'test@example.com',
        'password' => 'wrongpassword',
    ]);
    $cacheControl = $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
});
