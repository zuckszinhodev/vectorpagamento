<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_acquirers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('acquirer_id');
            $table->text('credentials'); // Encrypted
            $table->integer('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->default('{}');
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('acquirer_id')->references('id')->on('acquirers')->onDelete('cascade');

            $table->unique(['account_id', 'acquirer_id']);
            $table->index('account_id');
            $table->index('acquirer_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_acquirers');
    }
};
