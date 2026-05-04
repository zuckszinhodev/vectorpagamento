<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->uuid('charge_id');
            $table->bigInteger('amount');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'processing', 'approved', 'declined', 'failed'])->default('pending');
            $table->string('acquirer_refund_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('charge_id')->references('id')->on('charges')->onDelete('cascade');

            $table->index('transaction_id');
            $table->index('charge_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
