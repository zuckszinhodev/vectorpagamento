<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserWebhookController extends Controller
{
    /**
     * Available events for user webhooks
     */
    private const AVAILABLE_EVENTS = [
        'charge.created',
        'charge.paid',
        'charge.failed',
        'charge.refunded',
        'charge.disputed',
        'payment.approved',
        'payment.rejected',
        'payment.refunded',
        'payment.expired',
        'payment.cancelled',
        'payment.chargedback',
        'withdrawal.requested',
        'withdrawal.completed',
        '*',
    ];

    /**
     * List user's webhooks
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        if (!$user->account_id) {
            return response()->json([
                'success' => true,
                'data' => [],
                'available_events' => self::AVAILABLE_EVENTS,
            ]);
        }

        $webhooks = Webhook::where('account_id', $user->account_id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($webhook) {
                return [
                    'id' => $webhook->id,
                    'url' => $webhook->url,
                    'events' => $webhook->events ?? [],
                    'is_active' => $webhook->is_active,
                    'secret' => $webhook->secret ? $this->maskSecret($webhook->secret) : null,
                    'created_at' => $webhook->created_at?->toIso8601String(),
                    'updated_at' => $webhook->updated_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $webhooks,
            'available_events' => self::AVAILABLE_EVENTS,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserWebhookController::index error', [
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
     * Create a new webhook
     */
    public function store(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        if (!$user->account_id) {
            return response()->json([
                'success' => false,
                'message' => 'Conta não encontrada',
            ], 400);
        }

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', self::AVAILABLE_EVENTS)],
        ]);

        // Limit webhooks per account
        $existingCount = Webhook::where('account_id', $user->account_id)->count();
        if ($existingCount >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Limite máximo de 5 webhooks por conta',
            ], 422);
        }

        $webhook = Webhook::create([
            'account_id' => $user->account_id,
            'url' => $validated['url'],
            'events' => $validated['events'],
            'secret' => null,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $webhook->id,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'secret' => null,
                'created_at' => $webhook->created_at?->toIso8601String(),
            ],
            'message' => 'Webhook criado com sucesso',
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserWebhookController::store error', [
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
     * Update a webhook
     */
    public function update(Request $request, string $webhookId): JsonResponse
    {
        try {
        $user = $request->user();

        $webhook = Webhook::where('id', $webhookId)
            ->where('account_id', $user->account_id)
            ->first();

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook não encontrado',
            ], 404);
        }

        $validated = $request->validate([
            'url' => ['sometimes', 'url', 'max:500'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', self::AVAILABLE_EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $webhook->update($validated);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $webhook->id,
                'url' => $webhook->url,
                'events' => $webhook->events,
                'is_active' => $webhook->is_active,
                'secret' => $webhook->secret ? $this->maskSecret($webhook->secret) : null,
                'created_at' => $webhook->created_at?->toIso8601String(),
                'updated_at' => $webhook->updated_at?->toIso8601String(),
            ],
            'message' => 'Webhook atualizado com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserWebhookController::update error', [
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
     * Delete a webhook
     */
    public function destroy(string $webhookId): JsonResponse
    {
        try {
        $user = request()->user();

        $webhook = Webhook::where('id', $webhookId)
            ->where('account_id', $user->account_id)
            ->first();

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook não encontrado',
            ], 404);
        }

        $webhook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Webhook removido com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserWebhookController::destroy error', [
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
     * Regenerate webhook secret
     */
    public function regenerateSecret(string $webhookId): JsonResponse
    {
        try {
        $user = request()->user();

        $webhook = Webhook::where('id', $webhookId)
            ->where('account_id', $user->account_id)
            ->first();

        if (!$webhook) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook não encontrado',
            ], 404);
        }

        $newSecret = Str::random(32);
        $webhook->update(['secret' => $newSecret]);

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $newSecret, // Show full new secret
            ],
            'message' => 'Secret regenerado com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserWebhookController::regenerateSecret error', [
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
     * List webhook delivery logs for user's account
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user->account_id) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
                ]);
            }

            $webhookIds = Webhook::where('account_id', $user->account_id)
                ->pluck('id')
                ->toArray();

            $query = \Illuminate\Support\Facades\DB::table('webhook_attempts')
                ->join('webhooks', 'webhook_attempts.webhook_id', '=', 'webhooks.id')
                ->whereIn('webhook_attempts.webhook_id', $webhookIds)
                ->select([
                    'webhook_attempts.id',
                    'webhook_attempts.webhook_id',
                    'webhooks.url as webhook_url',
                    'webhook_attempts.event_type as event',
                    'webhook_attempts.payload',
                    'webhook_attempts.response_code as response_status',
                    'webhook_attempts.response_body',
                    'webhook_attempts.success',
                    'webhook_attempts.attempt_number as attempts',
                    'webhook_attempts.created_at',
                ]);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('webhooks.url', 'like', '%' . $search . '%')
                      ->orWhere('webhook_attempts.event_type', 'like', '%' . $search . '%');
                });
            }

            if ($status = $request->input('status')) {
                if ($status === 'success') {
                    $query->where('webhook_attempts.success', true);
                } elseif ($status === 'failed') {
                    $query->where('webhook_attempts.success', false);
                }
            }

            if ($event = $request->input('event')) {
                $query->where('webhook_attempts.event_type', $event);
            }

            $perPage = min((int) ($request->input('per_page', 20)), 100);
            $page = max((int) ($request->input('page', 1)), 1);

            $total = (clone $query)->count();
            $lastPage = max((int) ceil($total / $perPage), 1);

            $attempts = $query->orderBy('webhook_attempts.created_at', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(function ($attempt) {
                    return [
                        'id' => $attempt->id,
                        'webhook_id' => $attempt->webhook_id,
                        'webhook_url' => $attempt->webhook_url,
                        'event' => $attempt->event,
                        'payload' => json_decode($attempt->payload, true) ?: [],
                        'response_status' => $attempt->response_status,
                        'response_body' => $attempt->response_body,
                        'status' => $attempt->success ? 'success' : 'failed',
                        'attempts' => $attempt->attempts ?? 1,
                        'last_attempt_at' => $attempt->created_at,
                        'created_at' => $attempt->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $attempts,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'total' => $total,
                    'per_page' => $perPage,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserWebhookController::logs error', [
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
     * Retry a failed webhook delivery
     */
    public function retryAttempt(Request $request, string $attemptId): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user->account_id) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 400);
            }

            $webhookIds = Webhook::where('account_id', $user->account_id)
                ->pluck('id')
                ->toArray();

            $attempt = \Illuminate\Support\Facades\DB::table('webhook_attempts')
                ->whereIn('webhook_id', $webhookIds)
                ->where('id', $attemptId)
                ->first();

            if (!$attempt) {
                return response()->json(['success' => false, 'message' => 'Log não encontrado'], 404);
            }

            $webhook = Webhook::find($attempt->webhook_id);
            if (!$webhook || !$webhook->is_active) {
                return response()->json(['success' => false, 'message' => 'Webhook inativo ou não encontrado'], 422);
            }

            $payload = json_decode($attempt->payload, true) ?: [];

            // Dispatch using existing service
            $service = app(\App\Services\WebhookDispatchService::class);
            $service->send($webhook, $attempt->event_type, $payload);

            return response()->json([
                'success' => true,
                'message' => 'Webhook reenviado com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserWebhookController::retryAttempt error', [
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
     * Mask a secret for display (show only last 4 chars)
     */
    private function maskSecret(string $secret): string
    {
        if (strlen($secret) <= 4) {
            return '****';
        }
        return str_repeat('*', strlen($secret) - 4) . substr($secret, -4);
    }
}
