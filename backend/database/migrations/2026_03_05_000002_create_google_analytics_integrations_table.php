<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_analytics_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('measurement_id', 50)->comment('GA4 Measurement ID (G-XXXXXXXXXX)');
            $table->text('api_secret')->nullable()->comment('GA4 Measurement Protocol API secret');
            $table->boolean('is_active')->default(true);

            // Tracking config
            $table->boolean('track_purchase')->default(true)->comment('Send purchase event on payment approved');
            $table->boolean('track_begin_checkout')->default(true)->comment('Send begin_checkout event');
            $table->boolean('track_page_view')->default(true)->comment('Send page_view event via client-side tag');

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
        Schema::dropIfExists('google_analytics_integrations');
    }
};
