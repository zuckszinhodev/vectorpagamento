<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('checkout_id')->nullable();
            $table->uuid('affiliate_id')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->string('external_id')->nullable();
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('BRL');
            $table->enum('payment_method', ['credit_card', 'debit_card', 'pix', 'boleto']);
            $table->enum('status', [
                'pending', 'processing', 'authorized', 'approved', 
                'declined', 'cancelled', 'refunded', 'partially_refunded',
                'chargeback', 'expired', 'failed'
            ])->default('pending');
            $table->jsonb('customer');
            $table->jsonb('billing')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('checkout_id')->references('id')->on('checkouts')->onDelete('set null');
            $table->foreign('affiliate_id')->references('id')->on('affiliates')->onDelete('set null');

            $table->index('account_id');
            $table->index('status');
            $table->index('payment_method');
            $table->index('created_at');
            $table->index('idempotency_key');
            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
