<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\MessagingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessagingService
{
    /**
     * Send a message via the configured provider.
     */
    public function send(
        MessagingProvider $provider,
        string $recipientPhone,
        string $messageBody,
        ?string $recipientName = null,
        ?MessageTemplate $template = null,
        ?string $triggerEvent = null,
        ?string $chargeId = null,
    ): MessageLog {
        $log = MessageLog::create([
            'account_id' => $provider->account_id,
            'provider_id' => $provider->id,
            'template_id' => $template?->id,
            'channel' => $provider->channel,
            'recipient_phone' => $recipientPhone,
            'recipient_name' => $recipientName,
            'message_body' => $messageBody,
            'trigger_event' => $triggerEvent,
            'charge_id' => $chargeId,
            'status' => MessageLog::STATUS_PENDING,
        ]);

        try {
            $result = match ($provider->provider_name) {
                MessagingProvider::PROVIDER_EVOLUTION_API => $this->sendViaEvolutionApi($provider, $recipientPhone, $messageBody),
                MessagingProvider::PROVIDER_ZAPI => $this->sendViaZApi($provider, $recipientPhone, $messageBody),
                MessagingProvider::PROVIDER_TWILIO => $this->sendViaTwilio($provider, $recipientPhone, $messageBody),
                MessagingProvider::PROVIDER_VONAGE => $this->sendViaVonage($provider, $recipientPhone, $messageBody),
                MessagingProvider::PROVIDER_GATEPRO => $this->sendViaGatePro($provider, $recipientPhone, $messageBody),
                default => $this->sendViaCustom($provider, $recipientPhone, $messageBody),
            };

            $log->markAsSent($result['external_id'] ?? null, $result['response'] ?? null);
            $provider->incrementSent();
            $template?->incrementSent();

            Log::info('Message sent successfully', [
                'log_id' => $log->id,
                'channel' => $provider->channel,
                'provider' => $provider->provider_name,
                'recipient' => $recipientPhone,
            ]);
        } catch (\Exception $e) {
            $log->markAsFailed($e->getMessage());
            $provider->incrementFailed();

            Log::error('Failed to send message', [
                'log_id' => $log->id,
                'channel' => $provider->channel,
                'provider' => $provider->provider_name,
                'recipient' => $recipientPhone,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Send via triggered event (finds matching templates and sends).
     */
    public function sendForEvent(string $accountId, string $event, array $variables, string $recipientPhone, ?string $recipientName = null, ?string $chargeId = null): array
    {
        $sentLogs = [];

        $templates = MessageTemplate::forAccount($accountId)
            ->active()
            ->forEvent($event)
            ->with('provider')
            ->get();

        foreach ($templates as $template) {
            $provider = $template->provider;
            if (!$provider || !$provider->is_active) {
                // Try to find any active provider for this channel
                $provider = MessagingProvider::forAccount($accountId)
                    ->where('channel', $template->channel)
                    ->active()
                    ->first();
            }

            if (!$provider) {
                Log::warning('No active provider for message template', [
                    'template_id' => $template->id,
                    'channel' => $template->channel,
                    'event' => $event,
                ]);
                continue;
            }

            $renderedMessage = $template->renderMessage($variables);
            $log = $this->send($provider, $recipientPhone, $renderedMessage, $recipientName, $template, $event, $chargeId);
            $sentLogs[] = $log;
        }

        return $sentLogs;
    }

    /**
     * Send a quick/manual message without a template.
     */
    public function sendQuick(MessagingProvider $provider, string $recipientPhone, string $messageBody, ?string $recipientName = null): MessageLog
    {
        return $this->send($provider, $recipientPhone, $messageBody, $recipientName);
    }

    /**
     * Test provider connection.
     */
    public function testConnection(MessagingProvider $provider): array
    {
        try {
            return match ($provider->provider_name) {
                MessagingProvider::PROVIDER_EVOLUTION_API => $this->testEvolutionApi($provider),
                MessagingProvider::PROVIDER_ZAPI => $this->testZApi($provider),
                MessagingProvider::PROVIDER_TWILIO => $this->testTwilio($provider),
                MessagingProvider::PROVIDER_VONAGE => $this->testVonage($provider),
                MessagingProvider::PROVIDER_GATEPRO => $this->testGatePro($provider),
                default => ['valid' => true, 'message' => 'Provider configurado (sem teste disponÃ­vel)'],
            };
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Erro ao testar conexÃ£o: ' . $e->getMessage()];
        }
    }

    // ---- Evolution API (WhatsApp) ----

    private function sendViaEvolutionApi(MessagingProvider $provider, string $phone, string $message): array
    {
        $baseUrl = rtrim($provider->api_url, '/');
        $instance = $provider->instance_name;

        $response = Http::withHeaders([
            'apikey' => $provider->api_key,
            'Content-Type' => 'application/json',
        ])->post("{$baseUrl}/message/sendText/{$instance}", [
            'number' => $this->formatPhone($phone),
            'text' => $message,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Evolution API error: ' . $response->body());
        }

        $data = $response->json();
        return [
            'external_id' => $data['key']['id'] ?? null,
            'response' => json_encode($data),
        ];
    }

    private function testEvolutionApi(MessagingProvider $provider): array
    {
        $baseUrl = rtrim($provider->api_url, '/');
        $instance = $provider->instance_name;

        $response = Http::withHeaders([
            'apikey' => $provider->api_key,
        ])->get("{$baseUrl}/instance/connectionState/{$instance}");

        if ($response->successful()) {
            $data = $response->json();
            $state = $data['instance']['state'] ?? $data['state'] ?? 'unknown';
            return [
                'valid' => $state === 'open',
                'message' => $state === 'open' ? 'InstÃ¢ncia conectada!' : "InstÃ¢ncia em estado: {$state}",
            ];
        }

        return ['valid' => false, 'message' => 'NÃ£o foi possÃ­vel conectar Ã  Evolution API'];
    }

    // ---- Z-API (WhatsApp) ----

    private function sendViaZApi(MessagingProvider $provider, string $phone, string $message): array
    {
        $baseUrl = rtrim($provider->api_url, '/');

        $response = Http::withHeaders([
            'Client-Token' => $provider->instance_token,
        ])->post("{$baseUrl}/send-text", [
            'phone' => $this->formatPhone($phone),
            'message' => $message,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Z-API error: ' . $response->body());
        }

        $data = $response->json();
        return [
            'external_id' => $data['messageId'] ?? null,
            'response' => json_encode($data),
        ];
    }

    private function testZApi(MessagingProvider $provider): array
    {
        $baseUrl = rtrim($provider->api_url, '/');

        $response = Http::withHeaders([
            'Client-Token' => $provider->instance_token,
        ])->get("{$baseUrl}/status");

        if ($response->successful()) {
            $data = $response->json();
            $connected = ($data['connected'] ?? false) === true;
            return [
                'valid' => $connected,
                'message' => $connected ? 'WhatsApp conectado!' : 'WhatsApp desconectado',
            ];
        }

        return ['valid' => false, 'message' => 'NÃ£o foi possÃ­vel conectar Ã  Z-API'];
    }

    // ---- Twilio (SMS) ----

    private function sendViaTwilio(MessagingProvider $provider, string $phone, string $message): array
    {
        $settings = $provider->settings ?? [];
        $accountSid = $settings['account_sid'] ?? '';
        $authToken = $provider->api_key;
        $from = $provider->sender_number;

        $response = Http::withBasicAuth($accountSid, $authToken)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'To' => $this->formatPhoneInternational($phone),
                'From' => $from,
                'Body' => $message,
            ]);

        if (!$response->successful()) {
            throw new \Exception('Twilio error: ' . $response->body());
        }

        $data = $response->json();
        return [
            'external_id' => $data['sid'] ?? null,
            'response' => json_encode($data),
        ];
    }

    private function testTwilio(MessagingProvider $provider): array
    {
        $settings = $provider->settings ?? [];
        $accountSid = $settings['account_sid'] ?? '';
        $authToken = $provider->api_key;

        $response = Http::withBasicAuth($accountSid, $authToken)
            ->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}.json");

        return [
            'valid' => $response->successful(),
            'message' => $response->successful() ? 'Conta Twilio vÃ¡lida!' : 'Credenciais invÃ¡lidas',
        ];
    }

    // ---- Vonage/Nexmo (SMS) ----

    private function sendViaVonage(MessagingProvider $provider, string $phone, string $message): array
    {
        $settings = $provider->settings ?? [];
        $apiSecret = $settings['api_secret'] ?? '';

        $response = Http::post('https://rest.nexmo.com/sms/json', [
            'api_key' => $provider->api_key,
            'api_secret' => $apiSecret,
            'to' => $this->formatPhone($phone),
            'from' => $provider->sender_number ?? 'Vector Pagamentos',
            'text' => $message,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Vonage error: ' . $response->body());
        }

        $data = $response->json();
        $msg = $data['messages'][0] ?? [];
        if (($msg['status'] ?? '1') !== '0') {
            throw new \Exception('Vonage error: ' . ($msg['error-text'] ?? 'Unknown error'));
        }

        return [
            'external_id' => $msg['message-id'] ?? null,
            'response' => json_encode($data),
        ];
    }

    private function testVonage(MessagingProvider $provider): array
    {
        $settings = $provider->settings ?? [];
        $apiSecret = $settings['api_secret'] ?? '';

        $response = Http::get('https://rest.nexmo.com/account/get-balance', [
            'api_key' => $provider->api_key,
            'api_secret' => $apiSecret,
        ]);

        return [
            'valid' => $response->successful(),
            'message' => $response->successful() ? 'Conta Vonage vÃ¡lida!' : 'Credenciais invÃ¡lidas',
        ];
    }

    // ---- Vector Pagamentos SMS (built-in) ----

    private function sendViaGatePro(MessagingProvider $provider, string $phone, string $message): array
    {
        $smsUrl = config('services.vectorpagamentos_sms.url', env('VECTORPAGAMENTOS_SMS_URL', ''));
        $smsKey = config('services.vectorpagamentos_sms.key', env('VECTORPAGAMENTOS_SMS_KEY', ''));
        $smsSender = $provider->sender_number ?? config('services.vectorpagamentos_sms.sender', env('VECTORPAGAMENTOS_SMS_SENDER', 'Vector Pagamentos'));

        if (!$smsUrl) {
            throw new \Exception('Vector Pagamentos SMS: URL do gateway SMS nÃ£o configurada (VECTORPAGAMENTOS_SMS_URL)');
        }

        $formattedPhone = $this->formatPhoneInternational($phone);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $smsKey,
            'Content-Type' => 'application/json',
        ])->post(rtrim($smsUrl, '/') . '/send', [
            'to' => $formattedPhone,
            'from' => $smsSender,
            'message' => $message,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Vector Pagamentos SMS error: ' . $response->body());
        }

        $data = $response->json();
        return [
            'external_id' => $data['message_id'] ?? $data['id'] ?? null,
            'response' => json_encode($data),
        ];
    }

    private function testGatePro(MessagingProvider $provider): array
    {
        $smsUrl = config('services.vectorpagamentos_sms.url', env('VECTORPAGAMENTOS_SMS_URL', ''));
        $smsKey = config('services.vectorpagamentos_sms.key', env('VECTORPAGAMENTOS_SMS_KEY', ''));

        if (!$smsUrl) {
            return ['valid' => false, 'message' => 'URL do gateway SMS nÃ£o configurada'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $smsKey,
            ])->get(rtrim($smsUrl, '/') . '/status');

            return [
                'valid' => $response->successful(),
                'message' => $response->successful() ? 'Vector Pagamentos SMS ativo!' : 'ServiÃ§o SMS indisponÃ­vel',
            ];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => 'Erro ao conectar: ' . $e->getMessage()];
        }
    }

    // ---- Custom / Generic ----

    private function sendViaCustom(MessagingProvider $provider, string $phone, string $message): array
    {
        $baseUrl = rtrim($provider->api_url ?? '', '/');
        if (!$baseUrl) {
            throw new \Exception('URL da API nÃ£o configurada');
        }

        $headers = ['Content-Type' => 'application/json'];
        if ($provider->api_key) {
            $headers['Authorization'] = 'Bearer ' . $provider->api_key;
        }

        $response = Http::withHeaders($headers)->post($baseUrl, [
            'phone' => $this->formatPhone($phone),
            'message' => $message,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Custom API error: ' . $response->body());
        }

        return [
            'external_id' => null,
            'response' => $response->body(),
        ];
    }

    // ---- phone formatting ----

    private function formatPhone(string $phone): string
    {
        // Remove non-numeric characters
        return preg_replace('/\D/', '', $phone);
    }

    private function formatPhoneInternational(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone);
        if (!str_starts_with($clean, '+')) {
            if (strlen($clean) <= 11) {
                $clean = '55' . $clean; // Brazil
            }
            $clean = '+' . $clean;
        }
        return $clean;
    }
}
