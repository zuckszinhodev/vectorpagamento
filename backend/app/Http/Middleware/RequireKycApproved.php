<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireKycApproved
{
    /**
     * Handle an incoming request.
     * 
     * KYC verification is currently disabled - all users are allowed access.
     * To enable KYC requirements, uncomment the verification logic below.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // KYC verification disabled - allow all authenticated users
        return $next($request);

        /* Uncomment to enable KYC verification:
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Não autenticado',
                ],
            ], 401);
        }

        if ($user->kyc_status !== 'approved') {
            $message = match ($user->kyc_status) {
                'pending' => 'Complete sua verificação de identidade para continuar',
                'submitted', 'under_review' => 'Sua verificação está em análise. Aguarde a aprovação.',
                'rejected' => 'Sua verificação foi rejeitada. Por favor, envie novamente.',
                default => 'Verificação de identidade necessária',
            };

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'kyc_required',
                    'message' => $message,
                    'kyc_status' => $user->kyc_status,
                    'kyc_rejection_reason' => $user->kyc_rejection_reason,
                ],
            ], 403);
        }

        return $next($request);
        */
    }
}
