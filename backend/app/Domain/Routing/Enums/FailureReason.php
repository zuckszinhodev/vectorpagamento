<?php

declare(strict_types=1);

namespace App\Domain\Routing\Enums;

/**
 * Why an attempt failed. Drives three decisions:
 *   - retry the same acquirer? ({@see self::isRetryable()})
 *   - move on to the next acquirer? ({@see self::allowsFailover()})
 *   - count against the circuit breaker? ({@see self::countsAgainstCircuit()})
 *
 * A declined card is a legitimate answer from a healthy acquirer, so it must
 * neither trip the breaker nor be retried elsewhere.
 */
enum FailureReason: string
{
    case TIMEOUT = 'timeout';
    case CONNECTION_REFUSED = 'connection_refused';
    case NETWORK_ERROR = 'network_error';
    case PROVIDER_ERROR = 'provider_error';
    case PROVIDER_UNAVAILABLE = 'provider_unavailable';
    case RATE_LIMITED = 'rate_limited';
    case AUTHENTICATION_FAILED = 'authentication_failed';
    case INVALID_RESPONSE = 'invalid_response';
    case DECLINED = 'declined';
    case VALIDATION_ERROR = 'validation_error';
    case CIRCUIT_OPEN = 'circuit_open';
    case TPS_EXCEEDED = 'tps_exceeded';
    case NOT_CONFIGURED = 'not_configured';
    case UNKNOWN = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::TIMEOUT,
            self::CONNECTION_REFUSED,
            self::NETWORK_ERROR,
            self::PROVIDER_UNAVAILABLE,
            self::RATE_LIMITED => true,
            default => false,
        };
    }

    public function allowsFailover(): bool
    {
        return match ($this) {
            // The acquirer answered correctly — retrying elsewhere would be
            // double-charging risk or simply the same answer.
            self::DECLINED, self::VALIDATION_ERROR => false,
            default => true,
        };
    }

    public function countsAgainstCircuit(): bool
    {
        return match ($this) {
            self::DECLINED,
            self::VALIDATION_ERROR,
            self::CIRCUIT_OPEN,
            self::TPS_EXCEEDED => false,
            default => true,
        };
    }

    public function isTimeout(): bool
    {
        return $this === self::TIMEOUT;
    }

    /**
     * Best-effort classification of an arbitrary error message.
     */
    public static function fromThrowable(\Throwable $e): self
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => self::TIMEOUT,
            str_contains($message, 'connection refused'), str_contains($message, 'could not connect') => self::CONNECTION_REFUSED,
            str_contains($message, 'ssl'), str_contains($message, 'handshake'), str_contains($message, 'resolve host') => self::NETWORK_ERROR,
            str_contains($message, '401'), str_contains($message, 'unauthorized'), str_contains($message, 'forbidden') => self::AUTHENTICATION_FAILED,
            str_contains($message, '429'), str_contains($message, 'too many requests') => self::RATE_LIMITED,
            str_contains($message, '502'), str_contains($message, '503'), str_contains($message, '504'),
            str_contains($message, 'bad gateway'), str_contains($message, 'service unavailable') => self::PROVIDER_UNAVAILABLE,
            default => self::PROVIDER_ERROR,
        };
    }

    /**
     * Classification from a provider error string (used by the legacy adapter,
     * which returns arrays instead of throwing).
     */
    public static function fromMessage(?string $message): self
    {
        if ($message === null || trim($message) === '') {
            return self::UNKNOWN;
        }

        $normalized = strtolower($message);

        return match (true) {
            str_contains($normalized, 'timeout'), str_contains($normalized, 'timed out') => self::TIMEOUT,
            str_contains($normalized, 'connection refused') => self::CONNECTION_REFUSED,
            str_contains($normalized, 'ssl'), str_contains($normalized, 'handshake') => self::NETWORK_ERROR,
            str_contains($normalized, 'recusad'), str_contains($normalized, 'declined'), str_contains($normalized, 'insufficient') => self::DECLINED,
            str_contains($normalized, 'unauthorized'), str_contains($normalized, 'credenciais'), str_contains($normalized, 'api key') => self::AUTHENTICATION_FAILED,
            str_contains($normalized, 'not configured'), str_contains($normalized, 'nao configurad'), str_contains($normalized, 'não configurad') => self::NOT_CONFIGURED,
            str_contains($normalized, '502'), str_contains($normalized, '503'), str_contains($normalized, '504'),
            str_contains($normalized, 'bad gateway'), str_contains($normalized, 'service unavailable'),
            str_contains($normalized, 'indisponiv'), str_contains($normalized, 'indisponív') => self::PROVIDER_UNAVAILABLE,
            str_contains($normalized, 'invalid'), str_contains($normalized, 'inválid'), str_contains($normalized, 'obrigat') => self::VALIDATION_ERROR,
            default => self::PROVIDER_ERROR,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TIMEOUT => 'Timeout',
            self::CONNECTION_REFUSED => 'Conexão recusada',
            self::NETWORK_ERROR => 'Erro de rede',
            self::PROVIDER_ERROR => 'Erro da adquirente',
            self::PROVIDER_UNAVAILABLE => 'Adquirente indisponível',
            self::RATE_LIMITED => 'Limite de requisições',
            self::AUTHENTICATION_FAILED => 'Falha de autenticação',
            self::INVALID_RESPONSE => 'Resposta inválida',
            self::DECLINED => 'Recusado',
            self::VALIDATION_ERROR => 'Erro de validação',
            self::CIRCUIT_OPEN => 'Circuit breaker aberto',
            self::TPS_EXCEEDED => 'Limite de TPS excedido',
            self::NOT_CONFIGURED => 'Não configurada',
            self::UNKNOWN => 'Desconhecido',
        };
    }
}
