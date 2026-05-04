<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemberCourseController extends Controller
{
    /**
     * List courses for authenticated account
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $query = Course::where('account_id', $accountId)
                ->withCount(['modules', 'enrollments']);

            if ($request->query('search')) {
                $search = addcslashes($request->query('search'), '%_\\');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($request->query('status') === 'published') {
                $query->where('is_published', true);
            } elseif ($request->query('status') === 'draft') {
                $query->where('is_published', false);
            }

            $perPage = min((int) $request->query('per_page', 20), 100);
            $courses = $query->orderBy('sort_order')->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $courses->items(),
                'meta' => [
                    'current_page' => $courses->currentPage(),
                    'per_page' => $courses->perPage(),
                    'total' => $courses->total(),
                    'last_page' => $courses->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberCourseController::index error', [
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
     * Create a new course
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'slug' => 'sometimes|string|max:255|regex:/^[a-z0-9-]+$/',
                'custom_domain' => 'sometimes|nullable|string|max:255',
                'description' => 'sometimes|nullable|string',
                'short_description' => 'sometimes|nullable|string|max:500',
                'thumbnail_url' => 'sometimes|nullable|url',
                'cover_url' => 'sometimes|nullable|url',
                'trailer_url' => 'sometimes|nullable|url',
                'instructor_name' => 'sometimes|nullable|string|max:255',
                'instructor_avatar' => 'sometimes|nullable|url',
                'instructor_bio' => 'sometimes|nullable|string',
                'level' => 'sometimes|in:beginner,intermediate,advanced,all',
                'tags' => 'sometimes|array',
                'requirements' => 'sometimes|array',
                'objectives' => 'sometimes|array',
                'product_id' => 'sometimes|nullable|uuid|exists:products,id',
                'is_featured' => 'sometimes|boolean',
                'is_published' => 'sometimes|boolean',
            ]);

            // Generate slug if not provided
            if (empty($validated['slug'])) {
                $baseSlug = Str::slug($validated['name']);
                $slug = $baseSlug;
                $counter = 1;
                
                while (Course::where('account_id', $accountId)->where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                
                $validated['slug'] = $slug;
            } else {
                // Check slug uniqueness
                if (Course::where('account_id', $accountId)->where('slug', $validated['slug'])->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Este slug já está em uso',
                    ], 422);
                }
            }

            $validated['account_id'] = $accountId;
            
            if (!empty($validated['is_published']) && empty($validated['published_at'])) {
                $validated['published_at'] = now();
            }

            $course = Course::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Curso criado com sucesso',
                'data' => $course,
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberCourseController::store error', [
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
     * Get a specific course
     */
    public function show(Request $request, string $courseId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $course = Course::where('account_id', $accountId)
                ->where('id', $courseId)
                ->with(['modules.lessons', 'product:id,name,price'])
                ->withCount(['enrollments', 'modules'])
                ->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $course,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberCourseController::show error', [
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
     * Update a course
     */
    public function update(Request $request, string $courseId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $course = Course::where('account_id', $accountId)
                ->where('id', $courseId)
                ->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'slug' => 'sometimes|string|max:255|regex:/^[a-z0-9-]+$/',
                'custom_domain' => 'sometimes|nullable|string|max:255',
                'description' => 'sometimes|nullable|string',
                'short_description' => 'sometimes|nullable|string|max:500',
                'thumbnail_url' => 'sometimes|nullable|url',
                'cover_url' => 'sometimes|nullable|url',
                'trailer_url' => 'sometimes|nullable|url',
                'instructor_name' => 'sometimes|nullable|string|max:255',
                'instructor_avatar' => 'sometimes|nullable|url',
                'instructor_bio' => 'sometimes|nullable|string',
                'level' => 'sometimes|in:beginner,intermediate,advanced,all',
                'tags' => 'sometimes|array',
                'requirements' => 'sometimes|array',
                'objectives' => 'sometimes|array',
                'product_id' => 'sometimes|nullable|uuid|exists:products,id',
                'sort_order' => 'sometimes|integer',
                'is_featured' => 'sometimes|boolean',
                'is_published' => 'sometimes|boolean',
            ]);

            // Check slug uniqueness
            if (isset($validated['slug']) && $validated['slug'] !== $course->slug) {
                if (Course::where('account_id', $accountId)->where('slug', $validated['slug'])->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Este slug já está em uso',
                    ], 422);
                }
            }

            // Set published_at if publishing for first time
            if (!empty($validated['is_published']) && !$course->is_published && !$course->published_at) {
                $validated['published_at'] = now();
            }

            $course->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Curso atualizado com sucesso',
                'data' => $course->fresh(['modules.lessons']),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberCourseController::update error', [
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
     * Delete a course
     */
    public function destroy(Request $request, string $courseId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $course = Course::where('account_id', $accountId)
                ->where('id', $courseId)
                ->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            // Check for active enrollments
            $activeEnrollments = $course->enrollments()->where('status', 'active')->count();
            if ($activeEnrollments > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Este curso possui {$activeEnrollments} alunos ativos. Cancele as matrículas primeiro.",
                ], 422);
            }

            $course->delete();

            return response()->json([
                'success' => true,
                'message' => 'Curso excluído com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberCourseController::destroy error', [
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
     * Duplicate a course
     */
    public function duplicate(Request $request, string $courseId): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $course = Course::where('account_id', $accountId)
                ->where('id', $courseId)
                ->with(['modules.lessons'])
                ->first();

            if (!$course) {
                return response()->json([
                    'success' => false,
                    'message' => 'Curso não encontrado',
                ], 404);
            }

            // Generate new slug
            $baseSlug = $course->slug . '-copia';
            $slug = $baseSlug;
            $counter = 1;
            
            while (Course::where('account_id', $accountId)->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            // Create new course
            $newCourse = $course->replicate();
            $newCourse->name = $course->name . ' (Cópia)';
            $newCourse->slug = $slug;
            $newCourse->is_published = false;
            $newCourse->published_at = null;
            $newCourse->save();

            // Duplicate modules and lessons
            foreach ($course->modules as $module) {
                $newModule = $module->replicate();
                $newModule->course_id = $newCourse->id;
                $newModule->save();

                foreach ($module->lessons as $lesson) {
                    $newLesson = $lesson->replicate();
                    $newLesson->module_id = $newModule->id;
                    $newLesson->save();
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Curso duplicado com sucesso',
                'data' => $newCourse->fresh(['modules.lessons']),
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberCourseController::duplicate error', [
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
     * Reorder courses
     */
    public function reorder(Request $request): JsonResponse
    {
        $accountId = $request->user()->account_id;

        $validated = $request->validate([
            'courses' => 'required|array',
            'courses.*.id' => 'required|uuid',
            'courses.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['courses'] as $item) {
            Course::where('account_id', $accountId)
                ->where('id', $item['id'])
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ordem atualizada com sucesso',
        ]);
    }

    /**
     * Get course students
     */
    public function students(Request $request, string $courseId): JsonResponse
    {
        $accountId = $request->user()->account_id;

        $course = Course::where('account_id', $accountId)
            ->where('id', $courseId)
            ->first();

        if (!$course) {
            return response()->json([
                'success' => false,
                'message' => 'Curso não encontrado',
            ], 404);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $enrollments = $course->enrollments()
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

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
    }
}
