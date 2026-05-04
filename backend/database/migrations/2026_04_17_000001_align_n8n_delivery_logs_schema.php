<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('n8n_delivery_logs')) {
            return;
        }

        Schema::table('n8n_delivery_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('n8n_delivery_logs', 'http_status')) {
                $table->integer('http_status')->nullable();
            }

            if (!Schema::hasColumn('n8n_delivery_logs', 'max_attempts')) {
                $table->integer('max_attempts')->default(3);
            }

            if (!Schema::hasColumn('n8n_delivery_logs', 'request_payload')) {
                $table->json('request_payload')->nullable();
            }

            if (!Schema::hasColumn('n8n_delivery_logs', 'request_headers')) {
                $table->json('request_headers')->nullable();
            }

            if (!Schema::hasColumn('n8n_delivery_logs', 'response_body')) {
                $table->text('response_body')->nullable();
            }

            if (!Schema::hasColumn('n8n_delivery_logs', 'response_time_ms')) {
                $table->integer('response_time_ms')->nullable();
            }

            if (!Schema::hasColumn('n8n_delivery_logs', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable();
            }

            if (!Schema::hasColumn('n8n_delivery_logs', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally no-op: this migration aligns divergent historical schemas.
    }
};
