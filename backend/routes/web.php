<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [\App\Http\Controllers\HealthController::class, 'index']);

Route::get('/favicon.ico', [\App\Http\Controllers\HealthController::class, 'favicon']);

// Serve storage files (with path traversal protection)
Route::get('/storage/{path}', [\App\Http\Controllers\HealthController::class, 'storage'])
    ->where('path', '.*');

// Public Landing Pages (URL amigável)
Route::get('/lp/{slug}', [\App\Http\Controllers\Api\V1\PublicLandingPageController::class, 'show'])
    ->name('landing-page.show');

// Landing Page Preview (works for draft/unpublished pages)
Route::get('/lp/{slug}/preview', [\App\Http\Controllers\Api\V1\PublicLandingPageController::class, 'preview'])
    ->name('landing-page.preview');

// Landing Page via Custom Domain (catch-all for custom domains)
Route::get('/lp-domain', [\App\Http\Controllers\Api\V1\PublicLandingPageController::class, 'showByDomain'])
    ->name('landing-page.domain');

// Public Checkout Page (URL amigável)
Route::get('/checkout/{slug}', [\App\Http\Controllers\Api\V1\PublicCheckoutPageController::class, 'show'])
    ->name('checkout-page.show');

require __DIR__.'/auth.php';
