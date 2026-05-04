<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->uuid('account_id')->nullable();
            $table->string('event_type');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('details')->default('{}');
            $table->timestamp('created_at');

            $table->index('user_id');
            $table->index('account_id');
            $table->index('event_type');
            $table->index('severity');
            $table->index('ip_address');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
