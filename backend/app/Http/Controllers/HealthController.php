<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = ['status' => 'ok'];

        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error';
            $checks['status'] = 'degraded';
        }

        try {
            Cache::store()->put('health_check', true, 5);
            $checks['cache'] = 'ok';
        } catch (\Throwable $e) {
            $checks['cache'] = 'error';
        }

        $checks['timestamp'] = now()->toIso8601String();
        $checks['service'] = 'wazerpay-api';

        $httpStatus = $checks['status'] === 'ok' ? 200 : 503;
        return response()->json($checks, $httpStatus);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'name' => 'GatePro Gateway',
            'version' => '1.0.0',
            'status' => 'online',
        ]);
    }

    public function favicon(): Response
    {
        return response('', 204);
    }

    public function storage(string $path)
    {
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            abort(403);
        }

        $fullPath = storage_path('app/public/' . $path);
        $realPath = realpath($fullPath);
        $storagePath = realpath(storage_path('app/public'));

        if (!$realPath || !$storagePath || !str_starts_with($realPath, $storagePath)) {
            abort(404);
        }

        $mimeType = mime_content_type($realPath) ?: 'application/octet-stream';

        return response()->file($realPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

}
