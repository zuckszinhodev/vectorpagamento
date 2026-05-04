<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MessagingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service to interact with the Evolution API for WhatsApp instance management.
 * Handles: creating instances, getting QR codes, checking connection state, and disconnecting.
 */
class EvolutionApiService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.evolution.url', env('EVOLUTION_API_URL', 'http://evolution-api:8080')),
            '/'
        );
        $this->apiKey = config('services.evolution.key', env('EVOLUTION_API_KEY', ''));
    }

    /**
     * Check if Evolution API is configured and reachable.
     */
    public function isConfigured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->apiKey);
    }

    /**
     * Create an instance on Evolution API.
     * If it already exists, returns the existing instance info.
     */
    public function createInstance(string $instanceName, ?string $webhookUrl = null): array
    {
        $payload = [
            'instanceName' => $instanceName,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
            'rejectCall' => false,
            'groupsIgnore' => true,
            'alwaysOnline' => false,
            'readMessages' => false,
            'readStatus' => false,
            'syncFullHistory' => false,
        ];

        if ($webhookUrl) {
            $payload['webhook'] = [
                'url' => $webhookUrl,
                'byEvents' => false,
                'base64' => false,
                'events' => [
                    'MESSAGES_UPSERT',
                    'CONNECTION_UPDATE',
                    'QRCODE_UPDATED',
                ],
            ];
        }

        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/instance/create", $payload);

        if (!$response->successful()) {
            $body = $response->json();
            // If instance already exists, that's OK
            if (str_contains($response->body(), 'already') || str_contains($response->body(), 'exists')) {
                Log::info('Evolution API instance already exists', ['instance' => $instanceName]);
                return [
                    'success' => true,
                    'existed' => true,
                    'instance' => $instanceName,
                ];
            }
            Log::error('Evolution API createInstance failed', [
                'instance' => $instanceName,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Erro ao criar instância: ' . ($body['message'] ?? $response->body()));
        }

        $data = $response->json();

        return [
            'success' => true,
            'existed' => false,
            'instance' => $instanceName,
            'qrcode' => $data['qrcode'] ?? null,
            'pairingCode' => $data['pairingCode'] ?? null,
        ];
    }

    /**
     * Get the QR code for connecting a WhatsApp account.
     * Returns base64 QR code image.
     */
    public function getQrCode(string $instanceName): array
    {
        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
        ])->get("{$this->baseUrl}/instance/connect/{$instanceName}");

        if (!$response->successful()) {
            Log::warning('Evolution API getQrCode failed', [
                'instance' => $instanceName,
                'status' => $response->status(),
            ]);
            return [
                'success' => false,
                'message' => 'Não foi possível obter o QR Code',
            ];
        }

        $data = $response->json();

        // Check if already connected
        if (isset($data['instance']['state']) && $data['instance']['state'] === 'open') {
            return [
                'success' => true,
                'connected' => true,
                'state' => 'open',
                'message' => 'WhatsApp já está conectado!',
            ];
        }

        return [
            'success' => true,
            'connected' => false,
            'state' => $data['instance']['state'] ?? 'connecting',
            'base64' => $data['base64'] ?? null,
            'code' => $data['code'] ?? null,
            'pairingCode' => $data['pairingCode'] ?? null,
        ];
    }

    /**
     * Get the connection state of an instance.
     */
    public function getConnectionState(string $instanceName): array
    {
        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
        ])->get("{$this->baseUrl}/instance/connectionState/{$instanceName}");

        if (!$response->successful()) {
            return [
                'state' => 'unknown',
                'connected' => false,
                'message' => 'Não foi possível verificar o status',
            ];
        }

        $data = $response->json();
        $state = $data['instance']['state'] ?? $data['state'] ?? 'unknown';

        return [
            'state' => $state,
            'connected' => $state === 'open',
            'message' => match ($state) {
                'open' => 'WhatsApp conectado!',
                'close' => 'WhatsApp desconectado',
                'connecting' => 'Conectando... Escaneie o QR Code',
                default => "Estado: {$state}",
            },
        ];
    }

    /**
     * Get instance phone/profile info when connected.
     */
    public function getInstanceInfo(string $instanceName): array
    {
        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
        ])->get("{$this->baseUrl}/instance/fetchInstances", [
            'instanceName' => $instanceName,
        ]);

        if (!$response->successful()) {
            return [];
        }

        $instances = $response->json();
        if (is_array($instances) && count($instances) > 0) {
            $inst = $instances[0];
            return [
                'instance_name' => $inst['instance']['instanceName'] ?? $instanceName,
                'owner' => $inst['instance']['owner'] ?? null,
                'phone' => $inst['instance']['owner'] ?? null,
                'profile_name' => $inst['instance']['profileName'] ?? null,
                'profile_picture' => $inst['instance']['profilePictureUrl'] ?? null,
                'status' => $inst['instance']['status'] ?? 'unknown',
            ];
        }

        return [];
    }

    /**
     * Disconnect / Logout an instance.
     */
    public function logout(string $instanceName): array
    {
        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
        ])->delete("{$this->baseUrl}/instance/logout/{$instanceName}");

        if ($response->successful()) {
            return ['success' => true, 'message' => 'WhatsApp desconectado'];
        }

        return ['success' => false, 'message' => 'Erro ao desconectar'];
    }

    /**
     * Restart an instance (useful when QR code expires).
     */
    public function restartInstance(string $instanceName): array
    {
        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
        ])->put("{$this->baseUrl}/instance/restart/{$instanceName}");

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Instância reiniciada'];
        }

        return ['success' => false, 'message' => 'Erro ao reiniciar instância'];
    }

    /**
     * Delete an instance entirely.
     */
    public function deleteInstance(string $instanceName): array
    {
        $response = Http::withHeaders([
            'apikey' => $this->apiKey,
        ])->delete("{$this->baseUrl}/instance/delete/{$instanceName}");

        return [
            'success' => $response->successful(),
            'message' => $response->successful() ? 'Instância removida' : 'Erro ao remover instância',
        ];
    }
}
