<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessagingProvider;
use App\Models\MessageTemplate;
use App\Models\MessageLog;
use App\Services\MessagingService;
use App\Services\EvolutionApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MessagingController extends Controller
{
    public function __construct(
        private MessagingService $messagingService,
        private EvolutionApiService $evolutionApi,
    ) {}

    // ================================================================
    //  PROVIDERS
    // ================================================================

    /**
     * GET /api/v1/messaging/providers
     */
    public function indexProviders(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $providers = MessagingProvider::forAccount($accountId)
            ->orderBy('channel')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($p) => $this->formatProvider($p));

        return response()->json([
            'success' => true,
            'data' => $providers,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::indexProviders error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * POST /api/v1/messaging/providers
     */
    public function storeProvider(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'channel' => 'required|in:whatsapp,sms',
            'provider_name' => 'required|string|max:50',
            'api_url' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:500',
            'instance_name' => 'nullable|string|max:100',
            'instance_token' => 'nullable|string|max:500',
            'sender_number' => 'nullable|string|max:20',
            'settings' => 'nullable|array',
        ]);

        $accountId = $request->user()->account_id;

        $provider = MessagingProvider::create([
            'account_id' => $accountId,
            'channel' => $request->input('channel'),
            'provider_name' => $request->input('provider_name'),
            'api_url' => $request->input('api_url'),
            'api_key' => $request->input('api_key'),
            'instance_name' => $request->input('instance_name'),
            'instance_token' => $request->input('instance_token'),
            'sender_number' => $request->input('sender_number'),
            'settings' => $request->input('settings'),
            'is_active' => true,
        ]);

        Log::info('Messaging provider created', [
            'account_id' => $accountId,
            'provider_id' => $provider->id,
            'channel' => $provider->channel,
            'provider_name' => $provider->provider_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Provedor configurado com sucesso!',
            'data' => $this->formatProvider($provider),
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::storeProvider error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * PUT /api/v1/messaging/providers/{id}
     */
    public function updateProvider(Request $request, string $id): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $provider = MessagingProvider::forAccount($accountId)->findOrFail($id);

        $request->validate([
            'provider_name' => 'sometimes|string|max:50',
            'api_url' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:500',
            'instance_name' => 'nullable|string|max:100',
            'instance_token' => 'nullable|string|max:500',
            'sender_number' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
        ]);

        $data = $request->only([
            'provider_name', 'api_url', 'api_key', 'instance_name',
            'instance_token', 'sender_number', 'is_active', 'settings',
        ]);

        // Don't overwrite secrets with masked values
        if (isset($data['api_key']) && str_contains($data['api_key'], '***')) {
            unset($data['api_key']);
        }
        if (isset($data['instance_token']) && str_contains($data['instance_token'], '***')) {
            unset($data['instance_token']);
        }

        $provider->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json([
            'success' => true,
            'message' => 'Provedor atualizado com sucesso!',
            'data' => $this->formatProvider($provider->fresh()),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::updateProvider error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * DELETE /api/v1/messaging/providers/{id}
     */
    public function destroyProvider(Request $request, string $id): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $provider = MessagingProvider::forAccount($accountId)->findOrFail($id);

        $provider->delete();

        return response()->json([
            'success' => true,
            'message' => 'Provedor removido com sucesso.',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::destroyProvider error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * POST /api/v1/messaging/providers/auto-setup
     * Auto-creates providers using the platform's built-in services.
     * Supports: whatsapp (Evolution API) and sms (GatePro SMS).
     */
    public function autoSetupProvider(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $request->validate([
            'channel' => 'required|in:whatsapp,sms,both',
            'instance_name' => 'required_if:channel,whatsapp,both|nullable|string|max:100',
        ]);

        $channel = $request->input('channel', 'both');
        $created = [];
        $qrcode = null;

        // WhatsApp via Evolution API
        if (in_array($channel, ['whatsapp', 'both'])) {
            if (!$this->evolutionApi->isConfigured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Evolution API não está configurada no servidor.',
                ], 422);
            }

            $evolutionUrl = config('services.evolution.url', env('EVOLUTION_API_URL', 'http://evolution-api:8080'));
            $evolutionKey = config('services.evolution.key', env('EVOLUTION_API_KEY', ''));
            $instanceName = $request->input('instance_name', 'wazerpay');

            $existing = MessagingProvider::forAccount($accountId)
                ->where('provider_name', MessagingProvider::PROVIDER_EVOLUTION_API)
                ->first();

            if ($existing) {
                $created[] = $this->formatProvider($existing);
                $instanceName = $existing->instance_name ?? $instanceName;
            } else {
                // Create the instance on Evolution API first
                try {
                    $result = $this->evolutionApi->createInstance($instanceName);
                    $qrcode = $result['qrcode'] ?? null;
                } catch (\Exception $e) {
                    Log::warning('Evolution API createInstance error (may already exist)', [
                        'instance' => $instanceName,
                        'error' => $e->getMessage(),
                    ]);
                }

                $provider = MessagingProvider::create([
                    'account_id' => $accountId,
                    'channel' => 'whatsapp',
                    'provider_name' => MessagingProvider::PROVIDER_EVOLUTION_API,
                    'api_url' => $evolutionUrl,
                    'api_key' => $evolutionKey,
                    'instance_name' => $instanceName,
                    'is_active' => true,
                ]);
                $created[] = $this->formatProvider($provider);

                Log::info('Evolution API provider auto-setup', [
                    'account_id' => $accountId,
                    'provider_id' => $provider->id,
                ]);
            }

            // Also get the QR code for immediate display
            if (!$qrcode) {
                try {
                    $qrResult = $this->evolutionApi->getQrCode($instanceName);
                    if ($qrResult['success'] && !($qrResult['connected'] ?? false)) {
                        $qrcode = $qrResult['base64'] ?? null;
                    }
                } catch (\Exception $e) {
                    // Non-fatal
                }
            }
        }

        // SMS via GatePro built-in
        if (in_array($channel, ['sms', 'both'])) {
            $existing = MessagingProvider::forAccount($accountId)
                ->where('provider_name', MessagingProvider::PROVIDER_GATEPRO)
                ->first();

            if ($existing) {
                $created[] = $this->formatProvider($existing);
            } else {
                $smsUrl = config('services.wazerpay_sms.url', env('GATEPRO_SMS_URL', ''));
                $smsKey = config('services.wazerpay_sms.key', env('GATEPRO_SMS_KEY', ''));
                $smsSender = config('services.wazerpay_sms.sender', env('GATEPRO_SMS_SENDER', 'GatePro'));

                $provider = MessagingProvider::create([
                    'account_id' => $accountId,
                    'channel' => 'sms',
                    'provider_name' => MessagingProvider::PROVIDER_GATEPRO,
                    'api_url' => $smsUrl ?: null,
                    'api_key' => $smsKey ?: null,
                    'sender_number' => $smsSender,
                    'is_active' => true,
                ]);
                $created[] = $this->formatProvider($provider);

                Log::info('GatePro SMS provider auto-setup', [
                    'account_id' => $accountId,
                    'provider_id' => $provider->id,
                ]);
            }
        }

        $responseData = [
            'success' => count($created) > 0,
            'message' => count($created) > 0 ? 'Provedores configurados com sucesso!' : 'Nenhum provedor disponível no servidor.',
            'data' => $created,
        ];

        if ($qrcode) {
            $responseData['qrcode'] = $qrcode;
        }

        return response()->json($responseData, count($created) > 0 ? 201 : 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::autoSetupProvider error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    // ================================================================
    //  WHATSAPP CONNECTION MANAGEMENT
    // ================================================================

    /**
     * GET /api/v1/messaging/whatsapp/qrcode
     * Get the QR code for connecting WhatsApp.
     */
    public function whatsappQrCode(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $provider = MessagingProvider::forAccount($accountId)
            ->where('provider_name', MessagingProvider::PROVIDER_EVOLUTION_API)
            ->first();

        if (!$provider) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum provedor WhatsApp configurado. Ative primeiro.',
            ], 404);
        }

        $instanceName = $provider->instance_name ?? 'wazerpay';

        try {
            $result = $this->evolutionApi->getQrCode($instanceName);
        } catch (\Exception $e) {
            // Instance may not exist yet, try to create it
            try {
                $this->evolutionApi->createInstance($instanceName);
                $result = $this->evolutionApi->getQrCode($instanceName);
            } catch (\Exception $e2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao obter QR Code: ' . $e2->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'success' => $result['success'] ?? false,
            'data' => $result,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::whatsappQrCode error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * GET /api/v1/messaging/whatsapp/status
     * Get the connection state of the WhatsApp instance.
     */
    public function whatsappStatus(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $provider = MessagingProvider::forAccount($accountId)
            ->where('provider_name', MessagingProvider::PROVIDER_EVOLUTION_API)
            ->first();

        if (!$provider) {
            return response()->json([
                'success' => true,
                'data' => [
                    'configured' => false,
                    'state' => 'not_configured',
                    'connected' => false,
                    'message' => 'WhatsApp não configurado',
                ],
            ]);
        }

        $instanceName = $provider->instance_name ?? 'wazerpay';
        $state = $this->evolutionApi->getConnectionState($instanceName);
        $info = [];

        // If connected, get profile info
        if ($state['connected'] ?? false) {
            $info = $this->evolutionApi->getInstanceInfo($instanceName);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'configured' => true,
                'provider_id' => $provider->id,
                'instance_name' => $instanceName,
            ], $state, $info),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::whatsappStatus error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * POST /api/v1/messaging/whatsapp/disconnect
     * Disconnect/logout the WhatsApp session.
     */
    public function whatsappDisconnect(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $provider = MessagingProvider::forAccount($accountId)
            ->where('provider_name', MessagingProvider::PROVIDER_EVOLUTION_API)
            ->first();

        if (!$provider) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum provedor WhatsApp configurado.',
            ], 404);
        }

        $instanceName = $provider->instance_name ?? 'wazerpay';
        $result = $this->evolutionApi->logout($instanceName);

        Log::info('WhatsApp disconnected', [
            'account_id' => $accountId,
            'instance' => $instanceName,
        ]);

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'WhatsApp desconectado',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::whatsappDisconnect error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * POST /api/v1/messaging/whatsapp/restart
     * Restart the WhatsApp instance (useful when QR code expires).
     */
    public function whatsappRestart(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $provider = MessagingProvider::forAccount($accountId)
            ->where('provider_name', MessagingProvider::PROVIDER_EVOLUTION_API)
            ->first();

        if (!$provider) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum provedor WhatsApp configurado.',
            ], 404);
        }

        $instanceName = $provider->instance_name ?? 'wazerpay';
        $result = $this->evolutionApi->restartInstance($instanceName);

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'Instância reiniciada',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::whatsappRestart error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * POST /api/v1/messaging/providers/{id}/test
     */
    public function testProvider(Request $request, string $id): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $provider = MessagingProvider::forAccount($accountId)->findOrFail($id);

        $result = $this->messagingService->testConnection($provider);

        return response()->json([
            'success' => true,
            'data' => $result,
        ], $result['valid'] ? 200 : 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::testProvider error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * POST /api/v1/messaging/providers/{id}/send-test
     */
    public function sendTestMessage(Request $request, string $id): JsonResponse
    {
        try {
        $request->validate([
            'phone' => 'required|string|max:20',
            'message' => 'required|string|max:2000',
        ]);

        $accountId = $request->user()->account_id;
        $provider = MessagingProvider::forAccount($accountId)->findOrFail($id);

        if (!$provider->is_active) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'provider_inactive', 'message' => 'Provedor está desativado'],
            ], 400);
        }

        $log = $this->messagingService->sendQuick(
            $provider,
            $request->input('phone'),
            $request->input('message'),
            'Teste'
        );

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $log->status,
                'message_id' => $log->id,
            ],
            'message' => $log->status === 'sent' ? 'Mensagem enviada com sucesso!' : 'Falha ao enviar mensagem',
        ], $log->status === 'sent' ? 200 : 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::sendTestMessage error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    // ================================================================
    //  TEMPLATES
    // ================================================================

    /**
     * GET /api/v1/messaging/templates
     */
    public function indexTemplates(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $channel = $request->query('channel');

        $query = MessageTemplate::forAccount($accountId)
            ->with('provider:id,provider_name,channel')
            ->orderBy('created_at', 'desc');

        if ($channel) {
            $query->where('channel', $channel);
        }

        $templates = $query->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'channel' => $t->channel,
            'trigger_event' => $t->trigger_event,
            'message_body' => $t->message_body,
            'is_active' => $t->is_active,
            'times_sent' => $t->times_sent,
            'provider' => $t->provider ? [
                'id' => $t->provider->id,
                'name' => $t->provider->provider_name,
                'channel' => $t->provider->channel,
            ] : null,
            'created_at' => $t->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::indexTemplates error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * POST /api/v1/messaging/templates
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'name' => 'required|string|max:100',
            'channel' => 'required|in:whatsapp,sms',
            'trigger_event' => 'required|string|max:50',
            'message_body' => 'required|string|max:4096',
            'provider_id' => 'nullable|uuid',
            'is_active' => 'sometimes|boolean',
        ]);

        $accountId = $request->user()->account_id;

        // Validate provider belongs to account if specified
        if ($request->input('provider_id')) {
            MessagingProvider::forAccount($accountId)->findOrFail($request->input('provider_id'));
        }

        $template = MessageTemplate::create([
            'account_id' => $accountId,
            'provider_id' => $request->input('provider_id'),
            'channel' => $request->input('channel'),
            'name' => $request->input('name'),
            'trigger_event' => $request->input('trigger_event'),
            'message_body' => $request->input('message_body'),
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Template criado com sucesso!',
            'data' => $template,
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::storeTemplate error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * PUT /api/v1/messaging/templates/{id}
     */
    public function updateTemplate(Request $request, string $id): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $template = MessageTemplate::forAccount($accountId)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:100',
            'trigger_event' => 'sometimes|string|max:50',
            'message_body' => 'sometimes|string|max:4096',
            'provider_id' => 'nullable|uuid',
            'is_active' => 'sometimes|boolean',
        ]);

        $template->update($request->only([
            'name', 'trigger_event', 'message_body', 'provider_id', 'is_active',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Template atualizado com sucesso!',
            'data' => $template->fresh(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::updateTemplate error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * DELETE /api/v1/messaging/templates/{id}
     */
    public function destroyTemplate(Request $request, string $id): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $template = MessageTemplate::forAccount($accountId)->findOrFail($id);

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template removido com sucesso.',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::destroyTemplate error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * GET /api/v1/messaging/templates/variables
     */
    public function templateVariables(): JsonResponse
    {
        try {
        return response()->json([
            'success' => true,
            'data' => MessageTemplate::VARIABLES,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::templateVariables error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * GET /api/v1/messaging/templates/triggers
     */
    public function templateTriggers(): JsonResponse
    {
        try {
        $triggers = [
            ['value' => 'payment.approved', 'label' => 'Pagamento Aprovado'],
            ['value' => 'payment.refused', 'label' => 'Pagamento Recusado'],
            ['value' => 'payment.expired', 'label' => 'Pagamento Expirado'],
            ['value' => 'charge.created', 'label' => 'Cobrança Criada'],
            ['value' => 'pix.generated', 'label' => 'PIX Gerado'],
            ['value' => 'boleto.generated', 'label' => 'Boleto Gerado'],
            ['value' => 'refund.completed', 'label' => 'Reembolso Concluído'],
            ['value' => 'chargeback.created', 'label' => 'Chargeback Criado'],
            ['value' => 'checkout.abandoned', 'label' => 'Checkout Abandonado'],
            ['value' => 'pix.expired', 'label' => 'PIX Expirado'],
            ['value' => 'boleto.expired', 'label' => 'Boleto Expirado'],
        ];

        return response()->json([
            'success' => true,
            'data' => $triggers,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::templateTriggers error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    // ================================================================
    //  LOGS
    // ================================================================

    /**
     * GET /api/v1/messaging/logs
     */
    public function indexLogs(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $perPage = min((int) ($request->query('per_page', 20)), 100);
        $channel = $request->query('channel');
        $status = $request->query('status');

        $query = MessageLog::forAccount($accountId)
            ->with('provider:id,provider_name,channel')
            ->orderBy('created_at', 'desc');

        if ($channel) {
            $query->forChannel($channel);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::indexLogs error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * GET /api/v1/messaging/stats
     */
    public function stats(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $providers = MessagingProvider::forAccount($accountId)->get();
        $totalSent = $providers->sum('messages_sent');
        $totalFailed = $providers->sum('messages_failed');

        $whatsappProviders = $providers->where('channel', 'whatsapp');
        $smsProviders = $providers->where('channel', 'sms');

        $templateCount = MessageTemplate::forAccount($accountId)->count();
        $activeTemplateCount = MessageTemplate::forAccount($accountId)->active()->count();

        // Last 30 days logs
        $recentLogs = MessageLog::forAccount($accountId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'whatsapp' => [
                    'providers' => $whatsappProviders->count(),
                    'active' => $whatsappProviders->where('is_active', true)->count(),
                    'sent' => $whatsappProviders->sum('messages_sent'),
                    'failed' => $whatsappProviders->sum('messages_failed'),
                ],
                'sms' => [
                    'providers' => $smsProviders->count(),
                    'active' => $smsProviders->where('is_active', true)->count(),
                    'sent' => $smsProviders->sum('messages_sent'),
                    'failed' => $smsProviders->sum('messages_failed'),
                ],
                'templates' => [
                    'total' => $templateCount,
                    'active' => $activeTemplateCount,
                ],
                'recent_logs' => $recentLogs,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MessagingController::stats error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    // ================================================================
    //  HELPERS
    // ================================================================

    private function formatProvider(MessagingProvider $p): array
    {
        return [
            'id' => $p->id,
            'channel' => $p->channel,
            'provider_name' => $p->provider_name,
            'api_url' => $p->api_url,
            'masked_api_key' => $p->masked_api_key,
            'instance_name' => $p->instance_name,
            'masked_token' => $p->masked_token,
            'sender_number' => $p->sender_number,
            'is_active' => $p->is_active,
            'settings' => $p->settings,
            'messages_sent' => $p->messages_sent,
            'messages_failed' => $p->messages_failed,
            'last_sent_at' => $p->last_sent_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
