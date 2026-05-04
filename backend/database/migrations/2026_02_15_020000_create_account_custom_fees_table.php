<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_custom_fees')) {
            return;
        }

        Schema::create('account_custom_fees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('set_by_user_id')->nullable(); // admin who set the fees

            // Transaction fees
            $table->decimal('pix_fee_percent', 5, 2)->nullable();         // % fee per PIX transaction
            $table->integer('pix_fee_fixed_cents')->nullable();           // fixed fee per PIX in cents
            $table->decimal('credit_card_fee_percent', 5, 2)->nullable(); // % fee per credit card
            $table->integer('credit_card_fee_fixed_cents')->nullable();   // fixed fee per CC in cents
            $table->decimal('boleto_fee_percent', 5, 2)->nullable();      // % fee per boleto
            $table->integer('boleto_fee_fixed_cents')->nullable();        // fixed fee per boleto in cents

            // Withdrawal fees
            $table->integer('withdrawal_pix_fee_cents')->nullable();      // fee for PIX withdrawal
            $table->integer('withdrawal_ted_fee_cents')->nullable();      // fee for TED withdrawal
            $table->decimal('instant_withdrawal_fee_percent', 5, 2)->nullable(); // instant withdrawal %

            // Anti-fraud / chargeback
            $table->decimal('antifraude_fee_percent', 5, 2)->nullable();  // anti-fraud fee %
            $table->integer('antifraude_fee_fixed_cents')->nullable();    // anti-fraud fixed fee

            // Installment fees (seller absorbs)
            $table->decimal('installment_fee_percent', 5, 2)->nullable(); // extra % per installment

            // Minimum withdrawal amount
            $table->integer('min_withdrawal_cents')->nullable();          // min withdrawal amount

            // Holdback / reserve
            $table->decimal('holdback_percent', 5, 2)->nullable();        // % retained as reserve
            $table->integer('holdback_days')->nullable();                 // days to hold before release

            $table->text('notes')->nullable();                            // admin notes about this client
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->unique('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_custom_fees');
    }
};
