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
 * Endpoints de suporte para o vendedor.
 *
 * Todos os tickets são escopados pela conta autenticada — o vendedor só vê
 * e edita tickets da própria account.
 */
class SupportTicketController extends Controller
{
    /**
     * GET /support/tickets
     * Lista tickets da conta com paginação simples.
     */
    public function index(Request $request): JsonResponse
    {
        $accountId = $request->user()->account_id;

        $query = SupportTicket::where('account_id', $accountId);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $tickets = $query->orderByDesc('last_reply_at')
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
     * POST /support/tickets
     * Cria um novo ticket com a primeira mensagem.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject'  => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'in:financial,technical,acquirer,kyc,other'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'body'     => ['required', 'string', 'max:10000'],
        ]);

        $user = $request->user();

        $ticket = DB::transaction(function () use ($validated, $user) {
            $ticket = SupportTicket::create([
                'id'            => (string) Str::uuid(),
                'account_id'    => $user->account_id,
                'user_id'       => $user->id,
                'subject'       => $validated['subject'],
                'category'      => $validated['category'] ?? 'other',
                'priority'      => $validated['priority'] ?? 'normal',
                'status'        => 'open',
                'awaiting'      => 'admin',
                'last_reply_at' => now(),
            ]);

            SupportTicketMessage::create([
                'id'          => (string) Str::uuid(),
                'ticket_id'   => $ticket->id,
                'user_id'     => $user->id,
                'author_type' => 'seller',
                'body'        => $validated['body'],
            ]);

            return $ticket;
        });

        return response()->json([
            'success' => true,
            'data'    => $ticket->fresh(),
        ], 201);
    }

    /**
     * GET /support/tickets/{id}
     * Detalhes com thread completa (ignora notas internas).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $ticket = SupportTicket::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->with(['messages' => function ($q) {
                $q->where('is_internal_note', false);
            }])
            ->first();

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
     * POST /support/tickets/{id}/messages
     * Vendedor adiciona resposta ao ticket.
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $user = $request->user();
        $ticket = SupportTicket::where('account_id', $user->account_id)
            ->where('id', $id)
            ->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ticket_not_found', 'message' => 'Chamado não encontrado.'],
            ], 404);
        }

        if ($ticket->status === 'closed') {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ticket_closed', 'message' => 'Este chamado está fechado.'],
            ], 422);
        }

        $message = DB::transaction(function () use ($validated, $ticket, $user) {
            $msg = SupportTicketMessage::create([
                'id'          => (string) Str::uuid(),
                'ticket_id'   => $ticket->id,
                'user_id'     => $user->id,
                'author_type' => 'seller',
                'body'        => $validated['body'],
            ]);

            $ticket->update([
                // Se estava "resolved", vendedor respondendo → reabre.
                'status'        => $ticket->status === 'resolved' ? 'open' : $ticket->status,
                'awaiting'      => 'admin',
                'last_reply_at' => now(),
            ]);

            return $msg;
        });

        return response()->json([
            'success' => true,
            'data'    => $message,
        ], 201);
    }

    /**
     * PATCH /support/tickets/{id}/close
     * Vendedor pode encerrar próprio ticket.
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $ticket = SupportTicket::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ticket_not_found', 'message' => 'Chamado não encontrado.'],
            ], 404);
        }

        $ticket->update([
            'status'    => 'closed',
            'closed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $ticket->fresh(),
        ]);
    }
}
