<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_domains')) {
            return;
        }

        Schema::create('custom_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('domain')->unique();
            $table->string('verification_token', 64);
            $table->enum('status', ['pending', 'verified', 'failed', 'expired'])->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->boolean('ssl_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->string('last_check_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index('account_id');
            $table->index('domain');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_domains');
    }
};
