<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('acquirer_id');
            $table->string('payment_method')->nullable();
            $table->bigInteger('min_amount')->nullable();
            $table->bigInteger('max_amount')->nullable();
            $table->integer('priority')->default(1);
            $table->jsonb('conditions')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('acquirer_id')->references('id')->on('acquirers')->onDelete('cascade');

            $table->index('account_id');
            $table->index(['account_id', 'payment_method', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
    }
};
