<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberModuleController extends Controller
{
    /**
     * List modules for a course
     */
    public function index(Request $request, string $courseId): JsonResponse
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

            $modules = $course->modules()
                ->withCount('lessons')
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $modules,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberModuleController::index error', [
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
     * Create a new module
     */
    public function store(Request $request, string $courseId): JsonResponse
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
                'name' => 'required|string|max:255',
                'description' => 'sometimes|nullable|string',
                'thumbnail_url' => 'sometimes|nullable|url',
                'is_published' => 'sometimes|boolean',
                'is_free' => 'sometimes|boolean',
            ]);

            // Get next sort order
            $maxOrder = $course->modules()->max('sort_order') ?? -1;
            $validated['sort_order'] = $maxOrder + 1;
            $validated['course_id'] = $courseId;

            $module = CourseModule::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Módulo criado com sucesso',
                'data' => $module,
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberModuleController::store error', [
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
     * Get a specific module
     */
    public function show(Request $request, string $courseId, string $moduleId): JsonResponse
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
                ->with('lessons')
                ->first();

            if (!$module) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo não encontrado',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $module,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberModuleController::show error', [
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
     * Update a module
     */
    public function update(Request $request, string $courseId, string $moduleId): JsonResponse
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
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|nullable|string',
                'thumbnail_url' => 'sometimes|nullable|url',
                'sort_order' => 'sometimes|integer|min:0',
                'is_published' => 'sometimes|boolean',
                'is_free' => 'sometimes|boolean',
            ]);

            $module->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Módulo atualizado com sucesso',
                'data' => $module->fresh(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberModuleController::update error', [
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
     * Delete a module
     */
    public function destroy(Request $request, string $courseId, string $moduleId): JsonResponse
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

            $module->delete();

            // Update course duration
            $course->updateDuration();

            return response()->json([
                'success' => true,
                'message' => 'Módulo excluído com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberModuleController::destroy error', [
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
     * Reorder modules
     */
    public function reorder(Request $request, string $courseId): JsonResponse
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
                'modules' => 'required|array',
                'modules.*.id' => 'required|uuid',
                'modules.*.sort_order' => 'required|integer|min:0',
            ]);

            foreach ($validated['modules'] as $item) {
                CourseModule::where('course_id', $courseId)
                    ->where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Ordem atualizada com sucesso',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberModuleController::reorder error', [
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
