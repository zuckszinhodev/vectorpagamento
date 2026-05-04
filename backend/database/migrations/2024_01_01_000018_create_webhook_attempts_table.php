<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('webhook_id');
            $table->string('event_type');
            $table->jsonb('payload');
            $table->integer('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('attempt_number')->default(1);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->foreign('webhook_id')->references('id')->on('webhooks')->onDelete('cascade');

            $table->index('webhook_id');
            $table->index('event_type');
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_attempts');
    }
};
