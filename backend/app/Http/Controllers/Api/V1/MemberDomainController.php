<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomDomain;
use App\Models\MemberArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MemberDomainController extends Controller
{
    /**
     * Resolve member area from custom domain or subdomain.
     * 
     * Uses the unified CustomDomain model (same as checkout domains).
     * Domains with use_for containing 'members' can serve the member area.
     */
    public function resolve(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'domain' => 'required|string|max:255',
            ]);

            $domain = strtolower(trim($validated['domain']));

            // 1. Try unified CustomDomain (verified + use_for includes 'members')
            $customDomain = CustomDomain::where('domain', $domain)
                ->where('status', 'verified')
                ->whereJsonContains('use_for', 'members')
                ->first();

            if ($customDomain) {
                $memberArea = MemberArea::where('account_id', $customDomain->account_id)
                    ->whereRaw('"is_active" = true')
                    ->first();

                if ($memberArea) {
                    return response()->json([
                        'success' => true,
                        'data' => $this->formatMemberAreaPublic($memberArea),
                    ]);
                }
            }

            // 2. Fallback: try subdomain (e.g., school-name.members.vectorpagamentos.com)
            $subdomain = $this->extractSubdomain($domain);
            if ($subdomain) {
                $memberArea = MemberArea::where('subdomain', $subdomain)
                    ->whereRaw('"is_active" = true')
                    ->first();

                if ($memberArea) {
                    return response()->json([
                        'success' => true,
                        'data' => $this->formatMemberAreaPublic($memberArea),
                    ]);
                }
            }

            // 3. Legacy fallback: MemberArea.custom_domain field (for old data)
            $memberArea = MemberArea::where('custom_domain', $domain)
                ->where('domain_verified', true)
                ->whereRaw('"is_active" = true')
                ->first();

            if ($memberArea) {
                return response()->json([
                    'success' => true,
                    'data' => $this->formatMemberAreaPublic($memberArea),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ãrea de membros nÃ£o encontrada',
            ], 404);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberDomainController::resolve error', [
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
     * Check domain verification for member area.
     * 
     * Now uses the unified CustomDomain model - just checks if the domain
     * selected for the member area is verified and has 'members' in use_for.
     */
    public function checkDomainVerification(Request $request): JsonResponse
    {
        try {
            $accountId = $request->user()->account_id;

            $memberArea = MemberArea::where('account_id', $accountId)->first();

            if (!$memberArea || !$memberArea->custom_domain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum domÃ­nio personalizado configurado',
                ], 400);
            }

            $domain = $memberArea->custom_domain;

            // Check if domain exists in CustomDomain and is verified for members
            $customDomain = CustomDomain::where('account_id', $accountId)
                ->where('domain', $domain)
                ->where('status', 'verified')
                ->whereJsonContains('use_for', 'members')
                ->first();

            if ($customDomain) {
                // Sync verification status to MemberArea
                if (!$memberArea->domain_verified) {
                    $memberArea->update([
                        'domain_verified' => true,
                        'ssl_enabled' => true,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'domain' => $domain,
                        'domain_verified' => true,
                        'ssl_enabled' => true,
                        'message' => 'DomÃ­nio verificado via pÃ¡gina de DomÃ­nios',
                    ],
                ]);
            }

            // Domain not verified in CustomDomain - provide instructions
            $pendingDomain = CustomDomain::where('account_id', $accountId)
                ->where('domain', $domain)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'domain' => $domain,
                    'domain_verified' => false,
                    'ssl_enabled' => false,
                    'message' => $pendingDomain
                        ? 'DomÃ­nio encontrado mas ainda nÃ£o verificado. Verifique os DNS na pÃ¡gina de DomÃ­nios.'
                        : 'DomÃ­nio nÃ£o encontrado. Adicione-o primeiro na pÃ¡gina de DomÃ­nios.',
                    'needs_domain_page' => true,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberDomainController::checkDomainVerification error', [
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
     * Generate or regenerate verification token.
     * Redirects to the unified domain system.
     */
    public function generateToken(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => false,
                'message' => 'Use a pÃ¡gina de DomÃ­nios para gerenciar verificaÃ§Ã£o DNS. Os domÃ­nios verificados ficam automaticamente disponÃ­veis para a Ãrea de Membros.',
                'redirect' => '/domains',
            ], 400);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MemberDomainController::generateToken error', [
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
     * Format member area data for public consumption.
     */
    private function formatMemberAreaPublic(MemberArea $memberArea): array
    {
        return [
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
            'custom_css' => $memberArea->custom_css,
        ];
    }

    /**
     * Extract subdomain from domain.
     */
    private function extractSubdomain(string $domain): ?string
    {
        $memberDomain = config('app.member_area_domain', 'members.vectorpagamentos.com');

        if (str_ends_with($domain, '.' . $memberDomain)) {
            return str_replace('.' . $memberDomain, '', $domain);
        }

        // Also check without .br
        $memberDomainAlt = str_replace('.com.br', '.com', $memberDomain);
        if (str_ends_with($domain, '.' . $memberDomainAlt)) {
            return str_replace('.' . $memberDomainAlt, '', $domain);
        }

        return null;
    }
}
