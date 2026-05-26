<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\AcquirerConfig;
use App\Models\Charge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PixNominalNameService
{
    public function refreshAcquirerNominal(string $acquirerKey, ?string $preferredAccountId = null): array
    {
        $config = AcquirerConfig::where('acquirer_key', $acquirerKey)->where('enabled', true)->first();

        if (!$config) {
            return [
                'success' => false,
                'message' => 'Adquirente não encontrada ou desativada',
            ];
        }

        $account = $this->resolveNominalTestAccount($preferredAccountId);
        if (!$account) {
            return [
                'success' => false,
                'message' => 'Nao existe nenhuma conta ativa para gerar o PIX de teste da nominal',
            ];
        }

        $charge = Charge::create([
            'account_id' => $account->id,
            'idempotency_key' => 'nominal_' . Str::uuid()->toString(),
            'amount' => 1000,
            'currency' => 'BRL',
            'payment_method' => 'pix',
            'status' => 'pending',
            'acquirer' => $config->acquirer_key,
            'description' => 'Verificação de nominal',
            'customer' => [
                'name' => $this->resolveNominalCustomerName($account),
                'email' => 'nominal@vectorpagamentos.com',
                'document' => $this->resolveNominalCustomerDocument($account),
                'phone' => $this->resolveNominalCustomerPhone($account),
            ],
            'expires_at' => now()->addMinutes(5),
        ]);

        $result = $this->generateVerificationPix($config->acquirer_key, $charge);
        if (!$result || !($result['success'] ?? false)) {
            $charge->update(['status' => 'failed']);

            return [
                'success' => false,
                'message' => $result['error'] ?? 'Falha ao gerar PIX',
            ];
        }

        $pixPayload = (string) ($result['pix_copy_paste'] ?? $result['pix_qr_code'] ?? '');
        if ($pixPayload === '') {
            $charge->refresh();
            $pixPayload = (string) ($charge->pix_copy_paste ?: $charge->pix_qr_code ?: '');
        }

        $charge->update(['status' => 'expired']);

        if ($pixPayload === '') {
            return [
                'success' => false,
                'message' => 'PIX gerado sem payload',
            ];
        }

        $nominalName = $this->extractMerchantName($pixPayload);
        if ($nominalName === null) {
            return [
                'success' => false,
                'message' => 'Nominal não encontrada no payload',
            ];
        }

        $oldName = trim((string) $config->nominal_name);
        $config->nominal_name = $nominalName;
        $config->save();

        return [
            'success' => true,
            'acquirer' => $config->acquirer_key,
            'nominal_name' => $nominalName,
            'changed' => $oldName !== $nominalName,
        ];
    }

    public function refreshAllAcquirerNominals(?string $preferredAccountId = null): array
    {
        $results = [];

        foreach (AcquirerConfig::where('enabled', true)->get() as $config) {
            try {
                $results[] = $this->refreshAcquirerNominal($config->acquirer_key, $preferredAccountId);
            } catch (\Throwable $e) {
                $results[] = [
                    'success' => false,
                    'acquirer' => $config->acquirer_key,
                    'message' => $e->getMessage(),
                ];
            }
        }

        AcquirerConfig::clearCache();

        return $results;
    }

    public function syncFromCharge(Charge $charge, array $paymentResult = []): ?string
    {
        $acquirerKey = trim((string) ($paymentResult['acquirer'] ?? $charge->acquirer ?? ''));

        if ($acquirerKey === '') {
            return null;
        }

        if ($charge->payment_method === 'pix') {
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
        }

        return $this->syncFromLatestPixCharge($acquirerKey);
    }

    public function syncFromLatestPixCharge(string $acquirerKey): ?string
    {
        if ($acquirerKey === '') {
            return null;
        }

        $charge = Charge::query()
            ->where('acquirer', $acquirerKey)
            ->where('payment_method', 'pix')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereNotNull('pix_copy_paste')->where('pix_copy_paste', '!=', '');
                })->orWhere(function ($q) {
                    $q->whereNotNull('pix_qr_code')->where('pix_qr_code', '!=', '');
                });
            })
            ->latest()
            ->first();

        if (!$charge) {
            return null;
        }

        $payloads = array_filter([
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

    private function generateVerificationPix(string $acquirerKey, Charge $charge): ?array
    {
        $paymentService = app(PaymentService::class);
        $factory = AcquirerFactory::instance();

        return match ($acquirerKey) {
            'payfort' => $factory->payfort() ? $paymentService->generatePixViaPayFort($charge) : null,
            'blupay' => $factory->blupay() ? $paymentService->generatePixViaBluPay($charge) : null,
            'enki' => $factory->enki() ? $paymentService->generatePixViaEnki($charge) : null,
            'huntersub' => $factory->huntersub() ? $paymentService->generatePixViaHunterSub($charge) : null,
            'sharkhubsub' => $factory->sharkhubsub() ? $paymentService->generatePixViaSharkHubSub($charge) : null,
            'babylon' => $paymentService->isConfigured() ? $paymentService->generatePix($charge) : null,
            default => null,
        };
    }

    private function resolveNominalTestAccount(?string $preferredAccountId = null): ?Account
    {
        if ($preferredAccountId) {
            $account = Account::where('id', $preferredAccountId)->first();
            if ($account) {
                return $account;
            }
        }

        return Account::where('status', 'active')->orderBy('created_at')->first()
            ?? Account::orderBy('created_at')->first();
    }

    private function resolveNominalCustomerName(Account $account): string
    {
        $name = trim((string) ($account->name ?? ''));
        return $name !== '' ? $name : 'Verificacao Nominal';
    }

    private function resolveNominalCustomerDocument(Account $account): string
    {
        $document = preg_replace('/\D+/', '', (string) ($account->document ?? ''));

        if ($this->isValidCpfOrCnpj($document)) {
            return $document;
        }

        return '49722485806';
    }

    private function resolveNominalCustomerPhone(Account $account): string
    {
        $phone = preg_replace('/\D+/', '', (string) ($account->phone ?? ''));

        if (str_starts_with($phone, '55') && strlen($phone) >= 12) {
            $phone = substr($phone, 2);
        }

        if (strlen($phone) >= 10) {
            return $phone;
        }

        return '11999999999';
    }

    private function isValidCpfOrCnpj(?string $document): bool
    {
        $digits = preg_replace('/\D+/', '', (string) $document) ?? '';

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
