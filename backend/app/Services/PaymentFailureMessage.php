<?php

declare(strict_types=1);

namespace App\Services;

/** Messages shared by payment responses and the saved transaction failure reason. */
final class PaymentFailureMessage
{
    public static function amountLimit(int $cents, string $acquirer, bool $maximum = true): string
    {
        $bound = $maximum ? 'máximo' : 'mínimo';
        $value = number_format($cents / 100, 2, ',', '.');

        return "O valor {$bound} para gerar Pix na adquirente {$acquirer} é R$ {$value}.";
    }

    public static function customerField(string $field, mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $label = match ($field) {
            'phone' => 'Telefone',
            'email' => 'E-mail',
            'document' => 'CPF/CNPJ',
            'name' => 'Nome',
        };
        if ($value === '') {
            return "{$label} do cliente ausente. Informe esse dado para gerar a cobrança.";
        }
        $digits = preg_replace('/\D/', '', $value);

        return match ($field) {
            'phone' => strlen($digits) < 10 || strlen($digits) > 13
                ? 'Telefone do cliente inválido. Informe o número com DDD, entre 10 e 13 dígitos.' : null,
            'email' => ! filter_var($value, FILTER_VALIDATE_EMAIL)
                ? 'E-mail do cliente inválido. Confira o endereço informado.' : null,
            'document' => ! in_array(strlen($digits), [11, 14], true)
                ? 'CPF/CNPJ do cliente inválido. Informe 11 dígitos para CPF ou 14 para CNPJ.' : null,
            default => null,
        };
    }

    public static function fromProvider(string $message, string $acquirer, array $customer = [], ?int $httpStatus = null): string
    {
        $message = trim(strip_tags($message));
        $search = mb_strtolower($message);
        // Only interpret a field when the provider actually identified it as the error.
        foreach (['phone' => '/telefone|phone|celular/u', 'email' => '/e-?mail/u', 'document' => '/cpf|cnpj|document/u'] as $field => $pattern) {
            if (preg_match($pattern, $search) && preg_match('/inv[aá]lid|ausente|obrigat|informe|required|missing|must|necess[aá]rio/u', $search)) {
                $value = $customer[$field] ?? null;
                if ($field === 'document' && is_array($value)) {
                    $value = $value['number'] ?? null;
                }
                if ($customer !== [] || preg_match('/ausente|required|missing|obrigat/u', $search)) {
                    $specific = self::customerField($field, $value);
                    if ($specific !== null) {
                        return $specific;
                    }
                }
            }
        }
        // Monetary bounds must be explicit; never guess an amount or its unit.
        if (preg_match('/(?:m[aá]xim[oa]|maximum|at[eé]|n[aã]o pode (?:exceder|ultrapassar))[^;\n]{0,80}?R\$\s*([\d.]+(?:,\d{1,2})?)/iu', $message, $match)) {
            $cents = (int) round((float) str_replace(',', '.', str_replace('.', '', $match[1])) * 100);
            if ($cents > 0) {
                return self::amountLimit($cents, $acquirer);
            }
        }
        if (preg_match('/(?:m[ií]nim[oa]|minimum|inferior a)[^;\n]{0,80}?R\$\s*([\d.]+(?:,\d{1,2})?)/iu', $message, $match)) {
            $cents = (int) round((float) str_replace(',', '.', str_replace('.', '', $match[1])) * 100);
            if ($cents > 0) {
                return self::amountLimit($cents, $acquirer, false);
            }
        }
        if ($httpStatus === 429) {
            return 'Limite de solicitações da adquirente atingido. Aguarde antes de gerar outra cobrança.';
        }
        if (preg_match('/(?:valor|amount).*(?:m[aá]ximo|maximum|limit)|maximum.*amount|limite.*valor/iu', $message)) {
            return 'O valor da cobrança excede o limite da adquirente. O valor máximo não foi informado; consulte o suporte.';
        }
        if (in_array($httpStatus, [401, 403], true)) {
            return 'A adquirente não autorizou a emissão. Peça ao suporte para verificar as credenciais e permissões da conta.';
        }
        if ($httpStatus !== null && $httpStatus >= 500) {
            return 'A adquirente apresentou uma falha e não confirmou a emissão. Confira o status antes de tentar novamente.';
        }
        if ($message === '' || preg_match('/^(?:falha ao processar pagamento\.?\s*(?:tente novamente mais tarde\.?)?|unknown error|provider error|internal server error)$/iu', $message)) {
            return 'A adquirente não gerou a cobrança e não informou um motivo específico. Consulte o suporte.';
        }

        return mb_substr($message, 0, 500);
    }

    /** Preserve field names from nested validation errors (e.g. customer.phone). */
    public static function validationDetails(array $errors, string $prefix = ''): array
    {
        $messages = [];
        foreach ($errors as $key => $value) {
            $path = is_string($key) ? trim($prefix.'.'.$key, '.') : $prefix;
            if (is_array($value)) {
                array_push($messages, ...self::validationDetails($value, $path));
            } elseif (is_string($value)) {
                $messages[] = ($path !== '' ? $path.': ' : '').$value;
            }
        }

        return $messages;
    }
}
