<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('splits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->uuid('recipient_id');
            $table->enum('recipient_type', ['account', 'affiliate']);
            $table->enum('type', ['percentage', 'fixed']);
            $table->bigInteger('amount');
            $table->decimal('percentage', 5, 2)->nullable();
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('net_amount');
            $table->enum('status', ['pending', 'processing', 'transferred', 'failed'])->default('pending');
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');

            $table->index('transaction_id');
            $table->index('recipient_id');
            $table->index('recipient_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('splits');
    }
};
