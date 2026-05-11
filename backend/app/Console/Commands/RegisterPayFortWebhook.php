<?php

namespace App\Console\Commands;

use App\Infrastructure\Acquirers\PayFortAcquirer;
use Illuminate\Console\Command;

class RegisterPayFortWebhook extends Command
{
    protected $signature = 'payfort:register-webhook 
        {--url= : Custom webhook URL (default: APP_URL/api/v1/webhooks/payfort)}
        {--list : List existing webhooks}
        {--raw : Show raw JSON response (with --list)}
        {--delete= : Delete a webhook by ID}
        {--force : Delete all existing webhooks and re-register}
        {--delete-all : Delete ALL webhooks}
        {--activate= : Activate a webhook by ID}';

    protected $description = 'Register webhook at PayFort Bank to receive payment notifications';

    public function handle(): int
    {
        $apiKey = config('acquirers.payfort.api_key');
        $apiSecret = config('acquirers.payfort.api_secret');
        $baseUrl = config('acquirers.payfort.base_url', 'https://api.payfortbank.com');

        if (empty($apiKey) || empty($apiSecret)) {
            $this->error('PayFort credentials not configured. Set PAYFORT_API_KEY and PAYFORT_API_SECRET in .env');
            return 1;
        }

        $acquirer = new PayFortAcquirer([
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'base_url' => $baseUrl,
            'webhook_secret' => config('acquirers.payfort.webhook_secret', ''),
        ]);

        // List webhooks
        if ($this->option('list')) {
            return $this->listWebhooks($acquirer);
        }

        // Delete all webhooks
        if ($this->option('delete-all')) {
            return $this->deleteAllWebhooks($acquirer);
        }

        // Activate webhook
        if ($activateId = $this->option('activate')) {
            return $this->activateWebhook($acquirer, $activateId);
        }

        // Delete webhook
        if ($deleteId = $this->option('delete')) {
            return $this->deleteWebhook($acquirer, (int) $deleteId);
        }

        // Force: delete all then register
        if ($this->option('force')) {
            $this->warn('Force mode: deleting all existing webhooks first...');
            $this->deleteAllWebhooks($acquirer);
            $this->newLine();
        }

        // Register webhook
        return $this->registerWebhook($acquirer);
    }

    private function registerWebhook(PayFortAcquirer $acquirer): int
    {
        $webhookUrl = $this->option('url') ?? config('app.url') . '/api/v1/webhooks/payfort';

        $this->info("Registering webhook at PayFort Bank...");
        $this->info("URL: {$webhookUrl}");
        $this->newLine();

        $events = [
            'transaction_created',
            'transaction_paid',
            'transaction_refunded',
            // PayFort sends rejected/canceled changes through transaction_updated with status.
            'transaction_updated',
            'transfer_created',
            'transfer_completed',
            'transfer_canceled',
            'transfer_updated',
            'infraction',
        ];

        $this->info("Events: " . implode(', ', $events));
        $this->newLine();

        $result = $acquirer->registerWebhook($webhookUrl, $events, 'Vector Pagamentos');

        if ($result['success']) {
            $this->info('Webhook registered successfully!');
            
            if (isset($result['webhook_id'])) {
                $this->info("Webhook ID: {$result['webhook_id']}");
            }

            if (isset($result['signature_secret'])) {
                $this->newLine();
                $this->warn('=== IMPORTANT ===');
                $this->warn('Save this Signature Secret in your .env:');
                $this->info("PAYFORT_WEBHOOK_SECRET={$result['signature_secret']}");
                $this->warn('=================');
            }

            if (isset($result['data'])) {
                $this->newLine();
                $this->info('Full response:');
                $this->line(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return 0;
        }

        $this->error('Failed to register webhook: ' . ($result['error'] ?? 'Unknown error'));
        
        if (isset($result['details'])) {
            $this->line(json_encode($result['details'], JSON_PRETTY_PRINT));
        }

        return 1;
    }

    private function listWebhooks(PayFortAcquirer $acquirer): int
    {
        $this->info('Listing PayFort Bank webhooks...');
        $this->newLine();

        $result = $acquirer->listWebhooks();

        if ($result['success']) {
            $webhooks = $result['webhooks'] ?? [];

            if (empty($webhooks)) {
                $this->warn('No webhooks registered.');
                return 0;
            }

            // Always show raw JSON for debugging
            if ($this->option('raw') || true) {
                $this->info('Raw API response:');
                $this->line(json_encode($result['raw'] ?? $webhooks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $this->newLine();
            }

            $this->table(
                ['ID', 'URL', 'Name', 'Active', 'Events', 'Created'],
                collect($webhooks)->map(fn($wh) => [
                    $wh['id'] ?? $wh['_id'] ?? '-',
                    $wh['url'] ?? $wh['webhook_url'] ?? $wh['callbackUrl'] ?? '-',
                    $wh['name'] ?? $wh['webhook_name'] ?? '-',
                    ($wh['isActive'] ?? $wh['is_active'] ?? $wh['active'] ?? false) ? 'Yes' : 'No',
                    implode(', ', $wh['events'] ?? $wh['event_types'] ?? []),
                    $wh['createdAt'] ?? $wh['created_at'] ?? '-',
                ])->toArray()
            );

            return 0;
        }

        $this->error('Failed to list webhooks: ' . ($result['error'] ?? 'Unknown error'));
        return 1;
    }

    private function deleteWebhook(PayFortAcquirer $acquirer, int $webhookId): int
    {
        $this->info("Deleting webhook #{$webhookId}...");

        $result = $acquirer->deleteWebhook($webhookId);

        if ($result['success']) {
            $this->info('Webhook deleted successfully!');
            return 0;
        }

        $this->error('Failed to delete webhook: ' . ($result['error'] ?? 'Unknown error'));
        return 1;
    }

    private function deleteAllWebhooks(PayFortAcquirer $acquirer): int
    {
        $result = $acquirer->listWebhooks();

        if (!$result['success']) {
            $this->error('Failed to list webhooks: ' . ($result['error'] ?? 'Unknown'));
            return 1;
        }

        $webhooks = $result['webhooks'] ?? [];

        if (empty($webhooks)) {
            $this->info('No webhooks to delete.');
            return 0;
        }

        $deleted = 0;
        foreach ($webhooks as $wh) {
            $id = $wh['id'] ?? $wh['_id'] ?? null;
            if ($id) {
                $this->info("Deleting webhook #{$id}...");
                $delResult = $acquirer->deleteWebhook((int) $id);
                if ($delResult['success']) {
                    $deleted++;
                    $this->info("  Deleted.");
                } else {
                    $this->warn("  Failed: " . ($delResult['error'] ?? 'Unknown'));
                }
            }
        }

        $this->info("Deleted {$deleted} of " . count($webhooks) . " webhooks.");
        return 0;
    }

    private function activateWebhook(PayFortAcquirer $acquirer, $webhookId): int
    {
        $webhookUrl = $this->option('url') ?? config('app.url') . '/api/v1/webhooks/payfort';

        $this->info("Activating webhook #{$webhookId}...");
        $this->info("URL: {$webhookUrl}");

        $result = $acquirer->updateWebhook($webhookId, [
            'isActive' => true,
            'url' => $webhookUrl,
            'events' => [
                'transaction_created',
                'transaction_paid',
                'transaction_refunded',
                // PayFort sends rejected/canceled changes through transaction_updated with status.
                'transaction_updated',
                'transfer_created',
                'transfer_completed',
                'transfer_canceled',
                'transfer_updated',
                'infraction',
            ],
        ]);

        if ($result['success']) {
            $this->info('Webhook activated successfully!');
            $this->line(json_encode($result['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->error('Failed to activate webhook: ' . ($result['error'] ?? 'Unknown'));
        if (isset($result['response'])) {
            $this->line(json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        return 1;
    }
}
