<?php

namespace App\Services;

use App\Events\LiveMapEventCreated;
use App\Models\Checkout;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LiveMapService
{
    private const MAX_EVENTS = 80;

    private const COUNTRY_POINTS = [
        'BR' => ['Brazil', -14.2350, -51.9253],
        'US' => ['United States', 39.8283, -98.5795],
        'PT' => ['Portugal', 39.3999, -8.2245],
        'AR' => ['Argentina', -38.4161, -63.6167],
        'CO' => ['Colombia', 4.5709, -74.2973],
        'MX' => ['Mexico', 23.6345, -102.5528],
        'CL' => ['Chile', -35.6751, -71.5430],
        'PE' => ['Peru', -9.1900, -75.0152],
        'GB' => ['United Kingdom', 55.3781, -3.4360],
        'ES' => ['Spain', 40.4637, -3.7492],
        'FR' => ['France', 46.2276, 2.2137],
        'DE' => ['Germany', 51.1657, 10.4515],
        'IT' => ['Italy', 41.8719, 12.5674],
        'CA' => ['Canada', 56.1304, -106.3468],
        'JP' => ['Japan', 36.2048, 138.2529],
    ];

    public function recordCheckoutAccess(
        Checkout $checkout,
        Request $request,
        string $kind = 'checkout',
        string $label = 'Checkout aberto',
        array $extra = []
    ): array {
        $accountId = (string) $checkout->account_id;
        $country = $this->countryFromRequest($request);
        [$countryName, $lat, $lng] = self::COUNTRY_POINTS[$country] ?? self::COUNTRY_POINTS['BR'];
        [$lat, $lng] = $this->jitterCoordinates($lat, $lng, $request->ip() ?? $checkout->id);

        $event = array_merge([
            'id' => (string) Str::uuid(),
            'kind' => $kind === 'api' ? 'api' : 'checkout',
            'label' => $label,
            'location' => $countryName . ', ' . $country,
            'country' => $country,
            'lat' => $lat,
            'lng' => $lng,
            'checkout_id' => (string) $checkout->id,
            'checkout_slug' => (string) $checkout->slug,
            'at' => now()->toIso8601String(),
        ], $extra);

        $events = $this->events($accountId);
        array_unshift($events, $event);
        $events = array_slice($events, 0, self::MAX_EVENTS);
        Cache::put($this->eventsKey($accountId), $events, now()->addHours(6));

        try {
            event(new LiveMapEventCreated($accountId, $event));
        } catch (\Throwable $e) {
            Log::debug('Live map broadcast skipped', ['error' => $e->getMessage()]);
        }

        return $event;
    }

    public function events(string $accountId): array
    {
        $events = Cache::get($this->eventsKey($accountId), []);
        return is_array($events) ? $events : [];
    }

    public function activeVisits(string $accountId): int
    {
        $cutoff = now()->subMinutes(5);

        return collect($this->events($accountId))
            ->filter(fn (array $event) => isset($event['at']) && Carbon::parse($event['at'])->greaterThanOrEqualTo($cutoff))
            ->count();
    }

    private function countryFromRequest(Request $request): string
    {
        $country = $request->headers->get('CF-IPCountry')
            ?: $request->headers->get('X-Vercel-IP-Country')
            ?: $request->headers->get('CloudFront-Viewer-Country')
            ?: $request->headers->get('X-Country-Code')
            ?: 'BR';

        $country = strtoupper(substr((string) $country, 0, 2));

        return preg_match('/^[A-Z]{2}$/', $country) ? $country : 'BR';
    }

    private function jitterCoordinates(float $lat, float $lng, string $seed): array
    {
        $hash = crc32($seed);
        $latOffset = (($hash % 1000) / 1000 - 0.5) * 3.8;
        $lngOffset = (((int) ($hash / 1000) % 1000) / 1000 - 0.5) * 5.2;

        return [
            round(max(min($lat + $latOffset, 85), -85), 4),
            round(max(min($lng + $lngOffset, 179), -179), 4),
        ];
    }

    private function eventsKey(string $accountId): string
    {
        return 'live_map_events:' . $accountId;
    }
}
