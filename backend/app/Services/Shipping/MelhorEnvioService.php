<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cotação de frete via Melhor Envio.
 *
 * Só a cotação: nada aqui compra etiqueta nem move dinheiro. O token é lido da
 * configuração do servidor e nunca chega ao navegador — o checkout público
 * conversa com o nosso endpoint, que conversa com o Melhor Envio.
 *
 * @see https://docs.melhorenvio.com.br/
 */
class MelhorEnvioService
{
    private const TIMEOUT = 12;
    private const CACHE_MINUTES = 60;

    /** Limites da API; fora disso a cotação volta vazia sem explicação. */
    private const MIN_WEIGHT_KG = 0.1;
    private const MIN_DIMENSION_CM = 1;

    public function isConfigured(): bool
    {
        return !empty($this->token()) && !empty($this->originPostalCode());
    }

    /**
     * Cota o frete entre o CEP de origem configurado e o destino informado.
     *
     * @param array{weight?:float|int,width?:float|int,height?:float|int,length?:float|int} $package
     * @return array<int, array{id:int,name:string,company:string,price:int,delivery_time:int|null}>
     */
    public function quote(string $destinationPostalCode, array $package, int $insuranceCents = 0): array
    {
        $to = $this->onlyDigits($destinationPostalCode);
        $from = $this->onlyDigits($this->originPostalCode());

        if (!$this->isConfigured() || strlen($to) !== 8 || strlen($from) !== 8) {
            return [];
        }

        $body = [
            'from' => ['postal_code' => $from],
            'to' => ['postal_code' => $to],
            'package' => [
                // A API trabalha em kg e cm; valores abaixo do mínimo são recusados.
                'weight' => max(self::MIN_WEIGHT_KG, (float) ($package['weight'] ?? 0)),
                'width' => max(self::MIN_DIMENSION_CM, (int) ($package['width'] ?? 0)),
                'height' => max(self::MIN_DIMENSION_CM, (int) ($package['height'] ?? 0)),
                'length' => max(self::MIN_DIMENSION_CM, (int) ($package['length'] ?? 0)),
            ],
            'options' => [
                'insurance_value' => round($insuranceCents / 100, 2),
                'receipt' => false,
                'own_hand' => false,
            ],
        ];

        // Mesma origem, destino e pacote sempre dão o mesmo preço: evita bater
        // na API a cada tecla digitada no CEP e reduz latência do checkout.
        $cacheKey = 'melhorenvio:quote:' . md5(json_encode($body));

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($body) {
            return $this->request($body);
        });
    }

    private function request(array $body): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // O Melhor Envio exige User-Agent com contato; sem isso recusa.
                'User-Agent' => config('services.melhorenvio.user_agent', 'VectorPagamentos'),
            ])->timeout(self::TIMEOUT)->post($this->baseUrl() . '/api/v2/me/shipment/calculate', $body);

            if (!$response->successful()) {
                Log::warning('MelhorEnvio: cotacao recusada', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return [];
            }

            return $this->normalize($response->json() ?? []);
        } catch (\Throwable $e) {
            Log::warning('MelhorEnvio: falha na cotacao', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * A API devolve também serviços indisponíveis, marcados com `error`.
     * Esses são descartados para o cliente não ver opção que não dá para usar.
     */
    private function normalize(array $services): array
    {
        $options = [];

        foreach ($services as $service) {
            if (!empty($service['error'])) {
                continue;
            }

            $price = (float) ($service['custom_price'] ?? $service['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }

            $options[] = [
                'id' => (int) ($service['id'] ?? 0),
                'name' => (string) ($service['name'] ?? 'Entrega'),
                'company' => (string) ($service['company']['name'] ?? ''),
                // Centavos, para o checkout somar sem erro de ponto flutuante.
                'price' => (int) round($price * 100),
                'delivery_time' => isset($service['delivery_time']) ? (int) $service['delivery_time'] : null,
            ];
        }

        usort($options, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $options;
    }

    private function baseUrl(): string
    {
        return config('services.melhorenvio.sandbox')
            ? 'https://sandbox.melhorenvio.com.br'
            : 'https://melhorenvio.com.br';
    }

    private function token(): string
    {
        return (string) config('services.melhorenvio.token', '');
    }

    private function originPostalCode(): string
    {
        return (string) config('services.melhorenvio.from_postal_code', '');
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
