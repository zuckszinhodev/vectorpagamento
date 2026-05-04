<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Str;

class TestHelpers
{
    /**
     * Create a test account with default values
     */
    public static function createAccount(array $overrides = []): Account
    {
        return Account::create(array_merge([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Test Account',
            'document'      => '12345678900',
            'document_type' => 'cpf',
            'email'         => 'account@test.com',
            'status'        => 'active',
        ], $overrides));
    }

    /**
     * Create a test user with default values
     */
    public static function createUser(Account $account, array $overrides = []): User
    {
        return User::create(array_merge([
            'id'         => Str::uuid()->toString(),
            'account_id' => $account->id,
            'email'      => 'user@test.com',
            'password'   => bcrypt('password'),
            'name'       => 'Test User',
            'status'     => 'active',
            'role'       => 'admin',
        ], $overrides));
    }

    /**
     * Create authenticated user and return JWT token
     */
    public static function createAuthenticatedUser(array $accountOverrides = [], array $userOverrides = []): array
    {
        $account = self::createAccount($accountOverrides);
        $user = self::createUser($account, $userOverrides);
        $token = auth()->login($user);

        return [
            'account' => $account,
            'user'    => $user,
            'token'   => $token,
        ];
    }

    /**
     * Generate authorization header for JWT
     */
    public static function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * Format money amount (cents to BRL)
     */
    public static function formatMoney(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    /**
     * Parse money string to cents
     */
    public static function parseMoney(string $money): int
    {
        $cleaned = preg_replace('/[^0-9,.]/', '', $money);
        $cleaned = str_replace(',', '.', $cleaned);
        return (int) (floatval($cleaned) * 100);
    }
}
