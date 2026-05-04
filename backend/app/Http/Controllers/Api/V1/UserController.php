<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * List users for authenticated account
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $query = User::where('account_id', $accountId);

        if ($request->query('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->query('search')) {
            $search = addcslashes($request->query('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserController::index error', [
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
     * Create a new user
     */
    public function store(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:admin,manager,operator,viewer'],
        ]);

        $accountId = $request->user()->account_id;

        $user = User::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $accountId,
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role' => $request->input('role'),
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
            ],
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserController::store error', [
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
     * Show a specific user
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $user = User::where('id', $userId)
            ->where('account_id', $accountId)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'Usuário não encontrado',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserController::show error', [
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
     * Update a user
     */
    public function update(Request $request, string $userId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $user = User::where('id', $userId)
            ->where('account_id', $accountId)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'Usuário não encontrado',
                ],
            ], 404);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $userId],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', 'in:admin,manager,operator,viewer'],
            'is_active' => ['boolean'],
        ]);

        $updateData = $request->only(['name', 'email', 'role', 'is_active']);

        if ($request->has('password')) {
            $updateData['password'] = Hash::make($request->input('password'));
        }

        $user->update(array_filter($updateData, fn($v) => $v !== null));

        return response()->json([
            'success' => true,
            'data' => $user->fresh(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserController::update error', [
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
     * Delete a user
     */
    public function destroy(Request $request, string $userId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;
        $currentUser = $request->user();

        // Prevent self-deletion
        if ($userId === $currentUser->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'cannot_delete_self',
                    'message' => 'Você não pode excluir sua própria conta',
                ],
            ], 400);
        }

        $user = User::where('id', $userId)
            ->where('account_id', $accountId)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'Usuário não encontrado',
                ],
            ], 404);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'data' => ['message' => 'Usuário removido com sucesso'],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UserController::destroy error', [
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
}
