<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Get the real client IP address.
     *
     * Priority: CF-Connecting-IP > X-Real-IP > $request->ip()
     * This handles Cloudflare → Nginx → PHP-FPM proxy chain.
     */
    protected function getClientIp(Request $request): string
    {
        // Cloudflare always sends the real client IP in this header
        $cfIp = $request->header('CF-Connecting-IP');
        if ($cfIp && filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }

        // Fallback to X-Real-IP set by our Nginx
        $realIp = $request->header('X-Real-IP');
        if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        // Final fallback - Laravel's built-in (uses X-Forwarded-For via TrustProxies)
        return $request->ip();
    }

    /**
     * Normalize CPF/CNPJ by keeping digits only.
     */
    protected function normalizeBrazilianDocument(?string $document): string
    {
        return preg_replace('/\D+/', '', (string) $document) ?? '';
    }

    /**
     * Validate CPF or CNPJ check digits.
     */
    protected function isValidCpfOrCnpj(?string $document): bool
    {
        $digits = $this->normalizeBrazilianDocument($document);

        return match (strlen($digits)) {
            11 => $this->isValidCpf($digits),
            14 => $this->isValidCnpj($digits),
            default => false,
        };
    }

    private function isValidCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($digitIndex = 9; $digitIndex < 11; $digitIndex++) {
            $sum = 0;

            for ($cursor = 0; $cursor < $digitIndex; $cursor++) {
                $sum += ((int) $cpf[$cursor]) * (($digitIndex + 1) - $cursor);
            }

            $checkDigit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$digitIndex] !== $checkDigit) {
                return false;
            }
        }

        return true;
    }

    private function isValidCnpj(string $cnpj): bool
    {
        if (!preg_match('/^\d{14}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weightsOne = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weightsTwo = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += ((int) $cnpj[$index]) * $weightsOne[$index];
        }

        $remainder = $sum % 11;
        $digitOne = $remainder < 2 ? 0 : 11 - $remainder;
        if ((int) $cnpj[12] !== $digitOne) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 13; $index++) {
            $sum += ((int) $cnpj[$index]) * $weightsTwo[$index];
        }

        $remainder = $sum % 11;
        $digitTwo = $remainder < 2 ? 0 : 11 - $remainder;

        return (int) $cnpj[13] === $digitTwo;
    }
}
