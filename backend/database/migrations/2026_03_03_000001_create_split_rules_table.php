<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('name');                                // "Regra Afiliado 30%", etc.
            $table->uuid('product_id')->nullable();                // NULL = all products
            $table->uuid('checkout_id')->nullable();               // NULL = all checkouts
            $table->boolean('is_default')->default(false);         // default rule for the account
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);               // higher = evaluated first
            $table->json('recipients');                             // array of {recipient_id, recipient_type, type, value, description}
            $table->boolean('charge_fee_to_producer')->default(true); // who absorbs gateway fees
            $table->integer('minimum_amount')->default(0);         // minimum charge amount (cents) to apply rule
            $table->boolean('auto_process')->default(true);        // auto-credit wallets on payment
            $table->integer('transfer_delay_hours')->default(0);   // delay before crediting (0 = immediate)
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['account_id', 'is_active']);
            $table->index(['account_id', 'product_id']);
            $table->index(['account_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('split_rules');
    }
};
