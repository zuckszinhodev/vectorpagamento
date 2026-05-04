<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_pixel_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('pixel_id', 50)->comment('Facebook Pixel ID (e.g. 123456789012345)');
            $table->text('access_token')->comment('Meta Conversions API access token');
            $table->string('test_event_code', 50)->nullable()->comment('Test event code for Meta Events Manager debugging');
            $table->boolean('is_active')->default(true);

            // Tracking config
            $table->boolean('track_purchase')->default(true)->comment('Send Purchase event on payment approved');
            $table->boolean('track_initiate_checkout')->default(true)->comment('Send InitiateCheckout event on checkout view');
            $table->boolean('track_add_payment_info')->default(true)->comment('Send AddPaymentInfo event');
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
        Schema::dropIfExists('facebook_pixel_integrations');
    }
};
