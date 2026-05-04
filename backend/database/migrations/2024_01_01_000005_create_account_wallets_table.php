<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('currency', 3)->default('BRL');
            $table->bigInteger('available_balance')->default(0);
            $table->bigInteger('pending_balance')->default(0);
            $table->bigInteger('blocked_balance')->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            
            $table->unique(['account_id', 'currency']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_wallets');
    }
};
