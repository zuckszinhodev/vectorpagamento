<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('url');
            $table->jsonb('events')->default('[]');
            $table->string('secret', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');

            $table->index('account_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
