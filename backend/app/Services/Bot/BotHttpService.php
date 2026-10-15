<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chamada HTTP externa disparada por um no do fluxo.
 *
 * A URL vem do usuario, entao e superficie de SSRF: o destino e resolvido e
 * checado contra faixas privadas/reservadas antes de sair, e redirect fica
 * desligado para ninguem escapar via 302 para um IP interno.
 */
class BotHttpService
{
    private const TIMEOUT = 12;
    private const MAX_BODY = 20000;

    /**
     * @return array{success:bool, message:string, data:mixed, status:int|null}
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $method = strtoupper($method ?: 'GET');
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $this->fail('Método HTTP não suportado.');
        }

        $rejection = $this->rejectUnsafeUrl($url);
        if ($rejection !== null) {
            Log::warning('BotHttpService: destino bloqueado', ['url' => $url, 'reason' => $rejection]);

            return $this->fail($rejection);
        }

        try {
            $request = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT)
                ->withoutRedirecting();

            $response = match ($method) {
                'GET' => $request->get($url),
                'DELETE' => $request->delete($url),
                default => $request->withBody($body ?? '', $headers['Content-Type'] ?? 'application/json')->send($method, $url),
            };
        } catch (\Throwable $e) {
            Log::warning('BotHttpService: falha na chamada', ['url' => $url, 'error' => $e->getMessage()]);

            return $this->fail('Não consegui chamar o serviço externo.');
        }

        $raw = substr($response->body(), 0, self::MAX_BODY);
        $decoded = json_decode($raw, true);

        return [
            'success' => $response->successful(),
            'message' => $response->successful()
                ? 'Chamada concluída.'
                : "O serviço externo respondeu {$response->status()}.",
            'data' => json_last_error() === JSON_ERROR_NONE ? $decoded : $raw,
            'status' => $response->status(),
        ];
    }

    /**
     * Devolve o motivo da recusa, ou null se o destino for aceitavel.
     */
    private function rejectUnsafeUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return 'URL inválida.';
        }

        $scheme = strtolower((string) $parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'Só é permitido http ou https.';
        }

        if (app()->environment('production') && $scheme !== 'https') {
            return 'Em produção a URL precisa ser https.';
        }

        $host = strtolower((string) $parsed['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'], true)) {
            return 'Endereço interno não é permitido.';
        }

        // Um host pode ter varios IPs; basta um interno para ser recusado.
        $ips = $this->resolveAll($host);
        if (empty($ips)) {
            return 'Não consegui resolver o endereço.';
        }

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                return 'O endereço aponta para a rede interna.';
            }
        }

        return null;
    }

    /** @return string[] */
    private function resolveAll(string $host): array
    {
        // Host que ja e IP literal nao precisa de DNS.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => null, 'status' => null];
    }
}
