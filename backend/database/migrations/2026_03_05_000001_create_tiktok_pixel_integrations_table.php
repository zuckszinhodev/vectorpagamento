<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_pixel_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('pixel_id', 50)->comment('TikTok Pixel ID');
            $table->text('access_token')->nullable()->comment('TikTok Events API access token');
            $table->string('test_event_code', 50)->nullable()->comment('Test event code for debugging');
            $table->boolean('is_active')->default(true);

            // Tracking config
            $table->boolean('track_complete_payment')->default(true)->comment('Send CompletePayment event on payment approved');
            $table->boolean('track_initiate_checkout')->default(true)->comment('Send InitiateCheckout event');
            $table->boolean('track_page_view')->default(true)->comment('Send PageView event via client-side pixel');

            // Stats
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status', 50)->nullable();
            $table->text('last_response')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);

            $table->timestamps();

            $table->unique('account_id');
            $table->index('is_active');

            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_pixel_integrations');
    }
};
