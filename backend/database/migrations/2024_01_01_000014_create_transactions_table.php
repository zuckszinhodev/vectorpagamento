<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('charge_id');
            $table->uuid('acquirer_id');
            $table->enum('type', ['authorization', 'capture', 'sale', 'refund']);
            $table->enum('status', [
                'pending', 'processing', 'authorized', 'approved', 
                'declined', 'cancelled', 'refunded', 'failed'
            ])->default('pending');
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('net_amount');
            $table->string('acquirer_tid')->nullable();
            $table->string('acquirer_nsu')->nullable();
            $table->string('authorization_code')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_digits', 4)->nullable();
            $table->integer('installments')->default(1);
            $table->jsonb('gateway_response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('charge_id')->references('id')->on('charges')->onDelete('cascade');
            $table->foreign('acquirer_id')->references('id')->on('acquirers')->onDelete('restrict');

            $table->index('charge_id');
            $table->index('acquirer_id');
            $table->index('status');
            $table->index('type');
            $table->index('created_at');
            $table->index('acquirer_tid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
