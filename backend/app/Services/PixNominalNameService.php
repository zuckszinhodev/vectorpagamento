<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcquirerConfig;
use App\Models\Charge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PixNominalNameService
{
    public function syncFromCharge(Charge $charge, array $paymentResult = []): ?string
    {
        $acquirerKey = trim((string) ($paymentResult['acquirer'] ?? $charge->acquirer ?? ''));

        if ($acquirerKey === '' || $charge->payment_method !== 'pix') {
            return null;
        }

        $payloads = array_filter([
            $paymentResult['pix_copy_paste'] ?? null,
            $paymentResult['pix_qr_code'] ?? null,
            $charge->pix_copy_paste,
            $charge->pix_qr_code,
        ], fn ($value) => is_string($value) && trim($value) !== '');

        foreach ($payloads as $payload) {
            $nominalName = $this->extractMerchantName((string) $payload);

            if (!$nominalName) {
                continue;
            }

            $this->updateAcquirerNominalName($acquirerKey, $nominalName);

            return $nominalName;
        }

        return null;
    }

    public function extractMerchantName(string $pixPayload): ?string
    {
        $payload = trim($pixPayload);

        if ($payload === '' || str_starts_with($payload, 'data:') || filter_var($payload, FILTER_VALIDATE_URL)) {
            return null;
        }

        $fields = $this->parseEmvFields($payload);
        $merchantName = $fields['59'] ?? null;

        if (!is_string($merchantName) || trim($merchantName) === '') {
            return null;
        }

        return $this->formatMerchantName($merchantName);
    }

    private function updateAcquirerNominalName(string $acquirerKey, string $nominalName): void
    {
        try {
            $config = AcquirerConfig::where('acquirer_key', $acquirerKey)->first();

            if (!$config) {
                return;
            }

            if (trim((string) $config->nominal_name) === $nominalName) {
                return;
            }

            $config->nominal_name = $nominalName;
            $config->save();
        } catch (\Throwable $e) {
            Log::warning('Failed to sync PIX nominal name', [
                'acquirer' => $acquirerKey,
                'nominal_name' => $nominalName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parses root-level EMV fields used by PIX copy/paste payloads.
     */
    private function parseEmvFields(string $payload): array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset + 4 <= $length) {
            $id = substr($payload, $offset, 2);
            $fieldLength = substr($payload, $offset + 2, 2);

            if (!ctype_digit($id) || !ctype_digit($fieldLength)) {
                break;
            }

            $valueLength = (int) $fieldLength;
            $valueStart = $offset + 4;

            if ($valueStart + $valueLength > $length) {
                break;
            }

            $fields[$id] = substr($payload, $valueStart, $valueLength);
            $offset = $valueStart + $valueLength;
        }

        return $fields;
    }

    private function formatMerchantName(string $name): string
    {
        $normalized = preg_replace('/[_\s]+/', ' ', trim($name)) ?? trim($name);
        $normalized = Str::lower($normalized);
        $normalized = str_replace(
            ['intermediacoes', 'transacoes', 'solucoes', 'informacoes'],
            ['intermediações', 'transações', 'soluções', 'informações'],
            $normalized
        );

        return ucfirst($normalized);
    }
}
