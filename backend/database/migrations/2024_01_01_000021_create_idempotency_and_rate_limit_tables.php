<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('request_hash', 64);
            $table->json('response')->nullable();
            $table->integer('status_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->index('key');
            $table->index('expires_at');
        });

        Schema::create('rate_limit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('identifier');
            $table->enum('identifier_type', ['ip', 'api_key', 'user']);
            $table->string('endpoint');
            $table->integer('requests_count')->default(1);
            $table->timestamp('window_start')->useCurrent();
            $table->boolean('blocked')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['identifier', 'identifier_type', 'endpoint']);
            $table->index('window_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_logs');
        Schema::dropIfExists('idempotency_keys');
    }
};
