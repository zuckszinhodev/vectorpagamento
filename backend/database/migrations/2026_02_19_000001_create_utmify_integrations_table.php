<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('utmify_integrations')) {
            return;
        }

        Schema::create('utmify_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('api_token');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_response')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('cascade');

            $table->unique('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utmify_integrations');
    }
};
