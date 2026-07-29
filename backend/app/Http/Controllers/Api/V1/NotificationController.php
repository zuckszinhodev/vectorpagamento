<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    /**
     * List all notifications for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $userId = $request->user()->id;
        $accountId = $request->user()->account_id;
        $perPage = min($request->input('per_page', 20), 100);

        try {
            // Base: notificações do próprio usuário OU notificações de conta
            // (user_id null) da mesma account. Exclui as que este usuário ocultou.
            $baseFilter = function ($q) use ($userId, $accountId) {
                $q->where(function ($inner) use ($userId, $accountId) {
                    $inner->where('user_id', $userId)
                          ->orWhere(function ($qq) use ($accountId) {
                              $qq->whereNull('user_id')->where('account_id', $accountId);
                          });
                })
                ->where(function ($e) {
                    $e->whereNull('data->expires_at')
                      ->orWhere('data->expires_at', '>', now()->toIso8601String());
                })
                // Se este usuário ocultou a notificação, não devolve.
                ->whereNotIn('id', function ($sub) use ($userId) {
                    $sub->select('notification_id')
                        ->from('notification_reads')
                        ->where('user_id', $userId)
                        ->whereNotNull('deleted_at');
                });
            };

            $query = Notification::query()->where($baseFilter);

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Uma query só para saber quais o usuário já leu (dentre as da página).
            $ids = collect($paginated->items())->pluck('id')->all();
            $readIds = NotificationRead::where('user_id', $userId)
                ->whereIn('notification_id', $ids)
                ->whereNotNull('read_at')
                ->pluck('notification_id')
                ->flip();

            $isRead = function (Notification $n) use ($userId, $readIds): bool {
                // Notificação direcionada ao próprio usuário usa a coluna clássica.
                if ($n->user_id !== null && $n->user_id === $userId) {
                    return (bool) $n->read;
                }
                // Notificação de conta: lida se existir marca em notification_reads.
                return $readIds->has($n->id);
            };

            $items = collect($paginated->items());

            // Aplica filtro `read` no lado do PHP (é per-user, não dá para SQL simples).
            if ($request->filled('read')) {
                $wantRead = filter_var($request->input('read'), FILTER_VALIDATE_BOOLEAN);
                $items = $items->filter(fn (Notification $n) => $isRead($n) === $wantRead)->values();
            }

            $data = $items->map(function (Notification $n) use ($isRead) {
                return [
                    'id'         => $n->id,
                    'type'       => $n->type,
                    'title'      => $n->title,
                    'message'    => $n->message,
                    'data'       => $n->data,
                    'read'       => $isRead($n),
                    'time'       => $this->formatTime($n->created_at),
                    'created_at' => $n->created_at->toIso8601String(),
                ];
            });

            // Não-lidas: personal (read=false) + conta (sem marca em notification_reads.read_at).
            $unreadPersonal = Notification::query()
                ->where($baseFilter)
                ->where('user_id', $userId)
                ->where('read', false)
                ->count();

            $unreadAccount = Notification::query()
                ->where($baseFilter)
                ->whereNull('user_id')
                ->whereNotExists(function ($sub) use ($userId) {
                    $sub->select(DB::raw(1))
                        ->from('notification_reads')
                        ->whereColumn('notification_reads.notification_id', 'notifications.id')
                        ->where('notification_reads.user_id', $userId)
                        ->whereNotNull('read_at');
                })
                ->count();

            $unreadCount = $unreadPersonal + $unreadAccount;
            $notifications = $paginated;
        } catch (\Throwable $e) {
            Log::error('NotificationController::index error', ['message' => $e->getMessage()]);
            $data = collect();
            $notifications = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
            $unreadCount = 0;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
                'unread_count' => $unreadCount,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::index error', [
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
     * Get unread count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        try {
        $userId = $request->user()->id;
        $accountId = $request->user()->account_id;

        $notValidExpiry = function ($q) {
            $q->whereNull('data->expires_at')
              ->orWhere('data->expires_at', '>', now()->toIso8601String());
        };

        // Não ocultadas por este usuário.
        $notHidden = function ($q) use ($userId) {
            $q->whereNotIn('id', function ($sub) use ($userId) {
                $sub->select('notification_id')
                    ->from('notification_reads')
                    ->where('user_id', $userId)
                    ->whereNotNull('deleted_at');
            });
        };

        $personal = Notification::where('user_id', $userId)
            ->where('read', false)
            ->where($notValidExpiry)
            ->where($notHidden)
            ->count();

        $account = Notification::whereNull('user_id')
            ->where('account_id', $accountId)
            ->where($notValidExpiry)
            ->where($notHidden)
            ->whereNotExists(function ($sub) use ($userId) {
                $sub->select(DB::raw(1))
                    ->from('notification_reads')
                    ->whereColumn('notification_reads.notification_id', 'notifications.id')
                    ->where('notification_reads.user_id', $userId)
                    ->whereNotNull('read_at');
            })
            ->count();

        $count = $personal + $account;

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $count,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::unreadCount error', [
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
     * Mark a notification as read
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        try {
        $userId = $request->user()->id;
        $accountId = $request->user()->account_id;
        $notification = Notification::where(function ($q) use ($userId, $accountId) {
                $q->where('user_id', $userId)
                  ->orWhere(function ($qq) use ($accountId) {
                      $qq->whereNull('user_id')->where('account_id', $accountId);
                  });
            })
            ->where('id', $notificationId)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificação não encontrada',
            ], 404);
        }

        // Personal (para este user): marca na coluna clássica.
        // De conta (user_id null): grava um registro em notification_reads
        // para este usuário — sem afetar os outros usuários da conta.
        if ($notification->user_id === $userId) {
            $notification->markAsRead();
        } else {
            $this->markAccountNotificationAsRead($notification->id, $userId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notificação marcada como lida',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::markAsRead error', [
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
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
        $userId = $request->user()->id;
        $accountId = $request->user()->account_id;

        // Personal: marca coluna clássica de uma vez.
        Notification::where('user_id', $userId)
            ->where('read', false)
            ->update(['read' => true, 'read_at' => now()]);

        // De conta: cria registros em notification_reads para as que ainda
        // não têm marca deste usuário.
        $accountIds = Notification::whereNull('user_id')
            ->where('account_id', $accountId)
            ->whereNotExists(function ($sub) use ($userId) {
                $sub->select(DB::raw(1))
                    ->from('notification_reads')
                    ->whereColumn('notification_reads.notification_id', 'notifications.id')
                    ->where('notification_reads.user_id', $userId)
                    ->whereNotNull('read_at');
            })
            ->pluck('id');

        foreach ($accountIds as $nid) {
            $this->markAccountNotificationAsRead((string) $nid, $userId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Todas as notificações foram marcadas como lidas',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::markAllAsRead error', [
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
     * Delete a notification
     */
    public function destroy(Request $request, string $notificationId): JsonResponse
    {
        try {
        $userId = $request->user()->id;
        $accountId = $request->user()->account_id;
        $notification = Notification::where(function ($q) use ($userId, $accountId) {
                $q->where('user_id', $userId)
                  ->orWhere(function ($qq) use ($accountId) {
                      $qq->whereNull('user_id')->where('account_id', $accountId);
                  });
            })
            ->where('id', $notificationId)
            ->first();

        // Personal: apaga de verdade. De conta: apenas oculta para este usuário
        // (grava deleted_at em notification_reads sem tocar no registro global).
        if ($notification && $notification->user_id !== $userId) {
            $this->hideAccountNotification($notification->id, $userId);
            return response()->json(['success' => true, 'message' => 'Notificação removida da sua caixa.']);
        }

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificação não encontrada',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificação excluída',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::destroy error', [
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
     * Delete all read notifications
     */
    public function clearRead(Request $request): JsonResponse
    {
        try {
        Notification::where('user_id', $request->user()->id)
            ->where('read', true)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificações lidas foram excluídas',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::clearRead error', [
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
     * Format time for display
     */
    private function formatTime($createdAt): string
    {
        $now = now();
        $diff = $createdAt->diffInMinutes($now);

        if ($diff < 1) {
            return 'Agora mesmo';
        }

        if ($diff < 60) {
            return $diff . ' min atrás';
        }

        $hours = $createdAt->diffInHours($now);
        if ($hours < 24) {
            return $hours . 'h atrás';
        }

        $days = $createdAt->diffInDays($now);
        if ($days < 7) {
            return $days . 'd atrás';
        }

        return $createdAt->format('d/m/Y');
    }

    // ===================== ADMIN METHODS =====================

    /**
     * Admin: Send notification to one user or all users
     */
    public function adminSend(Request $request): JsonResponse
    {
        try {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:2000'],
            'type' => ['required', 'in:system,warning,info'],
            'target' => ['required', 'in:all,user'],
            'user_id' => ['required_if:target,user', 'nullable', 'uuid'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $count = 0;
        $expiresAt = isset($validated['duration_days'])
            ? now()->addDays($validated['duration_days'])->toIso8601String()
            : null;

        if ($validated['target'] === 'all') {
            $users = User::with('account')->whereNotNull('account_id')->get();

            foreach ($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'account_id' => $user->account_id,
                    'type' => $validated['type'],
                    'title' => $validated['title'],
                    'message' => $validated['message'],
                    'data' => [
                        'sent_by' => 'admin',
                        'admin_id' => $request->user()->id,
                        'expires_at' => $expiresAt,
                        'duration_days' => $validated['duration_days'] ?? null,
                    ],
                ]);
                $count++;
            }

            Log::info('Admin sent notification to all users', [
                'admin_id' => $request->user()->id,
                'title' => $validated['title'],
                'count' => $count,
            ]);
        } else {
            $user = User::find($validated['user_id']);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                ], 404);
            }

            Notification::create([
                'user_id' => $user->id,
                'account_id' => $user->account_id,
                'type' => $validated['type'],
                'title' => $validated['title'],
                'message' => $validated['message'],
                'data' => [
                    'sent_by' => 'admin',
                    'admin_id' => $request->user()->id,
                    'expires_at' => $expiresAt,
                    'duration_days' => $validated['duration_days'] ?? null,
                ],
            ]);
            $count = 1;

            Log::info('Admin sent notification to user', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
                'title' => $validated['title'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Notificação enviada para {$count} usuário(s)",
            'data' => ['count' => $count],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::adminSend error', [
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
     * Admin: List all sent notifications (history)
     */
    public function adminList(Request $request): JsonResponse
    {
        try {
        $perPage = min($request->input('per_page', 20), 100);

        $query = Notification::with(['user:id,name,email']);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = addcslashes($request->input('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('message', 'ilike', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'ilike', "%{$search}%")
                         ->orWhere('email', 'ilike', "%{$search}%");
                  });
            });
        }

        // Only show admin-sent notifications
        if ($request->input('admin_only') === 'true') {
            $query->whereJsonContains('data->sent_by', 'admin');
        }

        $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = collect($notifications->items())->map(function ($notification) {
            return [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'read' => $notification->read,
                'user_name' => $notification->user?->name ?? 'Desconhecido',
                'user_email' => $notification->user?->email ?? '',
                'time' => $this->formatTime($notification->created_at),
                'created_at' => $notification->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::adminList error', [
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
     * Admin: Search users for autocomplete
     */
    public function adminSearchUsers(Request $request): JsonResponse
    {
        try {
        $search = addcslashes($request->input('q', ''), '%_\\');

        $users = User::query()
            ->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            })
            ->select('id', 'name', 'email')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::adminSearchUsers error', [
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
     * Admin: Clear all admin-sent notifications
     */
    public function adminClear(Request $request): JsonResponse
    {
        try {
        $deleted = Notification::whereJsonContains('data->sent_by', 'admin')->delete();

        Log::info('Admin cleared all sent notifications', [
            'admin_id' => $request->user()->id,
            'deleted_count' => $deleted,
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$deleted} notificação(ões) removidas.",
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('NotificationController::adminClear error', [
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
     * Upsert seguro do estado "lida" de uma notificação de conta para um usuário.
     * Se já existe registro, só atualiza `read_at`; senão, cria novo.
     */
    private function markAccountNotificationAsRead(string $notificationId, string $userId): void
    {
        $read = NotificationRead::where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($read) {
            $read->update(['read_at' => now()]);
        } else {
            NotificationRead::create([
                'id'              => (string) Str::uuid(),
                'notification_id' => $notificationId,
                'user_id'         => $userId,
                'read_at'         => now(),
            ]);
        }
    }

    /**
     * Oculta uma notificação de conta para um usuário específico
     * (não afeta a visibilidade dela para os outros usuários da mesma account).
     */
    private function hideAccountNotification(string $notificationId, string $userId): void
    {
        $read = NotificationRead::where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($read) {
            $read->update(['deleted_at' => now()]);
        } else {
            NotificationRead::create([
                'id'              => (string) Str::uuid(),
                'notification_id' => $notificationId,
                'user_id'         => $userId,
                'deleted_at'      => now(),
            ]);
        }
    }
}
