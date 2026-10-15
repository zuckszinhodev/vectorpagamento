<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuyerSupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Abertura de chamado pela central de ajuda pública.
 *
 * Rota sem autenticação: quem abre é o comprador, que não tem conta. Por isso
 * há limite de taxa na rota, honeypot no formulário e nenhum campo que permita
 * ao cliente escolher status, protocolo ou destinatário.
 */
class BuyerSupportController extends Controller
{
    private const MAX_ATTACHMENTS = 3;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(BuyerSupportTicket::CATEGORIES))],
            'message' => ['required', 'string', 'min:15', 'max:4000'],
            'order_reference' => ['nullable', 'string', 'max:190'],
            // Anexos: rota publica, entao limites apertados em quantidade,
            // tamanho e tipo. Nada de PDF/SVG — SVG carrega script.
            'attachments' => ['nullable', 'array', 'max:' . self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // Campo invisível no formulário: se veio preenchido, é robô.
            'website' => ['nullable', 'string', 'max:0'],
        ], [
            'message.min' => 'Descreva o problema com um pouco mais de detalhe.',
            'attachments.max' => 'Envie no máximo ' . self::MAX_ATTACHMENTS . ' imagens.',
            'attachments.*.mimes' => 'Use imagens JPG, PNG ou WEBP.',
            'attachments.*.max' => 'Cada imagem deve ter no máximo 5MB.',
            'website.max' => 'Não foi possível enviar o formulário.',
        ]);

        try {
            // Nome do arquivo é sempre gerado por nós: nome vindo do cliente
            // pode carregar path traversal ou extensão dupla.
            $stored = [];
            foreach ($request->file('attachments', []) as $file) {
                $stored[] = $file->storeAs(
                    'support-attachments/' . now()->format('Y-m'),
                    Str::uuid()->toString() . '.' . $file->extension(),
                    'public',
                );
            }

            $ticket = BuyerSupportTicket::create([
                'protocol' => BuyerSupportTicket::generateProtocol(),
                'attachments' => $stored ?: null,
                'name' => $data['name'],
                'email' => $data['email'],
                'subject' => $data['subject'],
                'category' => $data['category'],
                'message' => $data['message'],
                'order_reference' => $data['order_reference'] ?? null,
                'status' => BuyerSupportTicket::STATUS_OPEN,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'protocol' => $ticket->protocol,
                    'message' => 'Chamado registrado. Guarde o protocolo para acompanhar.',
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('BuyerSupportController::store falhou', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Não consegui registrar seu chamado agora.'],
            ], 500);
        }
    }

    /**
     * Consulta pública por protocolo + e-mail.
     *
     * Exige os dois juntos de propósito: só o protocolo permitiria varrer
     * chamados alheios por tentativa e erro.
     */
    public function show(Request $request, string $protocol): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $ticket = BuyerSupportTicket::where('protocol', strtoupper($protocol))
            ->where('email', $request->string('email')->toString())
            ->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'not_found', 'message' => 'Chamado não encontrado com esse protocolo e e-mail.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'protocol' => $ticket->protocol,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'message' => $ticket->message,
                'admin_reply' => $ticket->admin_reply,
                'replied_at' => $ticket->replied_at?->toIso8601String(),
                'created_at' => $ticket->created_at?->toIso8601String(),
            ],
        ]);
    }
}
