<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MemberEnrollmentController extends Controller
{
    /**
     * List enrollments for account
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            $accountId = $user->account_id;

            if (!$accountId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conta não encontrada',
                ], 404);
            }

            $query = Enrollment::whereHas('course', function ($q) use ($accountId) {
                $q->where('account_id', $accountId);
            })->with(['user:id,name,email', 'course:id,name,thumbnail_url']);

            if ($request->query('course_id')) {
                $query->where('course_id', $request->query('course_id'));
            }

            if ($request->query('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->query('search')) {
                $search = addcslashes($request->query('search'), '%_\\');
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $perPage = min((int) $request->query('per_page', 20), 100);
            $enrollments = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $enrollments->items(),
                'meta' => [
                    'current_page' => $enrollments->currentPage(),
                    'per_page' => $enrollments->perPage(),
                    'total' => $enrollments->total(),
                    'last_page' => $enrollments->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollment index error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar matrículas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create manual enrollment
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $validated = $request->validate([
                'course_id' => 'required|string',
                'user_email' => 'required|email',
                'user_name' => 'sometimes|string|max:255',
                'access_type' => 'sometimes|in:lifetime,subscription,limited',
                'expires_at' => 'sometimes|nullable|date|after:now',
            ], [
                'course_id.required' => 'Selecione um curso para a matrícula.',
                'course_id.string' => 'O ID do curso deve ser uma string válida.',
                'user_email.required' => 'Informe o e-mail do aluno.',
                'user_email.email' => 'O e-mail informado não é válido.',
                'user_name.string' => 'O nome do aluno deve ser um texto válido.',
                'user_name.max' => 'O nome do aluno não pode exceder 255 caracteres.',
                'access_type.in' => 'O tipo de acesso deve ser: Vitalício, Assinatura ou Limitado.',
                'expires_at.date' => 'A data de expiração deve ser uma data válida.',
                'expires_at.after' => 'A data de expiração deve ser uma data futura.',
            ]);

            // Verify course belongs to account
            $course = Course::where('account_id', $accountId)
                ->where('id', $validated['course_id'])
                ->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            // Find or create user
            $user = User::where('email', $validated['user_email'])->first();
            $tempPassword = null;
            $isNewUser = false;
            
            if (!$user) {
                // Create user with temporary password
                $tempPassword = Str::random(12);
                $isNewUser = true;
                $user = User::create([
                    'name' => $validated['user_name'] ?? explode('@', $validated['user_email'])[0],
                    'email' => $validated['user_email'],
                    'password' => Hash::make($tempPassword),
                    'account_id' => $accountId,
                ]);

                // Send email with credentials
                try {
                    \Illuminate\Support\Facades\Mail::to($user->email)
                        ->queue(new \App\Mail\MemberCredentialsMail(
                            $user->name,
                            $user->email,
                            $tempPassword,
                            $course->title ?? $course->name ?? 'Curso',
                            config('app.frontend_url', 'https://app.wazerpay.com.br') . '/members/login'
                        ));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to send member credentials email', ['error' => $e->getMessage()]);
                }
            }

            // Check for existing enrollment
            $existingEnrollment = Enrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            if ($existingEnrollment) {
                // Reactivate if exists
                $existingEnrollment->update([
                    'status' => 'active',
                    'access_type' => $validated['access_type'] ?? 'lifetime',
                    'expires_at' => $validated['expires_at'] ?? null,
                    'enrolled_at' => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Matrícula reativada com sucesso',
                    'data' => $existingEnrollment->fresh(['user', 'course']),
                ]);
            }

            // Create enrollment
            $enrollment = Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => 'active',
                'access_type' => $validated['access_type'] ?? 'lifetime',
                'enrolled_at' => now(),
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            // Create or update member record
            $member = Member::where('account_id', $accountId)
                ->where('email', $user->email)
                ->first();

            if (!$member) {
                $memberData = [
                    'account_id' => $accountId,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => 'active',
                ];
                
                // If new user, also save password hash in member for login
                if ($isNewUser && $tempPassword) {
                    $memberData['password'] = Hash::make($tempPassword);
                }
                
                Member::create($memberData);
            } else {
                // If member exists but has no password, set it now
                if ($isNewUser && $tempPassword && empty($member->password)) {
                    $member->update(['password' => Hash::make($tempPassword)]);
                }
            }

            $response = [
                'success' => true,
                'message' => 'Matrícula criada com sucesso',
                'data' => $enrollment->fresh(['user', 'course']),
                'is_new_user' => $isNewUser,
            ];

            // Include generated password for new users so admin can share it
            if ($isNewUser && $tempPassword) {
                $response['generated_password'] = $tempPassword;
            }

            return response()->json($response, 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollmentController::store error', [
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
     * Show enrollment details
     */
    public function show(Request $request, string $enrollmentId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $enrollment = Enrollment::whereHas('course', function ($q) use ($accountId) {
                    $q->where('account_id', $accountId);
                })
                ->where('id', $enrollmentId)
                ->with(['user:id,name,email', 'course:id,name,thumbnail_url', 'progress.lesson'])
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matrícula não encontrada',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $enrollment,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollmentController::show error', [
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
     * Update enrollment
     */
    public function update(Request $request, string $enrollmentId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $enrollment = Enrollment::whereHas('course', function ($q) use ($accountId) {
                    $q->where('account_id', $accountId);
                })
                ->where('id', $enrollmentId)
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matrícula não encontrada',
                ], 404);
            }

            $validated = $request->validate([
                'status' => 'sometimes|in:active,expired,cancelled,pending',
                'access_type' => 'sometimes|in:lifetime,subscription,limited',
                'expires_at' => 'sometimes|nullable|date',
            ]);

            $enrollment->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Matrícula atualizada com sucesso',
                'data' => $enrollment->fresh(['user', 'course']),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollmentController::update error', [
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
     * Cancel enrollment
     */
    public function destroy(Request $request, string $enrollmentId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $enrollment = Enrollment::whereHas('course', function ($q) use ($accountId) {
                    $q->where('account_id', $accountId);
                })
                ->where('id', $enrollmentId)
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matrícula não encontrada',
                ], 404);
            }

            $enrollment->update(['status' => 'cancelled']);

            return response()->json([
                'success' => true,
                'message' => 'Matrícula cancelada com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollmentController::destroy error', [
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
     * Extend enrollment access
     */
    public function extend(Request $request, string $enrollmentId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $enrollment = Enrollment::whereHas('course', function ($q) use ($accountId) {
                    $q->where('account_id', $accountId);
                })
                ->where('id', $enrollmentId)
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matrícula não encontrada',
                ], 404);
            }

            $validated = $request->validate([
                'days' => 'required|integer|min:1|max:365',
            ]);

            $currentExpiry = $enrollment->expires_at ?? now();
            $newExpiry = $currentExpiry->addDays($validated['days']);

            $enrollment->update([
                'expires_at' => $newExpiry,
                'status' => 'active',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Acesso estendido por {$validated['days']} dias",
                'data' => $enrollment->fresh(['user', 'course']),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollmentController::extend error', [
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
     * Reset student progress
     */
    public function resetProgress(Request $request, string $enrollmentId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $enrollment = Enrollment::whereHas('course', function ($q) use ($accountId) {
                    $q->where('account_id', $accountId);
                })
                ->where('id', $enrollmentId)
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matrícula não encontrada',
                ], 404);
            }

            // Delete all progress records
            $enrollment->progress()->delete();

            // Reset enrollment progress
            $enrollment->update([
                'progress_percent' => 0,
                'completed_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Progresso resetado com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollmentController::resetProgress error', [
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
     * Reset member password
     */
    public function resetMemberPassword(Request $request, string $enrollmentId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $enrollment = Enrollment::whereHas('course', function ($q) use ($accountId) {
                    $q->where('account_id', $accountId);
                })
                ->where('id', $enrollmentId)
                ->with('user')
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matrícula não encontrada',
                ], 404);
            }

            // Find member by user email
            $member = Member::where('account_id', $accountId)
                ->where('email', $enrollment->user->email)
                ->first();

            if (!$member) {
                // Create member if not exists
                $member = Member::create([
                    'account_id' => $accountId,
                    'user_id' => $enrollment->user_id,
                    'name' => $enrollment->user->name,
                    'email' => $enrollment->user->email,
                    'status' => 'active',
                ]);
            }

            // Generate new password
            $newPassword = Str::random(12);
            $member->update(['password' => Hash::make($newPassword)]);

            // Also update user password
            $enrollment->user->update(['password' => Hash::make($newPassword)]);

            // Try to send email with new credentials
            try {
                \Illuminate\Support\Facades\Mail::to($member->email)
                    ->queue(new \App\Mail\MemberCredentialsMail(
                        $member->name,
                        $member->email,
                        $newPassword,
                        $enrollment->course->name ?? 'Curso',
                        config('app.frontend_url', 'https://app.wazerpay.com.br') . '/members/login'
                    ));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to send password reset email', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Senha redefinida com sucesso',
                'generated_password' => $newPassword,
                'data' => [
                    'email' => $member->email,
                    'name' => $member->name,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberEnrollmentController::resetMemberPassword error', [
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
