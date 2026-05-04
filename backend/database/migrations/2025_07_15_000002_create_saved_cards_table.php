<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('customer_email');
            $table->string('customer_document', 20)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last_four', 4);
            $table->string('card_holder_name')->nullable();
            $table->smallInteger('exp_month')->nullable();
            $table->smallInteger('exp_year')->nullable();
            $table->string('card_fingerprint')->nullable();
            $table->string('acquirer', 30);
            $table->text('acquirer_card_token')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['customer_email', 'account_id']);
            $table->unique(['account_id', 'customer_email', 'card_last_four', 'card_brand'], 'saved_cards_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_cards');
    }
};
