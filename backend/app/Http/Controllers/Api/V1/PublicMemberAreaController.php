<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonComment;
use App\Models\LessonProgress;
use App\Models\Member;
use App\Models\MemberArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class PublicMemberAreaController extends Controller
{
    /**
     * Get member area by subdomain or custom domain
     */
    public function getArea(Request $request): JsonResponse
    {
        try {
            $host = $request->getHost();
            
            // Try custom domain first
            $memberArea = MemberArea::where('custom_domain', $host)
                ->where('domain_verified', true)
                ->where('is_active', true)
                ->first();

            if (!$memberArea) {
                // Try subdomain
                $subdomain = explode('.', $host)[0];
                $memberArea = MemberArea::where('subdomain', $subdomain)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$memberArea) {
                return response()->json([
                    'success' => false,
                    'message' => 'Área de membros não encontrada',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $memberArea->id,
                    'name' => $memberArea->name,
                    'description' => $memberArea->description,
                    'logo_url' => $memberArea->logo_url,
                    'favicon_url' => $memberArea->favicon_url,
                    'primary_color' => $memberArea->primary_color,
                    'secondary_color' => $memberArea->secondary_color,
                    'accent_color' => $memberArea->accent_color,
                    'background_color' => $memberArea->background_color,
                    'text_color' => $memberArea->text_color,
                    'font_family' => $memberArea->font_family,
                    'theme_mode' => $memberArea->theme_mode,
                    'layout_style' => $memberArea->layout_style,
                    'banner_url' => $memberArea->banner_url,
                    'welcome_message' => $memberArea->welcome_message,
                    'welcome_video_url' => $memberArea->welcome_video_url,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::getArea error', [
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
     * Member login
     */
    public function login(Request $request): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Log::info('Member login attempt', [
                'email' => $request->input('email'),
                'has_account_id' => $request->has('account_id'),
                'origin' => $request->header('Origin'),
                'referer' => $request->header('Referer'),
                'host' => $request->getHost(),
            ]);

            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
                'account_id' => 'sometimes|uuid',
            ]);

            // Try to get account_id from request, or resolve from domain
            $accountId = $validated['account_id'] ?? null;
            
            if (!$accountId) {
                // Try to resolve account_id from Origin header or Referer
                $origin = $request->header('Origin') ?? $request->header('Referer') ?? '';
                $host = parse_url($origin, PHP_URL_HOST) ?? $request->getHost();
                
                \Illuminate\Support\Facades\Log::info('Resolving account by domain', [
                    'origin' => $origin,
                    'host' => $host,
                ]);
                
                // Try custom domain first
                $memberArea = MemberArea::where('custom_domain', $host)
                    ->where('domain_verified', true)
                    ->where('is_active', true)
                    ->first();
                
                \Illuminate\Support\Facades\Log::info('Custom domain lookup', [
                    'found' => (bool) $memberArea,
                    'member_area_id' => $memberArea?->id,
                ]);
                
                if (!$memberArea) {
                    // Try by subdomain
                    $subdomain = explode('.', $host)[0];
                    $memberArea = MemberArea::where('subdomain', $subdomain)
                        ->where('is_active', true)
                        ->first();
                        
                    \Illuminate\Support\Facades\Log::info('Subdomain lookup', [
                        'subdomain' => $subdomain,
                        'found' => (bool) $memberArea,
                    ]);
                }
                
                // Also try matching custom domain from domains table
                if (!$memberArea) {
                    $customDomain = \App\Models\CustomDomain::where('domain', $host)
                        ->where('status', 'verified')
                        ->first();
                    if ($customDomain) {
                        $accountId = $customDomain->account_id;
                        \Illuminate\Support\Facades\Log::info('Found account from CustomDomain table', [
                            'account_id' => $accountId,
                        ]);
                    }
                }
                
                if ($memberArea) {
                    $accountId = $memberArea->account_id;
                }
            }
            
            // If still no account_id, try to find member by email across all accounts
            if (!$accountId) {
                $member = Member::where('email', $validated['email'])->first();
                if ($member) {
                    $accountId = $member->account_id;
                    \Illuminate\Support\Facades\Log::info('Found account from member email', [
                        'member_id' => $member->id,
                        'account_id' => $accountId,
                    ]);
                }
            }
            
            \Illuminate\Support\Facades\Log::info('Account resolution result', [
                'account_id' => $accountId,
            ]);
            
            if (!$accountId) {
                \Illuminate\Support\Facades\Log::warning('Could not resolve account_id for member login', [
                    'email' => $validated['email'],
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Não foi possível identificar a área de membros',
                ], 422);
            }

            $member = Member::where('account_id', $accountId)
                ->where('email', $validated['email'])
                ->first();

            \Illuminate\Support\Facades\Log::info('Member lookup', [
                'found' => (bool) $member,
                'member_id' => $member?->id,
                'has_password' => $member ? !empty($member->password) : false,
            ]);

            if (!$member || !Hash::check($validated['password'], $member->password)) {
                \Illuminate\Support\Facades\Log::warning('Invalid credentials', [
                    'member_found' => (bool) $member,
                    'password_match' => $member ? Hash::check($validated['password'], $member->password) : false,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciais inválidas',
                ], 401);
            }

            if (!$member->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conta inativa ou bloqueada',
                ], 403);
            }

            $member->update(['last_login_at' => now()]);

            $token = JWTAuth::fromUser($member);

            return response()->json([
                'success' => true,
                'data' => [
                    'member' => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'avatar_url' => $member->avatar_url,
                    ],
                    'token' => [
                        'access_token' => $token,
                        'token_type' => 'bearer',
                        'expires_in' => config('jwt.ttl') * 60,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::login error', [
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
     * Get member profile
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $member = $request->user();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar_url' => $member->avatar_url,
                    'phone' => $member->phone,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::profile error', [
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
     * Update member profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $member = $request->user();

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'avatar_url' => 'sometimes|nullable|url',
                'phone' => 'sometimes|nullable|string|max:20',
                'password' => 'sometimes|string|min:8|confirmed',
            ]);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $member->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Perfil atualizado com sucesso',
                'data' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar_url' => $member->avatar_url,
                    'phone' => $member->phone,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::updateProfile error', [
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
     * List enrolled courses for member
     */
    public function myCourses(Request $request): JsonResponse
    {
        try {
            $member = $request->user();

            $enrollments = Enrollment::where('user_id', $member->user_id)
                ->where('status', 'active')
                ->with(['course' => function ($q) {
                    $q->select('id', 'name', 'slug', 'short_description', 'thumbnail_url', 'cover_url', 'duration_minutes', 'level')
                        ->withCount(['modules', 'lessons']);
                }])
                ->get();

            $courses = $enrollments->map(function ($enrollment) {
                return [
                    'enrollment_id' => $enrollment->id,
                    'course' => $enrollment->course,
                    'progress_percent' => $enrollment->progress_percent,
                    'enrolled_at' => $enrollment->enrolled_at,
                    'expires_at' => $enrollment->expires_at,
                    'completed_at' => $enrollment->completed_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $courses,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::myCourses error', [
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
     * Get "continue watching" list
     */
    public function continueWatching(Request $request): JsonResponse
    {
        try {
            $member = $request->user();

            $progress = LessonProgress::whereHas('enrollment', function ($q) use ($member) {
                    $q->where('user_id', $member->user_id)
                        ->where('status', 'active');
                })
                ->where('is_completed', false)
                ->where('progress_percent', '>', 0)
                ->orderBy('last_watched_at', 'desc')
                ->limit(10)
                ->with(['lesson:id,name,thumbnail_url,duration_seconds,module_id', 'lesson.module:id,name,course_id', 'lesson.module.course:id,name,thumbnail_url'])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $progress,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::continueWatching error', [
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
     * Get course details for member
     */
    public function showCourse(Request $request, string $courseSlug): JsonResponse
    {
        try {
            $member = $request->user();

            // Accept either ID or slug
            $course = Course::where('slug', $courseSlug)
                ->orWhere('id', $courseSlug)
                ->where('is_published', true)
                ->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            // Check enrollment
            $enrollment = Enrollment::where('user_id', $member->user_id)
                ->where('course_id', $course->id)
                ->active()
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem acesso a este curso',
                ], 403);
            }

            // Get modules with lessons and progress
            $modules = $course->modules()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->with(['lessons' => function ($q) {
                    $q->where('is_published', true)->orderBy('sort_order');
                }])
                ->get();

            // Get progress for all lessons
            $lessonProgress = LessonProgress::where('enrollment_id', $enrollment->id)
                ->get()
                ->keyBy('lesson_id');

            // Add progress to lessons
            $modules = $modules->map(function ($module) use ($lessonProgress) {
                $module->lessons = $module->lessons->map(function ($lesson) use ($lessonProgress) {
                    $progress = $lessonProgress->get($lesson->id);
                    $lesson->progress = $progress ? [
                        'watched_seconds' => $progress->watched_seconds,
                        'progress_percent' => $progress->progress_percent,
                        'is_completed' => $progress->is_completed,
                    ] : null;
                    return $lesson;
                });
                return $module;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'course' => [
                        'id' => $course->id,
                        'name' => $course->name,
                        'slug' => $course->slug,
                        'description' => $course->description,
                        'thumbnail_url' => $course->thumbnail_url,
                        'cover_url' => $course->cover_url,
                        'trailer_url' => $course->trailer_url,
                        'instructor_name' => $course->instructor_name,
                        'instructor_avatar' => $course->instructor_avatar,
                        'instructor_bio' => $course->instructor_bio,
                        'duration_minutes' => $course->duration_minutes,
                        'level' => $course->level,
                    ],
                    'enrollment' => [
                        'id' => $enrollment->id,
                        'progress_percent' => $enrollment->progress_percent,
                        'enrolled_at' => $enrollment->enrolled_at,
                        'expires_at' => $enrollment->expires_at,
                        'completed_at' => $enrollment->completed_at,
                    ],
                    'modules' => $modules,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::showCourse error', [
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
     * Get lesson for watching
     */
    public function showLesson(Request $request, string $courseSlug, string $lessonId): JsonResponse
    {
        try {
            $member = $request->user();

            $course = Course::where('slug', $courseSlug)->orWhere('id', $courseSlug)->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            // Check enrollment
            $enrollment = Enrollment::where('user_id', $member->user_id)
                ->where('course_id', $course->id)
                ->active()
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem acesso a este curso',
                ], 403);
            }

            $lesson = Lesson::where('id', $lessonId)
                ->whereHas('module', function ($q) use ($course) {
                    $q->where('course_id', $course->id);
                })
                ->where('is_published', true)
                ->with('module:id,name,course_id')
                ->first();

            if (!$lesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aula não encontrada',
                ], 404);
            }

            // Get or create progress
            $progress = LessonProgress::firstOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'watched_seconds' => 0,
                    'progress_percent' => 0,
                ]
            );

            // Get next and previous lessons
            $allLessons = Lesson::whereHas('module', function ($q) use ($course) {
                    $q->where('course_id', $course->id)->where('is_published', true);
                })
                ->where('is_published', true)
                ->join('course_modules', 'lessons.module_id', '=', 'course_modules.id')
                ->orderBy('course_modules.sort_order')
                ->orderBy('lessons.sort_order')
                ->select('lessons.*')
                ->get();

            $currentIndex = $allLessons->search(function ($l) use ($lesson) {
                return $l->id === $lesson->id;
            });

            $previousLesson = $currentIndex > 0 ? $allLessons[$currentIndex - 1] : null;
            $nextLesson = $currentIndex < $allLessons->count() - 1 ? $allLessons[$currentIndex + 1] : null;

            return response()->json([
                'success' => true,
                'data' => [
                    'lesson' => [
                        'id' => $lesson->id,
                        'name' => $lesson->name,
                        'description' => $lesson->description,
                        'content_type' => $lesson->content_type,
                        'video_url' => $lesson->video_url,
                        'video_provider' => $lesson->video_provider,
                        'video_id' => $lesson->video_id,
                        'embed_url' => $lesson->embed_url,
                        'duration_seconds' => $lesson->duration_seconds,
                        'content' => $lesson->content,
                        'attachments' => $lesson->attachments,
                        'resources' => $lesson->resources,
                        'module' => $lesson->module,
                    ],
                    'progress' => [
                        'watched_seconds' => $progress->watched_seconds,
                        'progress_percent' => $progress->progress_percent,
                        'is_completed' => $progress->is_completed,
                    ],
                    'navigation' => [
                        'previous' => $previousLesson ? [
                            'id' => $previousLesson->id,
                            'name' => $previousLesson->name,
                        ] : null,
                        'next' => $nextLesson ? [
                            'id' => $nextLesson->id,
                            'name' => $nextLesson->name,
                        ] : null,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::showLesson error', [
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
     * Update lesson progress
     */
    public function updateProgress(Request $request, string $courseSlug, string $lessonId): JsonResponse
    {
        try {
            $member = $request->user();

            $validated = $request->validate([
                'watched_seconds' => 'sometimes|integer|min:0',
                'completed' => 'sometimes|boolean',
            ]);

            $course = Course::where('slug', $courseSlug)->orWhere('id', $courseSlug)->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            $enrollment = Enrollment::where('user_id', $member->user_id)
                ->where('course_id', $course->id)
                ->active()
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado',
                ], 403);
            }

            $lesson = Lesson::where('id', $lessonId)
                ->whereHas('module', function ($q) use ($course) {
                    $q->where('course_id', $course->id);
                })
                ->first();

            if (!$lesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aula não encontrada',
                ], 404);
            }

            $progress = LessonProgress::firstOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'watched_seconds' => 0,
                    'progress_percent' => 0,
                ]
            );

            // Handle completion
            if (isset($validated['completed']) && $validated['completed']) {
                $progress->markAsCompleted();
            } elseif (isset($validated['watched_seconds'])) {
                $progress->updateProgress($validated['watched_seconds']);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'watched_seconds' => $progress->watched_seconds,
                    'progress_percent' => $progress->progress_percent,
                    'is_completed' => $progress->is_completed,
                    'enrollment_progress' => $enrollment->fresh()->progress_percent,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::updateProgress error', [
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
     * Mark lesson as completed
     */
    public function completeLesson(Request $request, string $courseSlug, string $lessonId): JsonResponse
    {
        try {
            $member = $request->user();

            $course = Course::where('slug', $courseSlug)->orWhere('id', $courseSlug)->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            $enrollment = Enrollment::where('user_id', $member->user_id)
                ->where('course_id', $course->id)
                ->active()
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acesso negado',
                ], 403);
            }

            $lesson = Lesson::where('id', $lessonId)
                ->whereHas('module', function ($q) use ($course) {
                    $q->where('course_id', $course->id);
                })
                ->first();

            if (!$lesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aula não encontrada',
                ], 404);
            }

            $progress = LessonProgress::firstOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'watched_seconds' => 0,
                    'progress_percent' => 0,
                ]
            );

            $progress->markAsCompleted();

            return response()->json([
                'success' => true,
                'message' => 'Aula concluída',
                'data' => [
                    'enrollment_progress' => $enrollment->fresh()->progress_percent,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::completeLesson error', [
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
     * Get comments for a lesson
     */
    public function getComments(Request $request, string $courseSlug, string $lessonId): JsonResponse
    {
        try {
            $member = $request->user();

            // Verify access
            $course = Course::where('slug', $courseSlug)->orWhere('id', $courseSlug)->first();
            if (!$course) {
                return response()->json(['success' => false, 'message' => 'Curso não encontrado'], 404);
            }

            $hasAccess = Enrollment::where('user_id', $member->user_id)
                ->where('course_id', $course->id)
                ->active()
                ->exists();

            if (!$hasAccess) {
                return response()->json(['success' => false, 'message' => 'Acesso negado'], 403);
            }

            $comments = LessonComment::where('lesson_id', $lessonId)
                ->where('is_approved', true)
                ->whereNull('parent_id')
                ->with(['member:id,name,avatar_url', 'replies.member:id,name,avatar_url'])
                ->orderByDesc('is_pinned')
                ->orderByDesc('created_at')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $comments->items(),
                'meta' => [
                    'current_page' => $comments->currentPage(),
                    'total' => $comments->total(),
                    'last_page' => $comments->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::getComments error', [
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
     * Add comment to lesson
     */
    public function addComment(Request $request, string $courseSlug, string $lessonId): JsonResponse
    {
        try {
            $member = $request->user();

            $validated = $request->validate([
                'content' => 'required|string|max:2000',
                'parent_id' => 'sometimes|uuid|exists:lesson_comments,id',
            ]);

            // Verify access
            $course = Course::where('slug', $courseSlug)->orWhere('id', $courseSlug)->first();
            if (!$course) {
                return response()->json(['success' => false, 'message' => 'Curso não encontrado'], 404);
            }

            $hasAccess = Enrollment::where('user_id', $member->user_id)
                ->where('course_id', $course->id)
                ->active()
                ->exists();

            if (!$hasAccess) {
                return response()->json(['success' => false, 'message' => 'Acesso negado'], 403);
            }

            $comment = LessonComment::create([
                'lesson_id' => $lessonId,
                'member_id' => $member->id,
                'parent_id' => $validated['parent_id'] ?? null,
                'content' => $validated['content'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Comentário adicionado',
                'data' => $comment->load('member:id,name,avatar_url'),
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::addComment error', [
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
     * Get certificate
     */
    public function getCertificate(Request $request, string $courseSlug): JsonResponse
    {
        try {
            $member = $request->user();

            $course = Course::where('slug', $courseSlug)->orWhere('id', $courseSlug)->first();
            if (!$course) {
                return response()->json(['success' => false, 'message' => 'Curso não encontrado'], 404);
            }

            $enrollment = Enrollment::where('user_id', $member->user_id)
                ->where('course_id', $course->id)
                ->whereNotNull('completed_at')
                ->first();

            if (!$enrollment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você ainda não concluiu este curso',
                ], 400);
            }

            // Get or create certificate
            $certificate = Certificate::firstOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                ],
                [
                    'course_id' => $course->id,
                    'member_id' => $member->id,
                    'code' => Certificate::generateCode(),
                    'name' => $member->name,
                    'issued_at' => now(),
                ]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'certificate' => $certificate,
                    'course' => [
                        'name' => $course->name,
                        'instructor_name' => $course->instructor_name,
                    ],
                    'verification_url' => $certificate->verification_url,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PublicMemberAreaController::getCertificate error', [
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
