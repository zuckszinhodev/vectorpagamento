<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('name');
            $table->string('key_hash', 64)->unique();
            $table->string('secret_hash', 64);
            $table->jsonb('permissions')->default('[]');
            $table->integer('rate_limit')->default(60);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');

            $table->index('account_id');
            $table->index('key_hash');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
