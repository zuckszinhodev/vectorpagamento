<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('n8n_delivery_logs')) {
            return;
        }

        Schema::create('n8n_delivery_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('integration_id')->index();
            $table->string('event', 100)->index();
            $table->string('status', 20)->default('pending')->index(); // pending, success, failed, retrying
            $table->integer('http_status')->nullable();
            $table->integer('attempt')->default(1);
            $table->integer('max_attempts')->default(3);
            $table->json('request_payload')->nullable();
            $table->json('request_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('integration_id')->references('id')->on('n8n_integrations')->cascadeOnDelete();
            
            // Index for cleanup / querying
            $table->index(['integration_id', 'created_at']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('n8n_delivery_logs');
    }
};
