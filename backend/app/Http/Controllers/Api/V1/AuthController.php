<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Mail\WelcomeMail;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function __construct(
        private readonly AccountRegistrationService $registrationService,
    ) {
    }
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        try {
        $passwordRules = ['required', 'string', 'max:128', 'confirmed'];

        if (app()->environment('local')) {
            $passwordRules[] = 'min:8';
        } else {
            $passwordRules[] = 'min:10';
            $passwordRules[] = 'regex:/[a-z]/';
            $passwordRules[] = 'regex:/[A-Z]/';
            $passwordRules[] = 'regex:/[0-9]/';
            $passwordRules[] = 'regex:/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/';
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => $passwordRules,
        ], [
            'name.required' => 'O nome é obrigatório',
            'email.required' => 'O e-mail é obrigatório',
            'email.email' => 'E-mail inválido',
            'email.unique' => 'Este e-mail já está em uso',
            'password.required' => 'A senha é obrigatória',
            'password.min' => app()->environment('local')
                ? 'A senha deve ter no mínimo 8 caracteres'
                : 'A senha deve ter no mínimo 10 caracteres',
            'password.max' => 'A senha deve ter no máximo 128 caracteres',
            'password.confirmed' => 'A confirmação de senha não confere',
            'password.regex' => 'A senha deve conter letras maiúsculas, minúsculas, números e caracteres especiais',
        ]);

        // Clean up any orphaned accounts with same email (from previously deleted users)
        $orphanedAccount = Account::withTrashed()->where('email', $request->input('email'))->first();
        if ($orphanedAccount) {
            $hasUsers = User::withTrashed()->where('account_id', $orphanedAccount->id)->exists();
            if (!$hasUsers) {
                DB::table('audit_logs')->where('account_id', $orphanedAccount->id)->delete();
                DB::table('security_events')->where('account_id', $orphanedAccount->id)->delete();
                $orphanedAccount->forceDelete();
            } else {
                // Account exists and has users — email is genuinely taken
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'email_taken',
                        'message' => 'Este e-mail já está em uso',
                    ],
                ], 422);
            }
        }

        // Create account + user atomically
        try {
            $result = $this->registrationService->createAccount([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => $request->input('password'),
            ]);
            $account = $result['account'];
            $user = $result['user'];
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'registration_failed',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }

        // Generate JWT token
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'account_id' => $account->id,
                    'role' => $user->getRoleName(),
                    'permissions' => $user->permissions,
                    'kyc_status' => $user->kyc_status ?? 'pending',
                ],
                'token' => [
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl', 60) * 60,
                ],
            ],
        ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::register error', [
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
     * Google OAuth - verify ID token and login/register user
     */
    public function googleAuth(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'credential' => ['required', 'string'],
            ]);

            $idToken = $request->input('credential');

            // Verify the Google ID token via Google's tokeninfo endpoint.
            $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);
            $payload = $response->successful() ? $response->json() : null;

            if (!$payload || !isset($payload['sub'])) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'invalid_token', 'message' => 'Token Google inválido.'],
                ], 401);
            }

            $expectedClientId = (string) config('services.google.client_id');
            if ($expectedClientId === '' || ($payload['aud'] ?? null) !== $expectedClientId
                || !filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'invalid_token', 'message' => 'Token Google não pertence a este aplicativo.'],
                ], 401);
            }

            $email = $payload['email'] ?? null;
            $name = $payload['name'] ?? ($payload['given_name'] ?? 'Usuário');
            $googleId = $payload['sub'] ?? null;

            if (!$email || !$googleId) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'invalid_token', 'message' => 'Token Google não contém dados necessários.'],
                ], 401);
            }

            // Check if user already exists
            $user = User::where('email', $email)->first();

            if ($user) {
                Log::info('Google OAuth: Existing user login', [
                    'email' => $email,
                    'user_id' => $user->id,
                    'kyc_status' => $user->kyc_status,
                    'has_google_id' => !empty($user->google_id),
                ]);

                // Existing user — check if blocked
                if (in_array($user->status ?? null, ['blocked', 'suspended'])) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'account_blocked',
                            'message' => 'Sua conta foi bloqueada pela administração.',
                            'block_reason' => $user->block_reason,
                        ],
                    ], 403);
                }

                // Update google_id if not set
                if (!$user->google_id) {
                    $user->google_id = $googleId;
                    $user->save();
                    Log::info('Google OAuth: Updated google_id for existing user', ['user_id' => $user->id]);
                }
            } else {
                // New user — register via Google
                try {
                    $result = $this->registrationService->createAccount([
                        'name' => $name,
                        'email' => $email,
                        'password' => null,
                        'google_id' => $googleId,
                    ]);
                    $user = $result['user'];
                } catch (\RuntimeException $e) {
                    Log::error('Google auth registration failed', ['error' => $e->getMessage(), 'email' => $email]);
                    return response()->json([
                        'success' => false,
                        'error' => ['code' => 'registration_failed', 'message' => 'Falha ao criar conta. Tente novamente.'],
                    ], 500);
                }
            }

            if ($user->mfa_enabled) {
                return $this->issueMfaChallenge($user, $request);
            }

            // OAuth verifies the first factor; local MFA must complete before issuing access.
            $token = JWTAuth::fromUser($user);
            $account = $user->account;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'account_id' => $account->id,
                        'role' => $user->getRoleName(),
                        'permissions' => $user->permissions,
                        'kyc_status' => $user->kyc_status ?? 'pending',
                        'mfa_enabled' => (bool) $user->mfa_enabled,
                    ],
                    'token' => [
                        'access_token' => $token,
                        'token_type' => 'bearer',
                        'expires_in' => config('jwt.ttl', 60) * 60,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('AuthController::googleAuth error', [
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
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'trusted_device_token' => ['nullable', 'string'],
        ], [
            'email.required' => 'O e-mail é obrigatório',
            'email.email' => 'E-mail inválido',
            'password.required' => 'A senha é obrigatória',
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_credentials',
                    'message' => 'E-mail ou senha inválidos',
                ],
            ], 401);
        }

        // Verify password — support both argon2id (current) and bcrypt (legacy) hashes
        $passwordValid = false;
        try {
            $passwordValid = Hash::check($request->input('password'), $user->password);
        } catch (\Throwable $e) {
            // Hash driver mismatch (e.g. bcrypt hash with argon2id driver) — fallback to password_verify
            $passwordValid = password_verify($request->input('password'), $user->password);
        }

        if (!$passwordValid) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_credentials',
                    'message' => 'E-mail ou senha inválidos',
                ],
            ], 401);
        }

        // Auto-rehash to Argon2id if password was stored with bcrypt (transparent migration)
        if (Hash::needsRehash($user->password)) {
            $user->forceFill([
                'password' => Hash::make($request->input('password')),
            ])->save();
        }

        // Check if user is blocked or suspended
        if (in_array($user->status, ['blocked', 'suspended'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'account_blocked',
                    'message' => 'Sua conta foi bloqueada pela administração. Para revisar seu bloqueio, entre em contato com o suporte.',
                    'block_reason' => $user->block_reason,
                ],
            ], 403);
        }

        if ($user->mfa_enabled && !$this->hasTrustedDevice($user, $request, (string) $request->input('trusted_device_token', ''))) {
            return $this->issueMfaChallenge($user, $request);
        }

        // Update last login
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->getClientIp($request),
        ])->save();

        // Generate JWT token
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'account_id' => $user->account_id,
                    'role' => $user->getRoleName(),
                    'permissions' => $user->permissions,
                    'kyc_status' => $user->kyc_status ?? 'pending',
                    'mfa_enabled' => (bool) $user->mfa_enabled,
                ],
                'token' => [
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl', 60) * 60,
                ],
            ],
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::login error', [
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
     * Verify MFA code
     */
    public function verifyMfa(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'remember_device' => ['sometimes', 'boolean'],
        ]);

        // Serialize attempts and consumption of the same challenge across workers.
        $lock = Cache::lock('mfa_verify:' . hash('sha256', $request->input('mfa_token')), 30);
        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'mfa_verification_busy', 'message' => 'Verificação em andamento. Tente novamente.'],
            ], 429);
        }

        try {
            return DB::transaction(fn () => $this->verifyMfaChallenge($request));
        } finally {
            $lock->release();
        }
    }

    protected function verifyMfaChallenge(Request $request): JsonResponse
    {
        try {
        $mfaToken = (string) $request->input('mfa_token');
        $code = preg_replace('/\D/', '', (string) $request->input('code', ''));
        $rememberDevice = filter_var($request->input('remember_device', false), FILTER_VALIDATE_BOOLEAN);

        $mfaData = cache()->get('mfa_token:' . $mfaToken);

        // Support both new format (array with IP binding) and legacy (just user_id)
        if (is_array($mfaData)) {
            $userId = $mfaData['user_id'];
            $boundIp = $mfaData['ip'] ?? null;
            $boundUaHash = isset($mfaData['ua_hash']) && is_string($mfaData['ua_hash'])
                ? $mfaData['ua_hash']
                : null;
            $currentUaHash = $this->buildUserAgentHash($request);
            $requestIp = $this->getClientIp($request);

            // Verify MFA token is used from the same IP that initiated login
            if ($boundIp && $boundIp !== $requestIp) {
                $sameUserAgent = $boundUaHash !== null && hash_equals($boundUaHash, $currentUaHash);

                if (!$sameUserAgent) {
                    Log::warning('MFA token used from different client fingerprint', [
                        'bound_ip' => $boundIp,
                        'request_ip' => $requestIp,
                    ]);
                    cache()->forget('mfa_token:' . $mfaToken);
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'mfa_ip_mismatch',
                            'message' => 'Sessão MFA inválida. Faça login novamente.',
                        ],
                    ], 401);
                }

                Log::notice('MFA token IP changed but user-agent fingerprint matched; allowing verification', [
                    'bound_ip' => $boundIp,
                    'request_ip' => $requestIp,
                ]);
            }
        } else {
            $userId = $mfaData;
        }

        if (!$userId) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_mfa_token',
                    'message' => 'Token MFA inválido ou expirado',
                ],
            ], 401);
        }

        // MFA brute-force protection: max 5 attempts per token
        $attemptKey = 'mfa_attempts:' . $mfaToken;
        $attempts = (int) cache()->get($attemptKey, 0);
        if ($attempts >= 5) {
            cache()->forget('mfa_token:' . $mfaToken);
            cache()->forget($attemptKey);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'mfa_attempts_exceeded',
                    'message' => 'Número máximo de tentativas MFA excedido. Faça login novamente.',
                ],
            ], 429);
        }

        $user = User::whereKey($userId)->lockForUpdate()->first();

        // Recheck after the database lock in case another request consumed the
        // challenge while this worker was waiting (including cache lock expiry).
        if (Cache::get('mfa_token:' . $mfaToken) !== $mfaData) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_mfa_token', 'message' => 'Token MFA inválido ou expirado'],
            ], 401);
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'Usuário não encontrado',
                ],
            ], 404);
        }

        if (in_array($user->status, ['blocked', 'suspended'], true)) {
            Cache::forget('mfa_token:' . $mfaToken);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'account_blocked', 'message' => 'Sua conta está bloqueada.'],
            ], 403);
        }

        // Revoke pending challenges after password/MFA changes, including legacy challenges.
        if (!$user->mfa_enabled || !is_array($mfaData)
            || !hash_equals($this->authenticationState($user), (string) ($mfaData['auth_state'] ?? ''))) {
            Cache::forget('mfa_token:' . $mfaToken);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_mfa_token', 'message' => 'Sessão MFA inválida. Faça login novamente.'],
            ], 401);
        }

        // Validate MFA code format
        if (!$code || !preg_match('/^\d{6}$/', $code)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_mfa_code',
                    'message' => 'Código MFA inválido. Informe 6 dígitos.',
                ],
            ], 422);
        }

        // Verify TOTP code against user's secret
        if (!$user->mfa_secret) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'mfa_misconfigured',
                    'message' => 'MFA não configurado corretamente. Reconfigure o MFA.',
                ],
            ], 500);
        }

        $valid = $this->consumeMfaCode($user, $code);
        if (!$valid) {
            cache()->put($attemptKey, $attempts + 1, 300);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_mfa_code',
                    'message' => 'Código MFA incorreto',
                ],
            ], 401);
        }

        cache()->forget('mfa_token:' . $mfaToken);
        cache()->forget($attemptKey);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $this->getClientIp($request),
        ])->save();

        $token = JWTAuth::fromUser($user);
        $trustedDeviceToken = $rememberDevice ? $this->issueTrustedDeviceToken($user, $request) : null;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'account_id' => $user->account_id,
                    'role' => $user->getRoleName(),
                    'permissions' => $user->permissions,
                    'kyc_status' => $user->kyc_status ?? 'pending',
                    'mfa_enabled' => (bool) $user->mfa_enabled,
                ],
                'token' => [
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl', 60) * 60,
                ],
                'trusted_device_token' => $trustedDeviceToken,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::verifyMfa error', [
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
     * Get current user
     */
    public function me(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        // API Key authentication returns a stdClass mock user
        if (($user->type ?? null) === 'api_key') {
            $apiKey = $user->api_key ?? null;
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name ?? 'API Key',
                    'email' => $user->email,
                    'account_id' => $user->account_id,
                    'auth_type' => 'api_key',
                    'key_type' => $user->key_type ?? 'secret',
                    'key_name' => $apiKey->name ?? null,
                    'key_prefix' => $apiKey->key_prefix ?? null,
                    'permissions' => $user->permissions ?? ['*'],
                    'kyc_status' => $user->kyc_status ?? 'approved',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'account_id' => $user->account_id,
                'role' => $user->getRoleName(),
                'permissions' => $user->permissions,
                'kyc_status' => $user->kyc_status ?? 'pending',
                'mfa_enabled' => $user->mfa_enabled,
                'email_verified_at' => $user->email_verified_at?->format('c'),
                'created_at' => $user->created_at->format('c'),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::me error', [
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
     * Check MFA code when already logged in (for admin access)
     */
    public function checkMfa(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->mfa_enabled || !$user->mfa_secret) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'mfa_not_enabled', 'message' => 'MFA não está habilitado para o usuário.'],
                ], 403);
            }

            $request->validate([
                'code' => ['required', 'digits:6'],
            ], [
                'code.required' => 'O código MFA é obrigatório.',
                'code.digits' => 'O código MFA deve ter 6 dígitos.',
            ]);

            $code = $request->input('code');
            if (!$this->consumeMfaCode($user, (string) $code)) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'invalid_mfa_code', 'message' => 'Código MFA incorreto.'],
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => ['message' => 'MFA verificada com sucesso.'],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::checkMfa error', [
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
     * Refresh token — invalidates old token before issuing new one
     * to prevent token accumulation attacks.
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            // Get the current user BEFORE invalidating the old token
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'token_refresh_failed', 'message' => 'Não foi possível renovar o token'],
                ], 401);
            }

            // Check if account is still active
            if (in_array($user->status, ['blocked', 'suspended'])) {
                try { JWTAuth::invalidate(JWTAuth::getToken()); } catch (\Exception $e) {}
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'account_blocked', 'message' => 'Sua conta foi bloqueada.'],
                ], 403);
            }

            // Invalidate the OLD token first (prevents token accumulation)
            try {
                JWTAuth::invalidate(JWTAuth::getToken());
            } catch (\Exception $e) {
                // If invalidation fails, still proceed with new token
                Log::warning('Failed to invalidate old token during refresh', ['user_id' => $user->id]);
            }

            // Issue a completely NEW token from the user (not just refresh)
            $newToken = JWTAuth::fromUser($user);

            return response()->json([
                'success' => true,
                'data' => [
                    'token' => [
                        'access_token' => $newToken,
                        'token_type' => 'bearer',
                        'expires_in' => config('jwt.ttl', 60) * 60,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'token_refresh_failed',
                    'message' => 'Não foi possível renovar o token',
                ],
            ], 401);
        }
    }

    /**
     * Logout
     */
    public function logout(): JsonResponse
    {
        try {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Exception $e) {
            // Ignore token errors on logout
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Logout realizado com sucesso',
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::logout error', [
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
     * Request password reset
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if ($user) {
            try {
                $token = Str::random(64);

                // Try file cache first, fallback to DB if Redis fails
                try {
                    cache()->put('password_reset:' . $token, $user->id, 3600);
                } catch (\Exception $cacheException) {
                    Log::error('Cache failed for password reset, using DB fallback', [
                        'error' => $cacheException->getMessage(),
                    ]);
                    // Fallback: store hashed token in database
                    DB::table('password_resets')->updateOrInsert(
                        ['email' => $user->email],
                        ['token' => hash('sha256', $token), 'created_at' => now()]
                    );
                }

                // Send password reset email
                Mail::to($user->email)->send(new PasswordResetMail($user, $token));
            } catch (\Exception $e) {
                Log::warning('Failed to send password reset email', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Se o e-mail existir em nossa base, você receberá instruções para redefinir sua senha',
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::forgotPassword error', [
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
     * Reset password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/'],
        ]);

        $token = $request->input('token');

        // Try cache first
        $userId = null;
        try {
            $userId = cache()->get('password_reset:' . $token);
        } catch (\Exception $e) {
            Log::error('Cache read failed for password reset', ['error' => $e->getMessage()]);
        }

        // Fallback: check DB table (token stored as hash)
        if (!$userId) {
            $record = DB::table('password_resets')->where('token', hash('sha256', $token))->first();
            if ($record) {
                // Check if token is not expired (1 hour)
                if (now()->diffInSeconds($record->created_at) <= 3600) {
                    $user = User::where('email', $record->email)->first();
                    $userId = $user?->id;
                }
            }
        }

        if (!$userId) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_token',
                    'message' => 'Token inválido ou expirado',
                ],
            ], 400);
        }

        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'Usuário não encontrado',
                ],
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        // Clean up token from both cache and DB
        try {
            cache()->forget('password_reset:' . $token);
        } catch (\Exception $e) {
            // Ignore cache errors
        }
        DB::table('password_resets')->where('token', hash('sha256', $token))->delete();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Senha alterada com sucesso',
            ],
        ]);        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::resetPassword error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }    }

    // ── Email Verification ─────────────────────────────────────

    /**
     * Send (or resend) email verification link
     */
    public function sendEmailVerification(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'E-mail já verificado',
            ], 422);
        }

        $token = Str::random(64);
        cache()->put('email_verify:' . $token, $user->id, now()->addHours(24));

        try {
            Mail::to($user->email)->send(new EmailVerificationMail($user, $token));
        } catch (\Exception $e) {
            Log::error('Failed to send verification email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar e-mail de verificação',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => ['message' => 'E-mail de verificação enviado'],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::sendEmailVerification error', [
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
     * Verify email with token
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        try {
        $request->validate(['token' => ['required', 'string']]);

        $token = $request->input('token');
        $userId = cache()->get('email_verify:' . $token);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_token',
                    'message' => 'Token inválido ou expirado',
                ],
            ], 400);
        }

        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não encontrado',
            ], 404);
        }

        if ($user->email_verified_at) {
            cache()->forget('email_verify:' . $token);
            return response()->json([
                'success' => true,
                'data' => ['message' => 'E-mail já verificado anteriormente'],
            ]);
        }

        $user->update(['email_verified_at' => now()]);
        cache()->forget('email_verify:' . $token);

        return response()->json([
            'success' => true,
            'data' => ['message' => 'E-mail verificado com sucesso'],
        ]);        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::verifyEmail error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }    }

    // ── Change Password ────────────────────────────────────────

    /**
     * Change the authenticated user's password
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'current_password' => 'required|string',
                'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[!@#$%^&*()_+\\-=\\[\\]{};\':"\\\\|,.<>\\/?]/'],
            ]);

            $user = $request->user();

            // Support both argon2id and bcrypt (legacy) hashes
            $currentPasswordValid = false;
            try {
                $currentPasswordValid = Hash::check($request->input('current_password'), $user->password);
            } catch (\Throwable $e) {
                $currentPasswordValid = password_verify($request->input('current_password'), $user->password);
            }

            if (!$currentPasswordValid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Senha atual incorreta',
                ], 422);
            }

            $user->forceFill([
                'password' => Hash::make($request->input('password')),
            ])->save();

            return response()->json([
                'success' => true,
                'data' => ['message' => 'Senha alterada com sucesso'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('AuthController::changePassword error', [
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

    // ── MFA Enable / Disable ───────────────────────────────────

    /**
     * Enable MFA — generates secret and returns QR code URL
     */
    public function enableMfa(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        if ($user->mfa_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'MFA já está ativado',
            ], 422);
        }

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $secret = $google2fa->generateSecretKey();

        // Store temporarily until user confirms with a valid code
        cache()->put("mfa_setup:{$user->id}", $secret, 600);

        // O emissor é o nome que aparece no app autenticador: vem do branding
        // salvo no banco, não do APP_NAME fixo do .env.
        $issuer = $this->platformName();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            $issuer,
            $user->email,
            $secret,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $secret,
                'qr_code_url' => $qrCodeUrl,
                'issuer' => $issuer,
                'message' => 'Escaneie o QR code e confirme com o código gerado',
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::enableMfa error', [
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
     * Confirm MFA setup with a valid TOTP code
     */
    public function confirmMfa(Request $request): JsonResponse
    {
        try {
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        $user = $request->user();
        $secret = cache()->get("mfa_setup:{$user->id}");

        if (!$secret) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma configuração de MFA pendente. Inicie o processo novamente.',
            ], 400);
        }

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $valid = $google2fa->verifyKey($secret, $request->input('code'), 1);

        if (!$valid) {
            return response()->json([
                'success' => false,
                'message' => 'Código MFA inválido',
            ], 422);
        }

        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
        ])->save();

        cache()->forget("mfa_setup:{$user->id}");

        return response()->json([
            'success' => true,
            'data' => ['message' => 'MFA ativado com sucesso'],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::confirmMfa error', [
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
     * Disable MFA (requires current code)
     */
    public function disableMfa(Request $request): JsonResponse
    {
        try {
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        $user = $request->user();

        if (!$user->mfa_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'MFA não está ativado',
            ], 422);
        }

        $valid = $this->consumeMfaCode($user, (string) $request->input('code'));

        if (!$valid) {
            return response()->json([
                'success' => false,
                'message' => 'Código MFA inválido',
            ], 422);
        }

        $user->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'data' => ['message' => 'MFA desativado com sucesso'],
        ]);        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AuthController::disableMfa error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Nome da plataforma configurado no branding (system_settings).
     * Cai no APP_NAME apenas quando o banco não tem valor.
     */
    protected function platformName(): string
    {
        try {
            $name = trim((string) \App\Models\SystemSetting::get('platform_name', ''));
        } catch (\Throwable) {
            $name = '';
        }

        return $name !== '' ? $name : (string) config('app.name', 'Vector Pagamentos');
    }

    protected function getClientIp(Request $request): string
    {
        // Use Laravel's request->ip() which respects TrustProxies middleware
        return $request->ip();
    }

    protected function buildUserAgentHash(Request $request): string
    {
        return hash('sha256', (string) ($request->userAgent() ?? ''));
    }

    protected function issueMfaChallenge(User $user, Request $request): JsonResponse
    {
        $token = Str::random(64);
        Cache::put('mfa_token:' . $token, [
            'user_id' => $user->id,
            'ip' => $this->getClientIp($request),
            'ua_hash' => $this->buildUserAgentHash($request),
            'auth_state' => $this->authenticationState($user),
        ], 300);

        return response()->json([
            'success' => true,
            'data' => ['requires_mfa' => true, 'mfa_token' => $token],
        ]);
    }

    protected function authenticationState(User $user): string
    {
        // HMAC avoids exposing password hashes or MFA secrets in signed device payloads.
        return hash_hmac('sha256', json_encode([
            $user->id, $user->password, $user->mfa_secret, (bool) $user->mfa_enabled,
        ]), (string) config('app.key'));
    }

    protected function consumeMfaCode(User $user, string $code): bool
    {
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        if (!$google2fa->verifyKey($user->mfa_secret, $code, 1)) {
            return false;
        }

        // Atomic add prevents two challenges/workers from accepting the same OTP.
        // 120 seconds exceeds the full accepted interval for a 30s TOTP with window 1.
        $key = 'mfa_used:' . hash_hmac('sha256', $user->id . ':' . $user->mfa_secret . ':' . $code, (string) config('app.key'));
        return Cache::add($key, true, 120);
    }

    protected function hasTrustedDevice(User $user, Request $request, string $trustedDeviceToken): bool
    {
        if ($trustedDeviceToken === '') {
            return false;
        }

        $payload = $this->parseTrustedDeviceToken($trustedDeviceToken);
        if (!$payload) {
            return false;
        }

        if (($payload['user_id'] ?? null) !== $user->id) {
            return false;
        }

        if (!hash_equals($this->authenticationState($user), (string) ($payload['auth_state'] ?? ''))) {
            return false;
        }

        if (($payload['ua_hash'] ?? null) !== $this->buildUserAgentHash($request)) {
            return false;
        }

        $deviceKey = $this->trustedDeviceCacheKey($user->id, (string) ($payload['device_id'] ?? ''));

        return Cache::get($deviceKey) === hash('sha256', $trustedDeviceToken);
    }

    protected function issueTrustedDeviceToken(User $user, Request $request): string
    {
        $payload = [
            'user_id' => $user->id,
            'auth_state' => $this->authenticationState($user),
            'device_id' => (string) Str::uuid(),
            'ua_hash' => $this->buildUserAgentHash($request),
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addDays(30)->timestamp,
        ];

        $encodedPayload = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encodedPayload, (string) config('app.key'));
        $token = $encodedPayload . '.' . $signature;

        Cache::put(
            $this->trustedDeviceCacheKey($user->id, $payload['device_id']),
            hash('sha256', $token),
            now()->addDays(30)
        );

        return $token;
    }

    protected function parseTrustedDeviceToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $encodedPayload, (string) config('app.key'));
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payload = json_decode(base64_decode($encodedPayload, true) ?: '', true);
        if (!is_array($payload)) {
            return null;
        }

        if ((int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        return $payload;
    }

    protected function trustedDeviceCacheKey(string $userId, string $deviceId): string
    {
        return 'trusted_device:' . $userId . ':' . $deviceId;
    }
}
