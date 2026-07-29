<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Endpoints de suporte para o admin.
 *
 * Vê e responde tickets de TODAS as contas. Também pode reatribuir, mudar
 * prioridade/categoria e marcar como resolvido/fechado.
 */
class SupportTicketAdminController extends Controller
{
    /**
     * GET /admin/support/tickets
     * Lista global com filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::with(['user:id,name,email', 'account:id,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($awaiting = $request->query('awaiting')) {
            $query->where('awaiting', $awaiting);
        }
        if ($search = $request->query('search')) {
            $safe = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($safe) {
                $q->where('subject', 'like', "%{$safe}%")
                  ->orWhereHas('user', function ($u) use ($safe) {
                      $u->where('name', 'like', "%{$safe}%")
                        ->orWhere('email', 'like', "%{$safe}%");
                  });
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $tickets = $query->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $tickets->items(),
            'meta'    => [
                'current_page' => $tickets->currentPage(),
                'per_page'     => $tickets->perPage(),
                'total'        => $tickets->total(),
                'last_page'    => $tickets->lastPage(),
            ],
        ]);
    }

    /**
     * GET /admin/support/tickets/{id}
     * Detalhes incluindo notas internas.
     */
    public function show(string $id): JsonResponse
    {
        $ticket = SupportTicket::with([
            'user:id,name,email',
            'account:id,name',
            'assignedAdmin:id,name,email',
            'messages',
            'messages.author:id,name',
        ])->find($id);

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ticket_not_found', 'message' => 'Chamado não encontrado.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $ticket,
        ]);
    }

    /**
     * POST /admin/support/tickets/{id}/messages
     * Admin responde (ou adiciona nota interna).
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'body'             => ['required', 'string', 'max:10000'],
            'is_internal_note' => ['nullable', 'boolean'],
        ]);

        $ticket = SupportTicket::find($id);
        if (!$ticket) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ticket_not_found', 'message' => 'Chamado não encontrado.'],
            ], 404);
        }

        $isInternal = (bool) ($validated['is_internal_note'] ?? false);
        $user = $request->user();

        $message = DB::transaction(function () use ($validated, $ticket, $user, $isInternal) {
            $msg = SupportTicketMessage::create([
                'id'                => (string) Str::uuid(),
                'ticket_id'         => $ticket->id,
                'user_id'           => $user->id,
                'author_type'       => 'admin',
                'body'              => $validated['body'],
                'is_internal_note'  => $isInternal,
            ]);

            // Notas internas não devolvem a bola para o vendedor.
            if (!$isInternal) {
                $ticket->update([
                    'status'            => $ticket->status === 'open' ? 'pending' : $ticket->status,
                    'awaiting'          => 'seller',
                    'assigned_admin_id' => $ticket->assigned_admin_id ?? $user->id,
                    'last_reply_at'     => now(),
                ]);
            }

            return $msg;
        });

        // In-app notification para o vendedor — só para respostas visíveis.
        if (!$isInternal) {
            try {
                app(\App\Services\AccountNotificationService::class)->supportReplied($ticket->fresh() ?? $ticket);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('In-app notification (support replied) failed', [
                    'ticket_id' => $ticket->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $message,
        ], 201);
    }

    /**
     * PATCH /admin/support/tickets/{id}
     * Atualiza status, prioridade, categoria ou atribuição.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status'            => ['nullable', 'in:open,pending,resolved,closed'],
            'priority'          => ['nullable', 'in:low,normal,high,urgent'],
            'category'          => ['nullable', 'in:financial,technical,acquirer,kyc,other'],
            'assigned_admin_id' => ['nullable', 'uuid'],
        ]);

        $ticket = SupportTicket::find($id);
        if (!$ticket) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ticket_not_found', 'message' => 'Chamado não encontrado.'],
            ], 404);
        }

        $updates = array_filter($validated, fn ($v) => $v !== null);

        if (isset($updates['status'])) {
            if ($updates['status'] === 'resolved') {
                $updates['resolved_at'] = now();
                $updates['awaiting'] = 'seller';
            } elseif ($updates['status'] === 'closed') {
                $updates['closed_at'] = now();
            }
        }

        $ticket->update($updates);

        return response()->json([
            'success' => true,
            'data'    => $ticket->fresh(),
        ]);
    }
}
