<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chargebacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('charge_id')->index();
            $table->uuid('transaction_id')->nullable()->index();
            $table->uuid('account_id')->index();
            $table->bigInteger('amount');
            $table->string('reason_code', 50)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 30)->default('open')->index(); // open, under_review, evidence_sent, won, lost, expired
            $table->json('evidence')->nullable();
            $table->string('acquirer_reference')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('charge_id')->references('id')->on('charges')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chargebacks');
    }
};
