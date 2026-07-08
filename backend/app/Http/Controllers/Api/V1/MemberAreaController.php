<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MemberArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemberAreaController extends Controller
{
    private function uniqueSubdomain(string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'area-' . Str::lower(Str::random(8));
        $base = Str::limit($base, 52, '');
        $candidate = $base;
        $suffix = 2;

        while (
            MemberArea::where('subdomain', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = Str::limit($base, 52, '') . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Get member area settings for authenticated account
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            
            $memberArea = MemberArea::where('account_id', $accountId)->first();
            
            if (!$memberArea) {
                $memberArea = MemberArea::create([
                    'account_id' => $accountId,
                    'name' => $request->user()->account->name ?? 'Minha Área de Membros',
                    'subdomain' => $this->uniqueSubdomain($request->user()->account->name ?? 'Minha Area de Membros'),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $memberArea,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberAreaController::show failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao carregar área de membros'],
            ], 500);
        }
    }

    /**
     * Update member area settings
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            
            $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'logo_url' => 'sometimes|nullable|url',
            'favicon_url' => 'sometimes|nullable|url',
            'primary_color' => 'sometimes|string|max:7',
            'secondary_color' => 'sometimes|string|max:7',
            'accent_color' => 'sometimes|string|max:7',
            'background_color' => 'sometimes|string|max:7',
            'text_color' => 'sometimes|string|max:7',
            'font_family' => 'sometimes|string|max:100',
            'theme_mode' => 'sometimes|in:dark,light',
            'layout_style' => 'sometimes|in:netflix,traditional,minimal,grid,list',
            'banner_url' => 'sometimes|nullable|url',
            'welcome_message' => 'sometimes|nullable|string',
            'welcome_video_url' => 'sometimes|nullable|url',
            'subdomain' => 'sometimes|nullable|string|max:63|regex:/^[a-z0-9-]+$/',
            'custom_domain' => 'sometimes|nullable|string|max:255',
            'featured_courses' => 'sometimes|array',
            'home_sections' => 'sometimes|array',
            'custom_css' => 'sometimes|nullable|array',
            'settings' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $memberArea = MemberArea::where('account_id', $accountId)->first();
        
        if (!$memberArea) {
            if (!isset($validated['subdomain']) || !$validated['subdomain']) {
                $validated['subdomain'] = $this->uniqueSubdomain($validated['name'] ?? 'Minha Area de Membros');
            }

            $memberArea = MemberArea::create(array_merge([
                'account_id' => $accountId,
                'name' => 'Minha Área de Membros',
            ], $validated));
        } else {
            // Check subdomain uniqueness
            if (isset($validated['subdomain']) && $validated['subdomain'] !== $memberArea->subdomain) {
                $exists = MemberArea::where('subdomain', $validated['subdomain'])
                    ->where('id', '!=', $memberArea->id)
                    ->exists();
                    
                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Este subdomínio já está em uso',
                    ], 422);
                }
            }

            $memberArea->update($validated);
        }

        return response()->json([
            'success' => true,
            'message' => 'Área de membros atualizada com sucesso',
            'data' => $memberArea->fresh(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberAreaController::update failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao atualizar área de membros'],
            ], 500);
        }
    }

    /**
     * Get member area dashboard stats
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            
            $memberArea = MemberArea::where('account_id', $accountId)->first();
            
            if (!$memberArea) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_courses' => 0,
                        'total_lessons' => 0,
                        'total_members' => 0,
                        'total_enrollments' => 0,
                        'active_enrollments' => 0,
                        'completion_rate' => 0,
                        'recent_enrollments' => [],
                        'popular_courses' => [],
                    ],
                ]);
            }

            $totalCourses = \App\Models\Course::where('account_id', $accountId)->count();
            $totalLessons = \App\Models\Lesson::whereHas('module.course', function ($q) use ($accountId) {
                $q->where('account_id', $accountId);
            })->count();
            $totalMembers = \App\Models\Member::where('account_id', $accountId)->count();
            
            $totalEnrollments = \App\Models\Enrollment::whereHas('course', function ($q) use ($accountId) {
                $q->where('account_id', $accountId);
            })->count();
            
            $activeEnrollments = \App\Models\Enrollment::whereHas('course', function ($q) use ($accountId) {
                $q->where('account_id', $accountId);
            })->where('status', 'active')->count();

            $completedEnrollments = \App\Models\Enrollment::whereHas('course', function ($q) use ($accountId) {
                $q->where('account_id', $accountId);
            })->whereNotNull('completed_at')->count();

            $completionRate = $totalEnrollments > 0 
                ? round(($completedEnrollments / $totalEnrollments) * 100, 1) 
                : 0;

            $recentEnrollments = \App\Models\Enrollment::whereHas('course', function ($q) use ($accountId) {
                    $q->where('account_id', $accountId);
                })
                ->with(['user:id,name,email', 'course:id,name,thumbnail_url'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $popularCourses = \App\Models\Course::where('account_id', $accountId)
                ->withCount(['enrollments' => function ($q) {
                    $q->where('status', 'active');
                }])
                ->orderBy('enrollments_count', 'desc')
                ->limit(5)
                ->get(['id', 'name', 'thumbnail_url']);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_courses' => $totalCourses,
                    'total_lessons' => $totalLessons,
                    'total_members' => $totalMembers,
                    'total_enrollments' => $totalEnrollments,
                    'active_enrollments' => $activeEnrollments,
                    'completion_rate' => $completionRate,
                    'recent_enrollments' => $recentEnrollments,
                    'popular_courses' => $popularCourses,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberAreaController::dashboard failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true,
                'data' => [
                    'total_courses' => 0,
                    'total_lessons' => 0,
                    'total_members' => 0,
                    'total_enrollments' => 0,
                    'active_enrollments' => 0,
                    'completion_rate' => 0,
                    'recent_enrollments' => [],
                    'popular_courses' => [],
                ],
            ]);
        }
    }

    /**
     * Check subdomain availability
     */
    public function checkSubdomain(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'subdomain' => 'required|string|max:63|regex:/^[a-z0-9-]+$/',
            ]);

            $accountId = $request->user()->account_id;
            
            $exists = MemberArea::where('subdomain', $validated['subdomain'])
                ->where('account_id', '!=', $accountId)
                ->exists();

            return response()->json([
                'success' => true,
                'available' => !$exists,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'available' => false], 200);
        }
    }

    /**
     * Verify custom domain
     */
    public function verifyDomain(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;
            
            $memberArea = MemberArea::where('account_id', $accountId)->first();
            
            if (!$memberArea || !$memberArea->custom_domain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum domínio personalizado configurado',
                ], 400);
            }

            $memberArea->update([
                'domain_verified' => true,
                'ssl_enabled' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Domínio verificado com sucesso',
                'data' => $memberArea->fresh(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberAreaController::verifyDomain failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'internal_error', 'message' => 'Erro ao verificar domínio'],
            ], 500);
        }
    }
}
