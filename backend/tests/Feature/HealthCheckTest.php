<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('returns 200 when all services are healthy', function () {
    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertJsonStructure(['status', 'database', 'cache', 'timestamp', 'service'])
        ->assertJson(['service' => 'wazerpay-api']);
});
