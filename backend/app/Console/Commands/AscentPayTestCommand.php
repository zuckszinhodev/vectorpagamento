<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AscentPayTestCommand extends Command
{
    protected $signature = 'ascentpay:test
        {--secret-key= : Secret Key to test (overrides DB/ENV)}
        {--public-key= : Public Key to test (overrides DB/ENV)}
        {--company-id= : Company ID to test (overrides DB/ENV)}
        {--base-url=https://api.ascentpayip.com : Base URL}
        {--from-db : Use DB-stored credentials}';

    protected $description = 'Test AscentPay API credentials directly';

    public function handle(): int
    {
        $this->info('=== AscentPay Credential Test ===');
        $this->newLine();

        $secretKey = $this->option('secret-key');
        $publicKey = $this->option('public-key');
        $companyId = $this->option('company-id');
        $baseUrl = $this->option('base-url');

        // If --from-db, pull from DB
        if ($this->option('from-db')) {
            $this->info('Loading credentials from database...');
            try {
                $config = \App\Models\AcquirerConfig::where('acquirer_key', 'ascentpay')->first();
                if (!$config) {
                    $this->error('No ascentpay record in acquirer_configs table');
                    return 1;
                }
                $creds = $config->getDecryptedCredentials();
                if (!$creds) {
                    $this->error('Failed to decrypt credentials from DB');
                    return 1;
                }
                $secretKey = $secretKey ?: ($creds['secret_key'] ?? '');
                $publicKey = $publicKey ?: ($creds['public_key'] ?? '');
                $companyId = $companyId ?: ($creds['company_id'] ?? '');
                $baseUrl = $creds['base_url'] ?? $baseUrl;
                $this->line('  DB fields present: ' . implode(', ', array_keys(array_filter($creds, fn($v) => !empty($v)))));
            } catch (\Exception $e) {
                $this->error('DB error: ' . $e->getMessage());
                return 1;
            }
        }

        // If not provided via flags or --from-db, prompt interactively
        if (empty($secretKey)) {
            $secretKey = $this->secret('Enter Secret Key');
        }
        if (empty($publicKey)) {
            $publicKey = $this->secret('Enter Public Key (or press Enter to skip)') ?: '';
        }
        if (empty($companyId)) {
            $companyId = $this->ask('Enter Company ID');
        }

        // Show what we're testing with
        $this->newLine();
        $this->info('--- Credentials ---');
        $this->line('  Base URL:    ' . $baseUrl);
        $this->line('  Secret Key:  ' . substr($secretKey, 0, 8) . '...' . substr($secretKey, -4) . ' (' . strlen($secretKey) . ' chars)');
        $this->line('  Public Key:  ' . (strlen($publicKey) > 8 ? substr($publicKey, 0, 8) . '...' . substr($publicKey, -4) . ' (' . strlen($publicKey) . ' chars)' : '(empty)'));
        $this->line('  Company ID:  ' . substr($companyId, 0, 8) . '...' . substr($companyId, -4) . ' (' . strlen($companyId) . ' chars)');

        // Test different auth formats
        $this->newLine();
        $this->info('--- Testing Auth Formats ---');

        // Format 1: secretKey:companyId (current implementation)
        $auth1 = base64_encode($secretKey . ':' . $companyId);
        $this->testAuth('Format 1: Basic secret_key:company_id', $baseUrl, $auth1);

        // Format 2: companyId:secretKey (reversed)
        $auth2 = base64_encode($companyId . ':' . $secretKey);
        $this->testAuth('Format 2: Basic company_id:secret_key', $baseUrl, $auth2);

        // Format 3: publicKey:secretKey
        if (!empty($publicKey)) {
            $auth3 = base64_encode($publicKey . ':' . $secretKey);
            $this->testAuth('Format 3: Basic public_key:secret_key', $baseUrl, $auth3);

            // Format 4: secretKey:publicKey
            $auth4 = base64_encode($secretKey . ':' . $publicKey);
            $this->testAuth('Format 4: Basic secret_key:public_key', $baseUrl, $auth4);
        }

        // Format 5: secretKey only (no password)
        $auth5 = base64_encode($secretKey . ':');
        $this->testAuth('Format 5: Basic secret_key only', $baseUrl, $auth5);

        // Format 6: Bearer token with secretKey
        $this->testBearerAuth('Format 6: Bearer secret_key', $baseUrl, $secretKey);

        // Format 7: Bearer token with publicKey
        if (!empty($publicKey)) {
            $this->testBearerAuth('Format 7: Bearer public_key', $baseUrl, $publicKey);
        }

        // Format 8: x-api-key header
        $this->testApiKeyAuth('Format 8: x-api-key + x-company-id', $baseUrl, $secretKey, $companyId);

        // Format 9: publicKey:companyId
        if (!empty($publicKey)) {
            $auth9 = base64_encode($publicKey . ':' . $companyId);
            $this->testAuth('Format 9: Basic public_key:company_id', $baseUrl, $auth9);
        }

        $this->newLine();
        return 0;
    }

    private function testAuth(string $label, string $baseUrl, string $authHeader): void
    {
        $this->line("  {$label}:");
        $this->line('    Auth: Basic ' . substr($authHeader, 0, 20) . '...');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $authHeader,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->get("{$baseUrl}/v1/dashboard/balance");

            $status = $response->status();
            $body = $response->json() ?? $response->body();

            if ($response->successful()) {
                $this->line("    <fg=green>✓ SUCCESS (HTTP {$status})</>");
                $this->line('    Response: ' . (is_array($body) ? json_encode($body) : $body));
            } else {
                $this->line("    <fg=red>✗ FAILED (HTTP {$status})</>");
                $this->line('    Response: ' . (is_array($body) ? json_encode($body) : $body));
            }
        } catch (\Exception $e) {
            $this->line("    <fg=red>✗ ERROR: {$e->getMessage()}</>");
        }
    }

    private function testBearerAuth(string $label, string $baseUrl, string $token): void
    {
        $this->line("  {$label}:");

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->get("{$baseUrl}/v1/dashboard/balance");

            $status = $response->status();
            $body = $response->json() ?? $response->body();

            if ($response->successful()) {
                $this->line("    <fg=green>✓ SUCCESS (HTTP {$status})</>");
                $this->line('    Response: ' . (is_array($body) ? json_encode($body) : $body));
            } else {
                $this->line("    <fg=red>✗ FAILED (HTTP {$status})</>");
                $this->line('    Response: ' . (is_array($body) ? json_encode($body) : $body));
            }
        } catch (\Exception $e) {
            $this->line("    <fg=red>✗ ERROR: {$e->getMessage()}</>");
        }
    }

    private function testApiKeyAuth(string $label, string $baseUrl, string $secretKey, string $companyId): void
    {
        $this->line("  {$label}:");

        try {
            $response = Http::withHeaders([
                'x-api-key' => $secretKey,
                'x-company-id' => $companyId,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->get("{$baseUrl}/v1/dashboard/balance");

            $status = $response->status();
            $body = $response->json() ?? $response->body();

            if ($response->successful()) {
                $this->line("    <fg=green>✓ SUCCESS (HTTP {$status})</>");
                $this->line('    Response: ' . (is_array($body) ? json_encode($body) : $body));
            } else {
                $this->line("    <fg=red>✗ FAILED (HTTP {$status})</>");
                $this->line('    Response: ' . (is_array($body) ? json_encode($body) : $body));
            }
        } catch (\Exception $e) {
            $this->line("    <fg=red>✗ ERROR: {$e->getMessage()}</>");
        }
    }
}
