<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling reserve holds: a portion of each paid charge's net amount can be
 * held (moved to blocked balance) for N days, then released back to available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reserve_holds')) {
            return;
        }

        Schema::create('reserve_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('charge_id')->nullable();
            $table->bigInteger('amount'); // cents held
            $table->string('status', 20)->default('held'); // held | released
            $table->timestamp('release_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['status', 'release_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserve_holds');
    }
};
