<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberLessonController extends Controller
{
    /**
     * List lessons for a module
     */
    public function index(Request $request, string $courseId, string $moduleId): JsonResponse
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

            $module = CourseModule::where('course_id', $courseId)
                ->where('id', $moduleId)
                ->first();

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo não encontrado',
                ], 404);
            }

            $lessons = $module->lessons()->orderBy('sort_order')->get();

            return response()->json([
                'success' => true,
                'data' => $lessons,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberLessonController::index error', [
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
     * Create a new lesson
     */
    public function store(Request $request, string $courseId, string $moduleId): JsonResponse
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

            $module = CourseModule::where('course_id', $courseId)
                ->where('id', $moduleId)
                ->first();

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo não encontrado',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'sometimes|nullable|string',
                'thumbnail_url' => 'sometimes|nullable|url',
                'content_type' => 'sometimes|in:video,text,quiz,download,live',
                'video_url' => 'sometimes|nullable|url',
                'video_provider' => 'sometimes|in:youtube,vimeo,bunny,custom',
                'duration_seconds' => 'sometimes|integer|min:0',
                'content' => 'sometimes|nullable|string',
                'attachments' => 'sometimes|array',
                'resources' => 'sometimes|array',
                'is_published' => 'sometimes|boolean',
                'is_free' => 'sometimes|boolean',
                'is_downloadable' => 'sometimes|boolean',
            ]);

            // Get next sort order
            $maxOrder = $module->lessons()->max('sort_order') ?? -1;
            $validated['sort_order'] = $maxOrder + 1;
            $validated['module_id'] = $moduleId;

            // Extract video ID if URL provided
            if (!empty($validated['video_url']) && !empty($validated['video_provider'])) {
                $videoId = Lesson::extractVideoId($validated['video_url'], $validated['video_provider']);
                if ($videoId) {
                    $validated['video_id'] = $videoId;
                }
            }

            $lesson = Lesson::create($validated);

            // Update course duration
            $course->updateDuration();

            return response()->json([
                'success' => true,
                'message' => 'Aula criada com sucesso',
                'data' => $lesson,
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberLessonController::store error', [
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
     * Get a specific lesson
     */
    public function show(Request $request, string $courseId, string $moduleId, string $lessonId): JsonResponse
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

            $module = CourseModule::where('course_id', $courseId)
                ->where('id', $moduleId)
                ->first();

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo não encontrado',
                ], 404);
            }

            $lesson = Lesson::where('module_id', $moduleId)
                ->where('id', $lessonId)
                ->first();

            if (!$lesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aula não encontrada',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $lesson,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberLessonController::show error', [
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
     * Update a lesson
     */
    public function update(Request $request, string $courseId, string $moduleId, string $lessonId): JsonResponse
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

            $module = CourseModule::where('course_id', $courseId)
                ->where('id', $moduleId)
                ->first();

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo não encontrado',
                ], 404);
            }

            $lesson = Lesson::where('module_id', $moduleId)
                ->where('id', $lessonId)
                ->first();

            if (!$lesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aula não encontrada',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|nullable|string',
                'thumbnail_url' => 'sometimes|nullable|url',
                'content_type' => 'sometimes|in:video,text,quiz,download,live',
                'video_url' => 'sometimes|nullable|url',
                'video_provider' => 'sometimes|in:youtube,vimeo,bunny,custom',
                'duration_seconds' => 'sometimes|integer|min:0',
                'content' => 'sometimes|nullable|string',
                'attachments' => 'sometimes|array',
                'resources' => 'sometimes|array',
                'sort_order' => 'sometimes|integer|min:0',
                'is_published' => 'sometimes|boolean',
                'is_free' => 'sometimes|boolean',
                'is_downloadable' => 'sometimes|boolean',
            ]);

            // Extract video ID if URL changed
            if (!empty($validated['video_url'])) {
                $provider = $validated['video_provider'] ?? $lesson->video_provider;
                if ($provider) {
                    $videoId = Lesson::extractVideoId($validated['video_url'], $provider);
                    if ($videoId) {
                        $validated['video_id'] = $videoId;
                    }
                }
            }

            $lesson->update($validated);

            // Update course duration if duration changed
            if (isset($validated['duration_seconds'])) {
                $course->updateDuration();
            }

            return response()->json([
                'success' => true,
                'message' => 'Aula atualizada com sucesso',
                'data' => $lesson->fresh(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberLessonController::update error', [
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
     * Delete a lesson
     */
    public function destroy(Request $request, string $courseId, string $moduleId, string $lessonId): JsonResponse
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

            $module = CourseModule::where('course_id', $courseId)
                ->where('id', $moduleId)
                ->first();

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo não encontrado',
                ], 404);
            }

            $lesson = Lesson::where('module_id', $moduleId)
                ->where('id', $lessonId)
                ->first();

            if (!$lesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aula não encontrada',
                ], 404);
            }

            $lesson->delete();

            // Update course duration
            $course->updateDuration();

            return response()->json([
                'success' => true,
                'message' => 'Aula excluída com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberLessonController::destroy error', [
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
     * Reorder lessons
     */
    public function reorder(Request $request, string $courseId, string $moduleId): JsonResponse
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

            $module = CourseModule::where('course_id', $courseId)
                ->where('id', $moduleId)
                ->first();

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo não encontrado',
                ], 404);
            }

            $validated = $request->validate([
                'lessons' => 'required|array',
                'lessons.*.id' => 'required|uuid',
                'lessons.*.sort_order' => 'required|integer|min:0',
            ]);

            foreach ($validated['lessons'] as $item) {
                Lesson::where('module_id', $moduleId)
                    ->where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Ordem atualizada com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberLessonController::reorder error', [
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
     * Move lesson to another module
     */
    public function move(Request $request, string $courseId, string $moduleId, string $lessonId): JsonResponse
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

            $lesson = Lesson::where('module_id', $moduleId)
                ->where('id', $lessonId)
                ->first();

            if (!$lesson) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aula não encontrada',
                ], 404);
            }

            $validated = $request->validate([
                'target_module_id' => 'required|uuid',
            ]);

            // Verify target module belongs to the same course
            $targetModule = CourseModule::where('course_id', $courseId)
                ->where('id', $validated['target_module_id'])
                ->first();

            if (!$targetModule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo de destino não encontrado',
                ], 404);
            }

            // Get next sort order in target module
            $maxOrder = $targetModule->lessons()->max('sort_order') ?? -1;

            $lesson->update([
                'module_id' => $targetModule->id,
                'sort_order' => $maxOrder + 1,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Aula movida com sucesso',
                'data' => $lesson->fresh(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberLessonController::move error', [
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
