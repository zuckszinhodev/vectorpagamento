<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('webhook_attempts', 'success')) {
            return;
        }

        Schema::table('webhook_attempts', function (Blueprint $table) {
            $table->boolean('success')->default(false)->after('attempt_number');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_attempts', function (Blueprint $table) {
            $table->dropColumn('success');
        });
    }
};
