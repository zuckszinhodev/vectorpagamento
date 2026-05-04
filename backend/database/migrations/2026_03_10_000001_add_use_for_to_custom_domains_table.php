<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_domains', function (Blueprint $table) {
            $table->json('use_for')->default('["checkout","members"]')->after('is_primary');
        });

        // Set existing domains to both checkout and members
        DB::table('custom_domains')->update([
            'use_for' => json_encode(['checkout', 'members']),
        ]);
    }

    public function down(): void
    {
        Schema::table('custom_domains', function (Blueprint $table) {
            $table->dropColumn('use_for');
        });
    }
};
