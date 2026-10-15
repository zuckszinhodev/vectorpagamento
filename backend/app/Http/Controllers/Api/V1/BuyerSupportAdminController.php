<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuyerSupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Visão administrativa dos chamados abertos por compradores.
 */
class BuyerSupportAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BuyerSupportTicket::query()
            ->status($request->query('status'))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('protocol', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $tickets = $query->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'success' => true,
            'data' => collect($tickets->items())->map(fn ($t) => $this->format($t)),
            'meta' => [
                'total' => $tickets->total(),
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
            ],
            'counts' => [
                'open' => BuyerSupportTicket::where('status', 'open')->count(),
                'pending' => BuyerSupportTicket::where('status', 'pending')->count(),
                'resolved' => BuyerSupportTicket::where('status', 'resolved')->count(),
                'closed' => BuyerSupportTicket::where('status', 'closed')->count(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $ticket = BuyerSupportTicket::findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->format($ticket, true)]);
    }

    /** Responde e/ou muda o status do chamado. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:open,pending,resolved,closed'],
            'admin_reply' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        $ticket = BuyerSupportTicket::findOrFail($id);

        if (array_key_exists('admin_reply', $data) && filled($data['admin_reply'])) {
            $data['replied_by'] = $request->user()->id;
            $data['replied_at'] = now();
            // Respondeu sem dizer o contrário: sai de "aberto" para "aguardando".
            $data['status'] = $data['status'] ?? BuyerSupportTicket::STATUS_PENDING;
        }

        $ticket->update($data);

        return response()->json(['success' => true, 'data' => $this->format($ticket->fresh(), true)]);
    }

    private function format(BuyerSupportTicket $ticket, bool $full = false): array
    {
        $data = [
            'id' => $ticket->id,
            'protocol' => $ticket->protocol,
            'name' => $ticket->name,
            'email' => $ticket->email,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'category_label' => BuyerSupportTicket::CATEGORIES[$ticket->category] ?? $ticket->category,
            'status' => $ticket->status,
            'order_reference' => $ticket->order_reference,
            // Caminho relativo vira URL completa só na saída; assim mudar de
            // domínio não invalida o que está gravado.
            'attachments' => collect($ticket->attachments ?? [])
                ->map(fn ($path) => Storage::disk('public')->url($path))
                ->values()
                ->all(),
            'admin_reply' => $ticket->admin_reply,
            'replied_at' => $ticket->replied_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];

        $data['message'] = $ticket->message;

        if ($full) {
            // Só no detalhe: dado de triagem de abuso não precisa ir na listagem.
            $data['ip_address'] = $ticket->ip_address;
            $data['user_agent'] = $ticket->user_agent;
        }

        return $data;
    }
}
