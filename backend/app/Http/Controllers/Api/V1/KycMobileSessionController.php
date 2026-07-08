<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class KycMobileSessionController extends Controller
{
    private const TTL_MINUTES = 120;

    public function create(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_user', 'message' => 'Autenticacao invalida'],
            ], 400);
        }

        $code = $this->generateCode();
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put($this->cacheKey($code), [
            'user_id' => $user->id,
            'account_id' => $user->account_id,
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $code,
                'expires_at' => $expiresAt->toIso8601String(),
                'url' => "{$frontendUrl}/kyc?mobile=1&kyc_code={$code}",
            ],
        ]);
    }

    public function show(string $code): JsonResponse
    {
        $user = $this->resolveUser($code);

        if (!$user) {
            return $this->invalidSession();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'code' => strtoupper($code),
                'user_name' => $user->name,
                'kyc_status' => $user->kyc_status ?? 'pending',
            ],
        ]);
    }

    public function submit(Request $request, string $code): JsonResponse
    {
        $user = $this->resolveUser($code);

        if (!$user) {
            return $this->invalidSession();
        }

        $request->setUserResolver(fn () => $user);

        return app(KycController::class)->submit($request);
    }

    public function uploadDocument(Request $request, string $code): JsonResponse
    {
        $user = $this->resolveUser($code);

        if (!$user) {
            return $this->invalidSession();
        }

        $request->setUserResolver(fn () => $user);

        return app(KycController::class)->uploadDocument($request);
    }

    public function submitForReview(Request $request, string $code): JsonResponse
    {
        $user = $this->resolveUser($code);

        if (!$user) {
            return $this->invalidSession();
        }

        $request->setUserResolver(fn () => $user);
        $response = app(KycController::class)->submitForReview($request);

        if ($response->getStatusCode() < 300) {
            Cache::forget($this->cacheKey($code));
        }

        return $response;
    }

    private function resolveUser(string $code): ?User
    {
        $session = Cache::get($this->cacheKey($code));

        if (!$session || empty($session['user_id'])) {
            return null;
        }

        return User::find($session['user_id']);
    }

    private function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Cache::has($this->cacheKey($code)));

        return $code;
    }

    private function cacheKey(string $code): string
    {
        return 'kyc_mobile_session:' . strtoupper($code);
    }

    private function invalidSession(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'invalid_kyc_session',
                'message' => 'Codigo de verificacao expirado ou invalido',
            ],
        ], 404);
    }
}
