<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared route closures (avoid duplication between JWT and API-key groups)
|--------------------------------------------------------------------------
*/
$memberAreaRoutes = function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\MemberAreaController::class, 'show']);
    Route::put('/', [\App\Http\Controllers\Api\V1\MemberAreaController::class, 'update']);
    Route::get('/dashboard', [\App\Http\Controllers\Api\V1\MemberAreaController::class, 'dashboard']);
    Route::post('/check-subdomain', [\App\Http\Controllers\Api\V1\MemberAreaController::class, 'checkSubdomain']);
    Route::post('/verify-domain', [\App\Http\Controllers\Api\V1\MemberAreaController::class, 'verifyDomain']);

    // Domain verification
    Route::get('/domain/check', [\App\Http\Controllers\Api\V1\MemberDomainController::class, 'checkDomainVerification']);
    Route::post('/domain/generate-token', [\App\Http\Controllers\Api\V1\MemberDomainController::class, 'generateToken']);

    // Courses
    Route::prefix('courses')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'store']);
        Route::post('/reorder', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'reorder']);
        Route::get('/{courseId}', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'show']);
        Route::put('/{courseId}', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'update']);
        Route::delete('/{courseId}', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'destroy']);
        Route::post('/{courseId}/duplicate', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'duplicate']);
        Route::get('/{courseId}/students', [\App\Http\Controllers\Api\V1\MemberCourseController::class, 'students']);

        // Modules
        Route::prefix('{courseId}/modules')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\MemberModuleController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\MemberModuleController::class, 'store']);
            Route::post('/reorder', [\App\Http\Controllers\Api\V1\MemberModuleController::class, 'reorder']);
            Route::get('/{moduleId}', [\App\Http\Controllers\Api\V1\MemberModuleController::class, 'show']);
            Route::put('/{moduleId}', [\App\Http\Controllers\Api\V1\MemberModuleController::class, 'update']);
            Route::delete('/{moduleId}', [\App\Http\Controllers\Api\V1\MemberModuleController::class, 'destroy']);

            // Lessons
            Route::prefix('{moduleId}/lessons')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\V1\MemberLessonController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Api\V1\MemberLessonController::class, 'store']);
                Route::post('/reorder', [\App\Http\Controllers\Api\V1\MemberLessonController::class, 'reorder']);
                Route::get('/{lessonId}', [\App\Http\Controllers\Api\V1\MemberLessonController::class, 'show']);
                Route::put('/{lessonId}', [\App\Http\Controllers\Api\V1\MemberLessonController::class, 'update']);
                Route::delete('/{lessonId}', [\App\Http\Controllers\Api\V1\MemberLessonController::class, 'destroy']);
                Route::post('/{lessonId}/move', [\App\Http\Controllers\Api\V1\MemberLessonController::class, 'move']);
            });
        });
    });

    // Enrollments
    Route::prefix('enrollments')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'store']);
        Route::get('/{enrollmentId}', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'show']);
        Route::put('/{enrollmentId}', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'update']);
        Route::delete('/{enrollmentId}', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'destroy']);
        Route::post('/{enrollmentId}/extend', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'extend']);
        Route::post('/{enrollmentId}/reset-progress', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'resetProgress']);
        Route::post('/{enrollmentId}/reset-password', [\App\Http\Controllers\Api\V1\MemberEnrollmentController::class, 'resetMemberPassword']);
    });
};

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
*/

// Health check endpoint (comprehensive)
Route::get('/health', [\App\Http\Controllers\HealthController::class, 'check']);

Route::prefix('v1')->group(function () use ($memberAreaRoutes) {
    /*
    |--------------------------------------------------------------------------
    | Public Routes (No Authentication)
    |--------------------------------------------------------------------------
    */

    // Authentication - SECURITY: Strict rate limiting to prevent brute force attacks
    Route::prefix('auth')->middleware(['throttle:5,5', 'auth.strict_rate:login'])->group(function () {
        Route::post('/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register']);
        Route::post('/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);
        Route::post('/mfa/verify', [\App\Http\Controllers\Api\V1\AuthController::class, 'verifyMfa']);
        Route::post('/forgot-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'resetPassword']);
        Route::post('/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        Route::post('/refresh', [\App\Http\Controllers\Api\V1\AuthController::class, 'refresh']);
        Route::post('/google', [\App\Http\Controllers\Api\V1\AuthController::class, 'googleAuth']);
    });

    // Public Checkout (rate limited to prevent abuse)
    Route::prefix('checkout')->middleware('throttle:60,1')->group(function () {
        Route::get('/charge/{chargeId}/status', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'checkStatus']);
        Route::get('/{slug}', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'show']);
        Route::post('/{slug}/process', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'process']);
        Route::post('/{slug}/saved-cards', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'lookupSavedCards']);
        // Upsell flow (post-purchase)
        Route::get('/charge/{chargeId}/upsell', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'getUpsell']);
        Route::post('/charge/{chargeId}/upsell/{upsellId}/accept', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'acceptUpsell']);
        Route::post('/charge/{chargeId}/upsell/{upsellId}/decline', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'declineUpsell']);
    });

    // Custom Domain Checkout (para domínios personalizados)
    // Acessado via: https://pay.seudominio.com/api/v1/pay/{slug?}
    Route::prefix('pay')->middleware('resolve.domain')->group(function () {
        Route::get('/{slug?}', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'showByDomain']);
        Route::post('/{slug}/process', [\App\Http\Controllers\Api\V1\PublicCheckoutController::class, 'process']);
    });

    // Affiliate validation (public)
    Route::get('/affiliates/validate/{code}', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'findByCode']);

    // Coupon validation (public)
    Route::post('/coupons/validate', [\App\Http\Controllers\Api\V1\CouponController::class, 'validateCode']);

    // Marketplace (public - list products available for affiliation, rate limited)
    Route::prefix('marketplace')->middleware('throttle:30,1')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\V1\MarketplaceController::class, 'index']);
        Route::get('/products/{productId}', [\App\Http\Controllers\Api\V1\MarketplaceController::class, 'show']);
        Route::get('/categories', [\App\Http\Controllers\Api\V1\MarketplaceController::class, 'categories']);
        Route::get('/stats', [\App\Http\Controllers\Api\V1\MarketplaceController::class, 'stats']);
    });

    // Webhook Unificado (no auth, signature verification inside handler)
    // Use este único endpoint em todas as adquirentes:
    //   POST https://api.wazerpay.com.br/api/v1/webhook
    Route::post('webhook', [\App\Http\Controllers\Api\V1\WebhookController::class, 'unified'])
        ->middleware('throttle:120,1');

    // Webhooks por adquirente (legado/compatibilidade)
    Route::prefix('webhooks')->middleware('throttle:120,1')->group(function () {

        // ── Endpoints ativos (PayFort e HunterSub) ───────
        Route::post('/payfort', [\App\Http\Controllers\Api\V1\WebhookController::class, 'payfort']);
        Route::post('/huntersub', [\App\Http\Controllers\Api\V1\WebhookController::class, 'huntersub']);
        Route::post('/enki', [\App\Http\Controllers\Api\V1\WebhookController::class, 'enki']);
        
        // ── Endpoints customizados ───────
        Route::post('/custom/{integrationId}', [\App\Http\Controllers\Api\V1\WebhookController::class, 'custom']);
        Route::post('/n8n/{integrationId}', [\App\Http\Controllers\Api\V1\WebhookController::class, 'n8n']);
    });

    // Public Branding/Appearance (no auth)
    Route::get('/branding', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'getPublicBranding']);

    // KYC Document View (protected via signed URL with expiration)
    Route::get('/kyc/documents/{document}/view', [\App\Http\Controllers\Api\V1\KycController::class, 'viewDocument'])->name('kyc.document.view');

    // Public Landing Pages (no auth)
    Route::prefix('lp')->group(function () {
        Route::get('/{slug}', [\App\Http\Controllers\Api\V1\PublicLandingPageController::class, 'show']);
        Route::get('/{slug}/preview', [\App\Http\Controllers\Api\V1\PublicLandingPageController::class, 'preview']);
        Route::post('/{slug}/track/click', [\App\Http\Controllers\Api\V1\PublicLandingPageController::class, 'trackClick']);
        Route::post('/{slug}/track/conversion', [\App\Http\Controllers\Api\V1\PublicLandingPageController::class, 'trackConversion']);
    });

    /*
    |--------------------------------------------------------------------------
    | JWT or API Key Authenticated Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth.jwt_or_apikey', 'throttle:api', 'check.status', 'audit'])->group(function () use ($memberAreaRoutes) {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::get('/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
            Route::post('/change-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'changePassword']);
            Route::post('/email/send-verification', [\App\Http\Controllers\Api\V1\AuthController::class, 'sendEmailVerification']);
            Route::post('/email/verify', [\App\Http\Controllers\Api\V1\AuthController::class, 'verifyEmail']);
            Route::post('/mfa/enable', [\App\Http\Controllers\Api\V1\AuthController::class, 'enableMfa']);
            Route::post('/mfa/confirm', [\App\Http\Controllers\Api\V1\AuthController::class, 'confirmMfa']);
            Route::post('/mfa/disable', [\App\Http\Controllers\Api\V1\AuthController::class, 'disableMfa']);
            Route::post('/mfa/check', [\App\Http\Controllers\Api\V1\AuthController::class, 'checkMfa']);
        });

        // API Keys Management
        Route::prefix('api-keys')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'store']);
            Route::put('/{apiKeyId}', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'update']);
            Route::delete('/{apiKeyId}', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'destroy']);
            Route::post('/{apiKeyId}/rotate', [\App\Http\Controllers\Api\V1\ApiKeyController::class, 'rotate']);
        });

        // Account Management
        Route::prefix('account')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AccountController::class, 'show']);
            Route::put('/', [\App\Http\Controllers\Api\V1\AccountController::class, 'update']);
            Route::get('/wallet', [\App\Http\Controllers\Api\V1\AccountController::class, 'wallet']);
            Route::get('/transactions', [\App\Http\Controllers\Api\V1\AccountController::class, 'transactions']);
            Route::get('/payment-info', [\App\Http\Controllers\Api\V1\AccountController::class, 'getPaymentInfo']);
            Route::put('/payment-info', [\App\Http\Controllers\Api\V1\AccountController::class, 'savePaymentInfo']);
            Route::get('/dashboard-customization', [\App\Http\Controllers\Api\V1\AccountController::class, 'getDashboardCustomization']);
            Route::put('/dashboard-customization', [\App\Http\Controllers\Api\V1\AccountController::class, 'saveDashboardCustomization']);
            Route::get('/withdrawal-ips', [\App\Http\Controllers\Api\V1\AccountController::class, 'getWithdrawalIpWhitelist']);
            Route::put('/withdrawal-ips', [\App\Http\Controllers\Api\V1\AccountController::class, 'updateWithdrawalIpWhitelist']);
        });

        // Users Management (admin only)
        Route::prefix('users')->middleware(['role:admin,owner', 'mfa.enabled', 'admin.audit', 'admin.permission:admin.users.manage', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\UserController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\UserController::class, 'store']);
            Route::get('/{userId}', [\App\Http\Controllers\Api\V1\UserController::class, 'show']);
            Route::put('/{userId}', [\App\Http\Controllers\Api\V1\UserController::class, 'update']);
            Route::delete('/{userId}', [\App\Http\Controllers\Api\V1\UserController::class, 'destroy']);
        });

        // Dashboard Products (JWT auth or API Key)
        Route::prefix('products')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\ProductController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\ProductController::class, 'store'])->middleware('api.permission:products:write');
            Route::get('/{productId}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show']);
            Route::put('/{productId}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update'])->middleware('api.permission:products:write');
            Route::delete('/{productId}', [\App\Http\Controllers\Api\V1\ProductController::class, 'destroy'])->middleware('api.permission:products:write');
        });

        // Dashboard Checkouts (JWT auth or API Key)
        Route::prefix('checkouts')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'store'])->middleware('api.permission:checkouts:write');
            Route::get('/{checkoutId}', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'show']);
            Route::put('/{checkoutId}', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'update'])->middleware('api.permission:checkouts:write');
            Route::delete('/{checkoutId}', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'destroy'])->middleware('api.permission:checkouts:write');
            Route::post('/{checkoutId}/clone', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'clone'])->middleware('api.permission:checkouts:write');
            Route::get('/{checkoutId}/analytics', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'analytics']);

            // Order Bumps (nested under checkouts)
            Route::prefix('{checkoutId}/order-bumps')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\V1\OrderBumpController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Api\V1\OrderBumpController::class, 'store'])->middleware('api.permission:checkouts:write');
                Route::post('/reorder', [\App\Http\Controllers\Api\V1\OrderBumpController::class, 'reorder'])->middleware('api.permission:checkouts:write');
                Route::get('/{bumpId}', [\App\Http\Controllers\Api\V1\OrderBumpController::class, 'show']);
                Route::put('/{bumpId}', [\App\Http\Controllers\Api\V1\OrderBumpController::class, 'update'])->middleware('api.permission:checkouts:write');
                Route::delete('/{bumpId}', [\App\Http\Controllers\Api\V1\OrderBumpController::class, 'destroy'])->middleware('api.permission:checkouts:write');
            });

            // Upsells (nested under checkouts)
            Route::prefix('{checkoutId}/upsells')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\V1\UpsellController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Api\V1\UpsellController::class, 'store'])->middleware('api.permission:checkouts:write');
                Route::post('/reorder', [\App\Http\Controllers\Api\V1\UpsellController::class, 'reorder'])->middleware('api.permission:checkouts:write');
                Route::get('/{upsellId}', [\App\Http\Controllers\Api\V1\UpsellController::class, 'show']);
                Route::put('/{upsellId}', [\App\Http\Controllers\Api\V1\UpsellController::class, 'update'])->middleware('api.permission:checkouts:write');
                Route::delete('/{upsellId}', [\App\Http\Controllers\Api\V1\UpsellController::class, 'destroy'])->middleware('api.permission:checkouts:write');
            });
        });

        // Dashboard Charges (JWT auth or API Key)
        Route::prefix('charges')->middleware('idempotency')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\ChargeController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\ChargeController::class, 'store'])->middleware('api.permission:charges:write');
            Route::post('/sync-all', [\App\Http\Controllers\Api\V1\ChargeController::class, 'syncAllPending'])->middleware('api.permission:charges:write');
            Route::get('/{chargeId}', [\App\Http\Controllers\Api\V1\ChargeController::class, 'show']);
            Route::post('/{chargeId}/cancel', [\App\Http\Controllers\Api\V1\ChargeController::class, 'cancel'])->middleware('api.permission:charges:write');
            Route::post('/{chargeId}/refund', [\App\Http\Controllers\Api\V1\ChargeController::class, 'refund'])->middleware('api.permission:charges:write');
            Route::post('/{chargeId}/sync', [\App\Http\Controllers\Api\V1\ChargeController::class, 'syncStatus'])->middleware('api.permission:charges:write');
            Route::delete('/{chargeId}', [\App\Http\Controllers\Api\V1\ChargeController::class, 'destroy'])->middleware('api.permission:charges:write');
        });

        // Dashboard Affiliates (JWT auth)
        Route::prefix('affiliates')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'store']);
            Route::post('/invite', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'invite']);
            Route::get('/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'show']);
            Route::put('/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'update']);
            Route::delete('/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'destroy']);
            Route::get('/{affiliateId}/stats', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'stats']);
        });

        // User Webhooks (JWT auth or API Key)
        Route::prefix('webhooks')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\UserWebhookController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\UserWebhookController::class, 'store'])->middleware('api.permission:webhooks:write');
            Route::put('/{webhookId}', [\App\Http\Controllers\Api\V1\UserWebhookController::class, 'update'])->middleware('api.permission:webhooks:write');
            Route::delete('/{webhookId}', [\App\Http\Controllers\Api\V1\UserWebhookController::class, 'destroy'])->middleware('api.permission:webhooks:write');
            Route::post('/{webhookId}/regenerate-secret', [\App\Http\Controllers\Api\V1\UserWebhookController::class, 'regenerateSecret'])->middleware('api.permission:webhooks:write');
        });

        // Webhook Logs (JWT auth)
        Route::get('/webhook-logs', [\App\Http\Controllers\Api\V1\UserWebhookController::class, 'logs']);
        Route::post('/webhook-logs/{attemptId}/retry', [\App\Http\Controllers\Api\V1\UserWebhookController::class, 'retryAttempt']);

        // Saved Cards (fast checkout)
        Route::get('/saved-cards', [\App\Http\Controllers\Api\V1\SavedCardController::class, 'index']);
        Route::delete('/saved-cards/{id}', [\App\Http\Controllers\Api\V1\SavedCardController::class, 'destroy']);

        // Dashboard Reports (JWT auth)
        Route::prefix('reports')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\ReportController::class, 'dashboard']);
            Route::get('/transactions', [\App\Http\Controllers\Api\V1\ReportController::class, 'transactions']);
            Route::get('/revenue', [\App\Http\Controllers\Api\V1\ReportController::class, 'revenue']);
            Route::get('/products', [\App\Http\Controllers\Api\V1\ReportController::class, 'products']);
            Route::get('/affiliates', [\App\Http\Controllers\Api\V1\ReportController::class, 'affiliates']);
            Route::post('/generate', [\App\Http\Controllers\Api\V1\ReportController::class, 'generate']);
            Route::get('/export/{type}', [\App\Http\Controllers\Api\V1\ReportController::class, 'export']);
            Route::get('/download/{filename}', [\App\Http\Controllers\Api\V1\ReportController::class, 'download']);
        });

        // Dashboard Coupons (JWT auth)
        Route::prefix('coupons')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\CouponController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\CouponController::class, 'store']);
            Route::get('/{couponId}', [\App\Http\Controllers\Api\V1\CouponController::class, 'show']);
            Route::put('/{couponId}', [\App\Http\Controllers\Api\V1\CouponController::class, 'update']);
            Route::delete('/{couponId}', [\App\Http\Controllers\Api\V1\CouponController::class, 'destroy']);
        });

        // Dashboard Analytics (JWT auth)
        Route::get('/analytics', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'index']);

        // Dashboard Withdrawals (JWT auth)
        Route::prefix('withdrawals')->middleware('idempotency')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'store']);
            Route::get('/summary', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'summary']);
            Route::get('/settings', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'getSettings']);
            Route::get('/{withdrawalId}', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'show']);
            Route::post('/{withdrawalId}/cancel', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'cancel']);
        });

        // Custom Domains (JWT auth)
        Route::prefix('domains')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\DomainController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\DomainController::class, 'store']);
            Route::get('/{domainId}', [\App\Http\Controllers\Api\V1\DomainController::class, 'show']);
            Route::delete('/{domainId}', [\App\Http\Controllers\Api\V1\DomainController::class, 'destroy']);
            Route::post('/{domainId}/verify', [\App\Http\Controllers\Api\V1\DomainController::class, 'verify']);
            Route::post('/{domainId}/primary', [\App\Http\Controllers\Api\V1\DomainController::class, 'setPrimary']);
            Route::put('/{domainId}/use-for', [\App\Http\Controllers\Api\V1\DomainController::class, 'updateUseFor']);
            Route::get('/{domainId}/dns-instructions', [\App\Http\Controllers\Api\V1\DomainController::class, 'getDnsInstructions']);
        });

        // KYC (Know Your Customer) - User verification
        Route::prefix('kyc')->group(function () {
            Route::get('/status', [\App\Http\Controllers\Api\V1\KycController::class, 'status']);
            Route::post('/submit', [\App\Http\Controllers\Api\V1\KycController::class, 'submit']);
            Route::post('/documents', [\App\Http\Controllers\Api\V1\KycController::class, 'uploadDocument']);
            Route::delete('/documents/{documentId}', [\App\Http\Controllers\Api\V1\KycController::class, 'deleteDocument']);
            Route::post('/documents/{document}/signed-url', [\App\Http\Controllers\Api\V1\KycController::class, 'generateSignedUrl']);
            Route::post('/submit-for-review', [\App\Http\Controllers\Api\V1\KycController::class, 'submitForReview']);
        });

        // File Uploads (JWT auth)
        Route::prefix('upload')->group(function () {
            Route::post('/product-image', [\App\Http\Controllers\Api\V1\UploadController::class, 'productImage']);
            Route::post('/', [\App\Http\Controllers\Api\V1\UploadController::class, 'upload']);
            Route::delete('/', [\App\Http\Controllers\Api\V1\UploadController::class, 'delete']);
        });

        // Notifications (JWT auth)
        Route::prefix('notifications')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\NotificationController::class, 'index']);
            Route::get('/unread-count', [\App\Http\Controllers\Api\V1\NotificationController::class, 'unreadCount']);
            Route::post('/read-all', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAllAsRead']);
            Route::delete('/clear-read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'clearRead']);
            Route::post('/{notificationId}/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAsRead']);
            Route::delete('/{notificationId}', [\App\Http\Controllers\Api\V1\NotificationController::class, 'destroy']);
        });

        // Admin: KYC Management - SECURITY: Added admin audit logging
        Route::middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::prefix('admin/kyc')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\V1\KycController::class, 'adminList']);
                Route::get('/{submissionId}', [\App\Http\Controllers\Api\V1\KycController::class, 'adminShow']);
                Route::get('/{submissionId}/documents', [\App\Http\Controllers\Api\V1\KycController::class, 'adminDocuments']);
                Route::post('/{submissionId}/approve', [\App\Http\Controllers\Api\V1\KycController::class, 'adminApprove']);
                Route::post('/{submissionId}/reject', [\App\Http\Controllers\Api\V1\KycController::class, 'adminReject']);
                Route::post('/documents/{document}/signed-url', [\App\Http\Controllers\Api\V1\KycController::class, 'generateSignedUrl']);
            });

            // Admin: System Stats - SECURITY: Added admin audit logging
            Route::get('admin/stats', [\App\Http\Controllers\Api\V1\AdminController::class, 'systemStats']);

            // Admin: Operational Pulse (fintech control strip)
            Route::get('admin/pulse', [\App\Http\Controllers\Api\V1\AdminController::class, 'operationsPulse']);

            // Admin: Revenue Chart Data
            Route::get('admin/chart/revenue', [\App\Http\Controllers\Api\V1\AdminController::class, 'revenueChart']);

            // Admin: Payment Methods Stats
            Route::get('admin/payment-methods', [\App\Http\Controllers\Api\V1\AdminController::class, 'paymentMethodsStats']);

            // Admin: Recent Activities - SECURITY: Added admin audit logging
            Route::get('admin/activities', [\App\Http\Controllers\Api\V1\AdminController::class, 'recentActivities']);

            // Admin: Withdrawal Management - SECURITY: Added admin audit logging
            Route::prefix('admin/withdrawals')->group(function () {
                Route::get('/config', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'getWithdrawalConfig']);
                Route::put('/config', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'updateWithdrawalConfig']);
                Route::get('/', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'adminList']);
                Route::get('/{withdrawalId}', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'adminShow']);
                Route::get('/{withdrawalId}/logs', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'adminLogs']);
                Route::put('/{withdrawalId}', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'adminUpdate']);
                Route::post('/{withdrawalId}/cancel', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'adminCancel']);
                Route::post('/{withdrawalId}/process', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'adminProcess']);
                Route::post('/process-all', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'adminProcessAll']);
            });

            // Admin: All Charges/Transactions
            Route::get('admin/charges', [\App\Http\Controllers\Api\V1\AdminController::class, 'allCharges']);

            // Admin: Control Tower advanced operations
            Route::prefix('admin/control-tower')->group(function () {
                Route::get('/sla', [\App\Http\Controllers\Api\V1\AdminController::class, 'acquirerSla']);
                Route::get('/risk-matrix', [\App\Http\Controllers\Api\V1\AdminController::class, 'sellerRiskMatrix']);
                Route::post('/risk-actions/bulk', [\App\Http\Controllers\Api\V1\AdminController::class, 'sellerRiskBulkAction']);
                Route::post('/playbooks/execute', [\App\Http\Controllers\Api\V1\AdminController::class, 'executeIncidentPlaybook']);
            });
        });

        // Admin: Customer Management - SECURITY: Added admin audit logging
        Route::prefix('admin/customers')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'index']);
            Route::get('/{customerId}', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'show']);
            Route::put('/{customerId}/status', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'updateStatus']);
            Route::delete('/{customerId}', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'destroy']);
            Route::get('/{customerId}/fees', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'getCustomFees']);
            Route::put('/{customerId}/fees', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'updateCustomFees']);
            Route::get('/{customerId}/charges', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'listCharges']);
            Route::get('/{customerId}/charges/{chargeId}', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'showCharge']);
            Route::post('/{customerId}/charges/{chargeId}/status', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'updateChargeStatus']);
            Route::put('/{customerId}/payment-info', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'updatePaymentInfo']);
            Route::post('/{customerId}/recalculate-balance', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'recalculateBalance']);
            Route::get('/{customerId}/acquirers', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'getAccountAcquirers']);
            Route::put('/{customerId}/acquirers', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'updateAccountAcquirers']);
            Route::post('/{customerId}/impersonate', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'impersonate']);
            Route::get('/{customerId}/withdrawals', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'listWithdrawals']);
            Route::get('/{customerId}/audit-logs', [\App\Http\Controllers\Api\V1\CustomerAdminController::class, 'listAuditLogs']);
        });

        // Admin: Acquirers Management - SECURITY: Added admin audit logging
        Route::prefix('admin/acquirers')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'index']);
            Route::get('/routing/settings', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'getRoutingSettings']);
            Route::put('/routing/settings', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'updateRoutingSettings']);
            Route::get('/routing/preview', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'previewRouting']);
            Route::put('/reorder', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'reorder']);
            Route::get('/{acquirerId}', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'show']);
            Route::put('/{acquirerId}', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'update']);
            Route::delete('/{acquirerId}', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'destroy']);
            Route::post('/{acquirerId}/set-primary', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'setPrimary']);
            Route::post('/{acquirerId}/toggle', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'toggle']);
            Route::post('/{acquirerId}/health-check', [\App\Http\Controllers\Api\V1\AcquirerAdminController::class, 'healthCheck']);
        });

        // Admin: System Config - SECURITY: Added admin audit logging
        Route::prefix('admin/system-config')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'getSystemConfig']);
            Route::put('/', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'updateSystemConfig']);
        });

        // Admin: Platform Settings - Settings page configuration
        Route::prefix('admin/settings')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\PlatformSettingsAdminController::class, 'index']);
            Route::put('/', [\App\Http\Controllers\Api\V1\PlatformSettingsAdminController::class, 'update']);
        });

        // Admin: API Keys Management
        Route::prefix('admin/api-keys')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission:admin.config.manage', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\PlatformSettingsAdminController::class, 'apiKeys']);
            Route::post('/', [\App\Http\Controllers\Api\V1\PlatformSettingsAdminController::class, 'createApiKey']);
            Route::delete('/{keyId}', [\App\Http\Controllers\Api\V1\PlatformSettingsAdminController::class, 'deleteApiKey']);
        });

        // Admin: Global Webhooks - SECURITY: Added admin audit logging
        Route::prefix('admin/webhooks')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'listGlobalWebhooks']);
            Route::post('/', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'createGlobalWebhook']);
            Route::put('/{webhookId}', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'updateGlobalWebhook']);
            Route::delete('/{webhookId}', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'deleteGlobalWebhook']);
            Route::post('/{webhookId}/regenerate-secret', [\App\Http\Controllers\Api\V1\SystemConfigAdminController::class, 'regenerateWebhookSecret']);
        });

        // Admin: Notifications - SECURITY: Added admin audit logging
        Route::prefix('admin/notifications')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::post('/send', [\App\Http\Controllers\Api\V1\NotificationController::class, 'adminSend']);
            Route::get('/', [\App\Http\Controllers\Api\V1\NotificationController::class, 'adminList']);
            Route::get('/search-users', [\App\Http\Controllers\Api\V1\NotificationController::class, 'adminSearchUsers']);
            Route::delete('/clear', [\App\Http\Controllers\Api\V1\NotificationController::class, 'adminClear']);
        });

        // Admin: Fraud Management - SECURITY: Added admin audit logging
        Route::prefix('admin/fraud')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'dashboard']);
            Route::get('/risk-scores', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'riskScores']);
            Route::get('/risk-scores/{riskScoreId}', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'riskScoreDetail']);
            Route::get('/devices', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'devices']);
            Route::put('/devices/{fingerprintId}/trust', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'updateDeviceTrust']);

            // Blacklist CRUD
            Route::get('/blacklist', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'blacklistIndex']);
            Route::post('/blacklist', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'blacklistStore']);
            Route::put('/blacklist/{blacklistId}', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'blacklistUpdate']);
            Route::delete('/blacklist/{blacklistId}', [\App\Http\Controllers\Api\V1\FraudAdminController::class, 'blacklistDestroy']);
        });

        // Admin: All Products Management (all accounts) - SECURITY: Added admin audit logging
        Route::prefix('admin/products')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Admin\ShowcaseController::class, 'allProducts']);
        });

        // Admin: Showcase Products Management - SECURITY: Added admin audit logging
        Route::prefix('admin/showcase')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/settings', [\App\Http\Controllers\Api\V1\Admin\ShowcaseController::class, 'getSettings']);
            Route::post('/settings', [\App\Http\Controllers\Api\V1\Admin\ShowcaseController::class, 'updateSettings']);
            Route::prefix('products')->group(function () {
                Route::get('/', [\App\Http\Controllers\Api\V1\Admin\ShowcaseController::class, 'index']);
                Route::post('/{productId}/approve', [\App\Http\Controllers\Api\V1\Admin\ShowcaseController::class, 'approve']);
                Route::post('/{productId}/reject', [\App\Http\Controllers\Api\V1\Admin\ShowcaseController::class, 'reject']);
                Route::delete('/{productId}', [\App\Http\Controllers\Api\V1\Admin\ShowcaseController::class, 'destroy']);
            });
        });

        // User: Chargebacks - View own chargebacks and submit evidence
        Route::prefix('chargebacks')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'userIndex']);
            Route::get('/{chargebackId}', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'userShow']);
            Route::post('/{chargebackId}/evidence', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'submitEvidence']);
        });

        // Admin: Chargebacks - SECURITY: Added admin audit logging
        Route::prefix('admin/chargebacks')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'dashboard']);
            Route::get('/', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'store']);
            Route::get('/{chargebackId}', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'show']);
            Route::post('/{chargebackId}/evidence', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'submitEvidence']);
            Route::post('/{chargebackId}/resolve', [\App\Http\Controllers\Api\V1\ChargebackController::class, 'resolve']);
        });

        // Admin: Reconciliation - SECURITY: Added admin audit logging
        Route::prefix('admin/reconciliation')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\ReconciliationController::class, 'dashboard']);
            Route::get('/', [\App\Http\Controllers\Api\V1\ReconciliationController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\ReconciliationController::class, 'store']);
            Route::get('/{reconciliationId}', [\App\Http\Controllers\Api\V1\ReconciliationController::class, 'show']);
        });

        // Admin: N8N Delivery Logs - SECURITY: Added admin audit logging
        Route::prefix('admin/n8n-logs')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission:admin.security.monitor', 'admin.rate'])->group(function () {
            Route::get('/stats', [\App\Http\Controllers\Api\V1\N8nDeliveryLogController::class, 'stats']);
            Route::get('/', [\App\Http\Controllers\Api\V1\N8nDeliveryLogController::class, 'index']);
            Route::get('/{logId}', [\App\Http\Controllers\Api\V1\N8nDeliveryLogController::class, 'show']);
        });

        // ═══════════════════════════════════════════════════════════════════
        // Admin: Security Monitoring - FULL SECURITY MANAGEMENT
        // ═══════════════════════════════════════════════════════════════════
        Route::prefix('admin/security')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            // Stats & Threats (SecurityAdminController)
            Route::get('/stats', [\App\Http\Controllers\Api\V1\SecurityAdminController::class, 'stats']);
            Route::get('/threats', [\App\Http\Controllers\Api\V1\SecurityAdminController::class, 'threats']);
            Route::post('/threats/{threatId}/block', [\App\Http\Controllers\Api\V1\SecurityAdminController::class, 'blockThreat']);
            Route::post('/threats/{threatId}/dismiss', [\App\Http\Controllers\Api\V1\SecurityAdminController::class, 'dismissThreat']);
            Route::get('/blocklist', [\App\Http\Controllers\Api\V1\SecurityAdminController::class, 'blocklist']);
            Route::post('/blocklist', [\App\Http\Controllers\Api\V1\SecurityAdminController::class, 'addToBlocklist']);
            Route::delete('/blocklist/{blocklistId}', [\App\Http\Controllers\Api\V1\SecurityAdminController::class, 'removeFromBlocklist']);
            
            // Dashboard & Overview (SecurityMonitorController)
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'dashboard']);
            Route::get('/live', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'liveActivity']);
            
            // Security Logs
            Route::get('/logs', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'logs']);
            Route::get('/admin-logs', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'adminLogs']);
            Route::get('/failed-logins', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'failedLogins']);
            Route::get('/export', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'exportLogs']);
            
            // Alerts
            Route::get('/alerts', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'alerts']);
            Route::post('/alerts/{alertId}/acknowledge', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'acknowledgeAlert']);
            
            // IP Management
            Route::get('/blocked-ips', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'blockedIps']);
            Route::post('/block-ip', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'blockIp']);
            Route::delete('/unblock-ip/{ipOrId}', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'unblockIp']);
            Route::get('/ip/{ip}', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'ipDetails']);
            Route::get('/rate-limit-status', [\App\Http\Controllers\Api\V1\SecurityMonitorController::class, 'rateLimitStatus']);
        });

        // Admin: Dual Control Approvals Queue
        Route::prefix('admin/approvals')->middleware(['role:admin,owner,support,financial,antifraud', 'admin.audit', 'mfa.enabled', 'admin.permission', 'admin.rate'])->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AdminActionApprovalController::class, 'index']);
            Route::post('/{approvalId}/approve', [\App\Http\Controllers\Api\V1\AdminActionApprovalController::class, 'approve']);
            Route::post('/{approvalId}/reject', [\App\Http\Controllers\Api\V1\AdminActionApprovalController::class, 'reject']);
        });

        // Split Config (user's own split settings)
        Route::prefix('splits')->group(function () {
            Route::get('/config', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'show']);
            Route::put('/config', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'update']);
            Route::get('/charges/{chargeId}', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'chargesSplits']);

            // Split Rules (automatic split configuration)
            Route::get('/rules', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'indexRules']);
            Route::post('/rules', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'storeRule']);
            Route::get('/rules/{ruleId}', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'showRule']);
            Route::put('/rules/{ruleId}', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'updateRule']);
            Route::delete('/rules/{ruleId}', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'destroyRule']);
            Route::post('/rules/{ruleId}/simulate', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'simulateRule']);
            Route::post('/process-pending', [\App\Http\Controllers\Api\V1\SplitConfigController::class, 'processPending']);
        });

        // N8n Integrations (JWT auth)
        Route::prefix('n8n')->group(function () {
            Route::get('/events', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'events']);
            Route::get('/', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'store']);
            Route::get('/{integrationId}', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'show']);
            Route::put('/{integrationId}', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'update']);
            Route::delete('/{integrationId}', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'destroy']);
            Route::post('/{integrationId}/test', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'test']);
            Route::post('/{integrationId}/toggle', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'toggle']);
            Route::post('/{integrationId}/regenerate-secret', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'regenerateSecret']);
            Route::post('/{integrationId}/trigger', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'trigger']);
            Route::get('/{integrationId}/logs', [\App\Http\Controllers\Api\V1\N8nIntegrationController::class, 'logs']);
        });

        // UTMify Integration (JWT auth)
        Route::prefix('integrations/utmify')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\UtmifyIntegrationController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Api\V1\UtmifyIntegrationController::class, 'store']);
            Route::put('/toggle', [\App\Http\Controllers\Api\V1\UtmifyIntegrationController::class, 'toggle']);
            Route::delete('/', [\App\Http\Controllers\Api\V1\UtmifyIntegrationController::class, 'destroy']);
            Route::post('/test', [\App\Http\Controllers\Api\V1\UtmifyIntegrationController::class, 'test']);
        });

        // Facebook Pixel / Conversions API Integration (JWT auth)
        Route::prefix('integrations/facebook-pixel')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\FacebookPixelIntegrationController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Api\V1\FacebookPixelIntegrationController::class, 'store']);
            Route::put('/toggle', [\App\Http\Controllers\Api\V1\FacebookPixelIntegrationController::class, 'toggle']);
            Route::put('/events', [\App\Http\Controllers\Api\V1\FacebookPixelIntegrationController::class, 'updateEvents']);
            Route::delete('/', [\App\Http\Controllers\Api\V1\FacebookPixelIntegrationController::class, 'destroy']);
            Route::post('/test', [\App\Http\Controllers\Api\V1\FacebookPixelIntegrationController::class, 'test']);
        });

        // XTracky Integration (JWT auth)
        Route::prefix('integrations/xtracky')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\XtrackyIntegrationController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Api\V1\XtrackyIntegrationController::class, 'store']);
            Route::put('/toggle', [\App\Http\Controllers\Api\V1\XtrackyIntegrationController::class, 'toggle']);
            Route::delete('/', [\App\Http\Controllers\Api\V1\XtrackyIntegrationController::class, 'destroy']);
            Route::post('/test', [\App\Http\Controllers\Api\V1\XtrackyIntegrationController::class, 'test']);
        });

        // TikTok Pixel Integration (JWT auth)
        Route::prefix('integrations/tiktok-pixel')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\TiktokPixelIntegrationController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Api\V1\TiktokPixelIntegrationController::class, 'store']);
            Route::put('/toggle', [\App\Http\Controllers\Api\V1\TiktokPixelIntegrationController::class, 'toggle']);
            Route::delete('/', [\App\Http\Controllers\Api\V1\TiktokPixelIntegrationController::class, 'destroy']);
        });

        // Google Analytics GA4 Integration (JWT auth)
        Route::prefix('integrations/google-analytics')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\GoogleAnalyticsIntegrationController::class, 'show']);
            Route::post('/', [\App\Http\Controllers\Api\V1\GoogleAnalyticsIntegrationController::class, 'store']);
            Route::put('/toggle', [\App\Http\Controllers\Api\V1\GoogleAnalyticsIntegrationController::class, 'toggle']);
            Route::delete('/', [\App\Http\Controllers\Api\V1\GoogleAnalyticsIntegrationController::class, 'destroy']);
        });

        // Messaging (SMS & WhatsApp) Integration
        Route::prefix('messaging')->group(function () {
            // Stats
            Route::get('/stats', [\App\Http\Controllers\Api\V1\MessagingController::class, 'stats']);

            // Providers
            Route::get('/providers', [\App\Http\Controllers\Api\V1\MessagingController::class, 'indexProviders']);
            Route::post('/providers', [\App\Http\Controllers\Api\V1\MessagingController::class, 'storeProvider']);
            Route::post('/providers/auto-setup', [\App\Http\Controllers\Api\V1\MessagingController::class, 'autoSetupProvider']);
            Route::put('/providers/{id}', [\App\Http\Controllers\Api\V1\MessagingController::class, 'updateProvider']);
            Route::delete('/providers/{id}', [\App\Http\Controllers\Api\V1\MessagingController::class, 'destroyProvider']);
            Route::post('/providers/{id}/test', [\App\Http\Controllers\Api\V1\MessagingController::class, 'testProvider']);
            Route::post('/providers/{id}/send-test', [\App\Http\Controllers\Api\V1\MessagingController::class, 'sendTestMessage']);

            // WhatsApp Connection Management
            Route::get('/whatsapp/qrcode', [\App\Http\Controllers\Api\V1\MessagingController::class, 'whatsappQrCode']);
            Route::get('/whatsapp/status', [\App\Http\Controllers\Api\V1\MessagingController::class, 'whatsappStatus']);
            Route::post('/whatsapp/disconnect', [\App\Http\Controllers\Api\V1\MessagingController::class, 'whatsappDisconnect']);
            Route::post('/whatsapp/restart', [\App\Http\Controllers\Api\V1\MessagingController::class, 'whatsappRestart']);

            // Templates
            Route::get('/templates', [\App\Http\Controllers\Api\V1\MessagingController::class, 'indexTemplates']);
            Route::get('/templates/variables', [\App\Http\Controllers\Api\V1\MessagingController::class, 'templateVariables']);
            Route::get('/templates/triggers', [\App\Http\Controllers\Api\V1\MessagingController::class, 'templateTriggers']);
            Route::post('/templates', [\App\Http\Controllers\Api\V1\MessagingController::class, 'storeTemplate']);
            Route::put('/templates/{id}', [\App\Http\Controllers\Api\V1\MessagingController::class, 'updateTemplate']);
            Route::delete('/templates/{id}', [\App\Http\Controllers\Api\V1\MessagingController::class, 'destroyTemplate']);

            // Logs
            Route::get('/logs', [\App\Http\Controllers\Api\V1\MessagingController::class, 'indexLogs']);
        });

        // Marketplace (authenticated - affiliate to products)
        Route::prefix('marketplace')->group(function () {
            Route::post('/products/{productId}/affiliate', [\App\Http\Controllers\Api\V1\MarketplaceController::class, 'affiliate']);
            Route::get('/my-affiliations', [\App\Http\Controllers\Api\V1\MarketplaceController::class, 'myAffiliations']);
        });

        // Member Area (Producer - JWT auth)
        Route::prefix('member-area')->group($memberAreaRoutes);

        // Landing Pages (AI Generator)
        Route::prefix('landing-pages')->group(function () {
            Route::get('/templates', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'templates']);
            Route::get('/section-types', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'sectionTypes']);
            Route::get('/', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'index']);
            Route::post('/generate', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'generate']);
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'destroy']);
            Route::post('/{id}/duplicate', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'duplicate']);
            Route::post('/{id}/publish', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'publish']);
            Route::post('/{id}/unpublish', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'unpublish']);
            Route::get('/{id}/preview', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'preview']);
            Route::post('/{id}/regenerate-section', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'regenerateSection']);
            Route::post('/{id}/chat-edit', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'chatEdit']);
            Route::get('/{id}/analytics', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'analytics']);
            Route::post('/{id}/set-domain', [\App\Http\Controllers\Api\V1\LandingPageController::class, 'setDomain']);
        });

        // WazerAI Assistant
        Route::prefix('wazer-ai')->group(function () {
            Route::post('/chat', [\App\Http\Controllers\Api\V1\WazerAIController::class, 'chat']);
            Route::get('/suggestions', [\App\Http\Controllers\Api\V1\WazerAIController::class, 'suggestions']);
            Route::post('/action', [\App\Http\Controllers\Api\V1\WazerAIController::class, 'executeAction']);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | API Key Authenticated Routes (External Integrations)
    |--------------------------------------------------------------------------
    */
    Route::prefix('external')->middleware(['auth.apikey', 'throttle:api', 'idempotency'])->group(function () use ($memberAreaRoutes) {

        // Charges
        Route::prefix('charges')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\ChargeController::class, 'index'])
                ->middleware('permission:charges.read');
            Route::post('/', [\App\Http\Controllers\Api\V1\ChargeController::class, 'store'])
                ->middleware('permission:charges.create');
            Route::get('/{chargeId}', [\App\Http\Controllers\Api\V1\ChargeController::class, 'show'])
                ->middleware('permission:charges.read');
            Route::post('/{chargeId}/cancel', [\App\Http\Controllers\Api\V1\ChargeController::class, 'cancel'])
                ->middleware('permission:charges.update');
            Route::post('/{chargeId}/refund', [\App\Http\Controllers\Api\V1\ChargeController::class, 'refund'])
                ->middleware('permission:refunds.create');
        });

        // Payments
        Route::prefix('payments')->group(function () {
            Route::post('/{chargeId}/process', [\App\Http\Controllers\Api\V1\PaymentController::class, 'process'])
                ->middleware('permission:payments.create');
            Route::get('/methods', [\App\Http\Controllers\Api\V1\PaymentController::class, 'methods'])
                ->middleware('permission:payments.read');
            Route::get('/installments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'installments'])
                ->middleware('permission:payments.read');
            Route::post('/transactions/{transactionId}/capture', [\App\Http\Controllers\Api\V1\PaymentController::class, 'capture'])
                ->middleware('permission:payments.create');
        });

        // Products
        Route::prefix('products')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\ProductController::class, 'index'])
                ->middleware('permission:products.read');
            Route::post('/', [\App\Http\Controllers\Api\V1\ProductController::class, 'store'])
                ->middleware('permission:products.create');
            Route::get('/{productId}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show'])
                ->middleware('permission:products.read');
            Route::put('/{productId}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update'])
                ->middleware('permission:products.update');
            Route::delete('/{productId}', [\App\Http\Controllers\Api\V1\ProductController::class, 'destroy'])
                ->middleware('permission:products.delete');
            Route::get('/{productId}/stats', [\App\Http\Controllers\Api\V1\ProductController::class, 'stats'])
                ->middleware('permission:products.read');
        });

        // Checkouts
        Route::prefix('checkouts')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'index'])
                ->middleware('permission:checkouts.read');
            Route::post('/', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'store'])
                ->middleware('permission:checkouts.create');
            Route::get('/{checkoutId}', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'show'])
                ->middleware('permission:checkouts.read');
            Route::put('/{checkoutId}', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'update'])
                ->middleware('permission:checkouts.update');
            Route::delete('/{checkoutId}', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'destroy'])
                ->middleware('permission:checkouts.delete');
            Route::post('/{checkoutId}/clone', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'clone'])
                ->middleware('permission:checkouts.create');
            Route::get('/{checkoutId}/analytics', [\App\Http\Controllers\Api\V1\CheckoutController::class, 'analytics'])
                ->middleware('permission:checkouts.read');
        });

        // Affiliates
        Route::prefix('affiliates')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'index'])
                ->middleware('permission:affiliates.read');
            Route::post('/', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'store'])
                ->middleware('permission:affiliates.create');
            Route::get('/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'show'])
                ->middleware('permission:affiliates.read');
            Route::put('/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'update'])
                ->middleware('permission:affiliates.update');
            Route::delete('/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'destroy'])
                ->middleware('permission:affiliates.delete');
            Route::get('/{affiliateId}/stats', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'stats'])
                ->middleware('permission:affiliates.read');
            Route::get('/{affiliateId}/commissions', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'commissions'])
                ->middleware('permission:affiliates.read');
        });

        // Withdrawals
        Route::prefix('withdrawals')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'index'])
                ->middleware('permission:withdrawals.read');
            Route::post('/', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'store'])
                ->middleware('permission:withdrawals.create');
            Route::get('/summary', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'summary'])
                ->middleware('permission:withdrawals.read');
            Route::get('/{withdrawalId}', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'show'])
                ->middleware('permission:withdrawals.read');
            Route::post('/{withdrawalId}/cancel', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'cancel'])
                ->middleware('permission:withdrawals.update');
        });

        // Reports
        Route::prefix('reports')->middleware('permission:reports.read')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\ReportController::class, 'dashboard']);
            Route::get('/transactions', [\App\Http\Controllers\Api\V1\ReportController::class, 'transactions']);
            Route::get('/revenue', [\App\Http\Controllers\Api\V1\ReportController::class, 'revenue']);
            Route::get('/products', [\App\Http\Controllers\Api\V1\ReportController::class, 'products']);
            Route::get('/affiliates', [\App\Http\Controllers\Api\V1\ReportController::class, 'affiliates']);
            Route::get('/export/{type}', [\App\Http\Controllers\Api\V1\ReportController::class, 'export']);
        });

        // Member Area (Producer - API key auth)
        Route::prefix('member-area')->group($memberAreaRoutes);
    });

    /*
    |--------------------------------------------------------------------------
    | Public Member Area Routes (For Students)
    |--------------------------------------------------------------------------
    */
    Route::prefix('members')->group(function () {
        // Public routes
        Route::get('/area', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'getArea']);
        Route::post('/login', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'login']);
        
        // Domain resolution - public endpoint
        Route::post('/resolve-domain', [\App\Http\Controllers\Api\V1\MemberDomainController::class, 'resolve']);

        // Authenticated member routes
        Route::middleware('auth:members')->group(function () {
            Route::get('/profile', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'profile']);
            Route::put('/profile', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'updateProfile']);
            Route::get('/courses', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'myCourses']);
            Route::get('/continue-watching', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'continueWatching']);
            Route::get('/courses/{courseSlug}', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'showCourse']);
            Route::get('/courses/{courseSlug}/lessons/{lessonId}', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'showLesson']);
            Route::post('/courses/{courseSlug}/lessons/{lessonId}/progress', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'updateProgress']);
            Route::post('/courses/{courseSlug}/lessons/{lessonId}/complete', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'completeLesson']);
            Route::get('/courses/{courseSlug}/lessons/{lessonId}/comments', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'getComments']);
            Route::post('/courses/{courseSlug}/lessons/{lessonId}/comments', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'addComment']);
            Route::get('/courses/{courseSlug}/certificate', [\App\Http\Controllers\Api\V1\PublicMemberAreaController::class, 'getCertificate']);
        });
    });
});
