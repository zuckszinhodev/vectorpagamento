<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Split;
use App\Models\SplitRule;
use App\Services\SplitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SplitConfigController extends Controller
{
    public function __construct(
        private SplitService $splitService,
    ) {}

    // ══════════════════════════════════════════════════════
    //  SPLIT CONFIG (legacy settings page)
    // ══════════════════════════════════════════════════════

    /**
     * Obter configuração de split da conta
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $accountId = $user->account_id ?? null;

            if (!$accountId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conta não encontrada',
                ], 404);
            }

            // Get split config from system_settings (legacy)
            $config = DB::table('system_settings')
                ->where('key', "split_config:{$accountId}")
                ->first();

            $legacyData = $config ? json_decode($config->value, true) : [
                'enabled' => false,
                'default_split_type' => 'percentage',
                'default_affiliate_commission' => 10,
                'auto_process' => true,
                'minimum_amount' => 100,
            ];

            // Merge with new rules-based config
            $rulesConfig = $this->splitService->getSplitConfig($accountId);

            return response()->json([
                'success' => true,
                'data' => array_merge($legacyData, $rulesConfig),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::show error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Atualizar configuração de split
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $accountId = $user->account_id ?? null;

            if (!$accountId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conta não encontrada',
                ], 404);
            }

            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
                'default_split_type' => ['required', 'in:percentage,fixed'],
                'default_affiliate_commission' => ['required', 'numeric', 'min:0', 'max:100'],
                'auto_process' => ['boolean'],
                'minimum_amount' => ['integer', 'min:0'],
            ]);

            DB::table('system_settings')->updateOrInsert(
                ['key' => "split_config:{$accountId}"],
                [
                    'value' => json_encode($validated),
                    'updated_at' => now(),
                ],
            );

            return response()->json([
                'success' => true,
                'data' => $validated,
                'message' => 'Configuração de split atualizada',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::update error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Listar splits de uma cobrança específica
     */
    public function chargesSplits(Request $request, string $chargeId): JsonResponse
    {
        try {
            $splits = $this->splitService->getSplitsForCharge($chargeId);

            return response()->json([
                'success' => true,
                'data' => $splits,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::chargesSplits error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    // ══════════════════════════════════════════════════════
    //  SPLIT RULES CRUD
    // ══════════════════════════════════════════════════════

    /**
     * Listar todas as regras de split da conta.
     */
    public function indexRules(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id ?? null;
            if (!$accountId) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
            }

            $rules = $this->splitService->listRules($accountId);

            return response()->json([
                'success' => true,
                'data' => $rules,
                'meta' => [
                    'total' => $rules->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::indexRules error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Criar nova regra de split automático.
     */
    public function storeRule(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id ?? null;
            if (!$accountId) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'product_id' => ['nullable', 'uuid'],
                'checkout_id' => ['nullable', 'uuid'],
                'is_default' => ['boolean'],
                'is_active' => ['boolean'],
                'priority' => ['integer', 'min:0', 'max:999'],
                'recipients' => ['required', 'array', 'min:1', 'max:10'],
                'recipients.*.recipient_id' => ['required', 'uuid'],
                'recipients.*.recipient_type' => ['required', 'in:account,affiliate'],
                'recipients.*.type' => ['required', 'in:percentage,fixed'],
                'recipients.*.value' => ['required', 'numeric', 'min:0'],
                'recipients.*.description' => ['nullable', 'string', 'max:255'],
                'charge_fee_to_producer' => ['boolean'],
                'minimum_amount' => ['integer', 'min:0'],
                'auto_process' => ['boolean'],
                'transfer_delay_hours' => ['integer', 'min:0', 'max:720'],
            ]);

            // Validate percentages sum
            $percentageRecipients = array_filter($validated['recipients'], fn($r) => $r['type'] === 'percentage');
            $totalPercentage = array_sum(array_column($percentageRecipients, 'value'));
            if ($totalPercentage > 100) {
                return response()->json([
                    'success' => false,
                    'message' => 'A soma das porcentagens não pode ultrapassar 100%',
                    'errors' => ['recipients' => ["Total: {$totalPercentage}%, máximo: 100%"]],
                ], 422);
            }

            // Destinatários precisam existir de fato — senão o valor seria creditado
            // numa carteira fantasma no momento do pagamento.
            $recipientErrors = $this->splitService->validateRecipients($validated['recipients']);
            if (!empty($recipientErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Destinatário inválido na regra de split',
                    'errors' => $recipientErrors,
                ], 422);
            }

            $rule = $this->splitService->createRule($accountId, $validated);

            return response()->json([
                'success' => true,
                'data' => $rule,
                'message' => 'Regra de split criada com sucesso',
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::storeRule error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Exibir uma regra de split específica.
     */
    public function showRule(Request $request, string $ruleId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id ?? null;
            if (!$accountId) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
            }

            $rule = $this->splitService->getRule($accountId, $ruleId);
            if (!$rule) {
                return response()->json(['success' => false, 'message' => 'Regra não encontrada'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $rule,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::showRule error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Atualizar uma regra de split.
     */
    public function updateRule(Request $request, string $ruleId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id ?? null;
            if (!$accountId) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
            }

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'product_id' => ['nullable', 'uuid'],
                'checkout_id' => ['nullable', 'uuid'],
                'is_default' => ['boolean'],
                'is_active' => ['boolean'],
                'priority' => ['integer', 'min:0', 'max:999'],
                'recipients' => ['sometimes', 'array', 'min:1', 'max:10'],
                'recipients.*.recipient_id' => ['required_with:recipients', 'uuid'],
                'recipients.*.recipient_type' => ['required_with:recipients', 'in:account,affiliate'],
                'recipients.*.type' => ['required_with:recipients', 'in:percentage,fixed'],
                'recipients.*.value' => ['required_with:recipients', 'numeric', 'min:0'],
                'recipients.*.description' => ['nullable', 'string', 'max:255'],
                'charge_fee_to_producer' => ['boolean'],
                'minimum_amount' => ['integer', 'min:0'],
                'auto_process' => ['boolean'],
                'transfer_delay_hours' => ['integer', 'min:0', 'max:720'],
            ]);

            // Validate percentages sum if recipients provided
            if (isset($validated['recipients'])) {
                $percentageRecipients = array_filter($validated['recipients'], fn($r) => $r['type'] === 'percentage');
                $totalPercentage = array_sum(array_column($percentageRecipients, 'value'));
                if ($totalPercentage > 100) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A soma das porcentagens não pode ultrapassar 100%',
                        'errors' => ['recipients' => ["Total: {$totalPercentage}%, máximo: 100%"]],
                    ], 422);
                }

                $recipientErrors = $this->splitService->validateRecipients($validated['recipients']);
                if (!empty($recipientErrors)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Destinatário inválido na regra de split',
                        'errors' => $recipientErrors,
                    ], 422);
                }
            }

            $rule = $this->splitService->updateRule($accountId, $ruleId, $validated);
            if (!$rule) {
                return response()->json(['success' => false, 'message' => 'Regra não encontrada'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $rule,
                'message' => 'Regra de split atualizada',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::updateRule error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Excluir uma regra de split.
     */
    public function destroyRule(Request $request, string $ruleId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id ?? null;
            if (!$accountId) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
            }

            $deleted = $this->splitService->deleteRule($accountId, $ruleId);
            if (!$deleted) {
                return response()->json(['success' => false, 'message' => 'Regra não encontrada'], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Regra de split excluída',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::destroyRule error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Simular um split com uma regra — preview sem criar registros.
     */
    public function simulateRule(Request $request, string $ruleId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id ?? null;
            if (!$accountId) {
                return response()->json(['success' => false, 'message' => 'Conta não encontrada'], 404);
            }

            $validated = $request->validate([
                'amount' => ['required', 'integer', 'min:100'],
                'fee_amount' => ['integer', 'min:0'],
            ]);

            $simulation = $this->splitService->simulateSplit(
                $ruleId,
                $accountId,
                $validated['amount'],
                $validated['fee_amount'] ?? 0,
            );

            if (isset($simulation['error'])) {
                return response()->json(['success' => false, 'message' => $simulation['error']], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $simulation,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::simulateRule error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Processar splits pendentes manualmente.
     */
    public function processPending(Request $request): JsonResponse
    {
        try {
            $results = $this->splitService->processPendingSplits();

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => "Processados: {$results['processed']}, Falhas: {$results['failed']}",
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('SplitConfigController::processPending error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }
}
