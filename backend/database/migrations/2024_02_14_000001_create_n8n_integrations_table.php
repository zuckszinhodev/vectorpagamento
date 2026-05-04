<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('n8n_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Basic info
            $table->string('name');
            $table->text('description')->nullable();
            
            // Account relationship (null for global integrations)
            $table->uuid('account_id')->nullable();
            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('cascade');
            
            // Webhook configuration
            $table->string('webhook_url', 2048);
            $table->json('events'); // Array of events to listen
            $table->string('secret_key', 128);
            $table->boolean('is_active')->default(true);
            
            // Retry configuration
            $table->integer('retry_count')->default(3);
            $table->integer('retry_delay')->default(60); // seconds
            $table->integer('timeout')->default(30); // seconds
            
            // Custom configuration
            $table->json('headers')->nullable(); // Custom HTTP headers
            $table->json('metadata')->nullable(); // Additional metadata
            
            // Stats and status
            $table->timestamp('last_triggered_at')->nullable();
            $table->string('last_status', 20)->nullable(); // success, failed, timeout
            $table->text('last_response')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index('account_id');
            $table->index('is_active');
            $table->index('last_triggered_at');
        });

        // Create delivery logs table for detailed tracking (skip if already exists)
        if (Schema::hasTable('n8n_delivery_logs')) {
            return;
        }

        Schema::create('n8n_delivery_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->uuid('integration_id');
            $table->foreign('integration_id')
                  ->references('id')
                  ->on('n8n_integrations')
                  ->onDelete('cascade');
            
            $table->string('event');
            $table->json('payload');
            $table->integer('status_code')->nullable();
            $table->text('response')->nullable();
            $table->string('status', 20); // pending, success, failed, timeout
            $table->integer('attempt')->default(1);
            $table->integer('duration_ms')->nullable(); // Response time in milliseconds
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('integration_id');
            $table->index('event');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('n8n_delivery_logs');
        Schema::dropIfExists('n8n_integrations');
    }
};
