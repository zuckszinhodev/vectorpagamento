<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('api_keys', 'type')) {
            return;
        }

        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('type', 20)->default('publishable');
        });

        // Update existing keys: infer type from key_prefix
        \DB::table('api_keys')
            ->where('key_prefix', 'like', 'sk_%')
            ->update(['type' => 'secret']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
