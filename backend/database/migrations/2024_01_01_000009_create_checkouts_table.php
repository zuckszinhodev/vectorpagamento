<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['redirect', 'embedded', 'popup'])->default('redirect');
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');
            $table->jsonb('theme')->default('{}');
            $table->jsonb('fields')->default('[]');
            $table->jsonb('settings')->default('{}');
            $table->string('success_url')->nullable();
            $table->string('cancel_url')->nullable();
            $table->string('webhook_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');

            $table->index('account_id');
            $table->index('slug');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
