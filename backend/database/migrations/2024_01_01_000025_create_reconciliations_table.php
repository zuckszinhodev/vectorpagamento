<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->nullable()->index();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('type', 20)->default('daily'); // daily, weekly, monthly, manual
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->integer('total_charges')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('total_fees')->default(0);
            $table->bigInteger('total_net')->default(0);
            $table->bigInteger('total_refunds')->default(0);
            $table->bigInteger('total_chargebacks')->default(0);
            $table->json('discrepancies')->nullable();
            $table->json('summary')->nullable();
            $table->uuid('reconciled_by')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
