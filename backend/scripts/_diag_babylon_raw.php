<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

$token = Setting::get('babylon_token');
$baseUrl = Setting::get('babylon_base_url', 'https://api.babylonpg.com');

$response = Http::withHeaders([
    'Authorization' => "Bearer $token",
    'Accept' => 'application/json',
])->get("$baseUrl/api/v1/user/balance");

echo json_encode([
    'http_status' => $response->status(),
    'body' => $response->json() ?? $response->body(),
], JSON_PRETTY_PRINT);
