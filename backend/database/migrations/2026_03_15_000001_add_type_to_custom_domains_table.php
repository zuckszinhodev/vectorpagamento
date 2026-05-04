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
            $table->string('type', 20)->default('custom')->after('domain');
            $table->string('subdomain_slug')->nullable()->after('type');

            $table->unique('subdomain_slug');
            $table->index('type');
        });

        // Backfill existing rows as 'custom'
        DB::table('custom_domains')->whereNull('type')->orWhere('type', '')->update(['type' => 'custom']);
    }

    public function down(): void
    {
        Schema::table('custom_domains', function (Blueprint $table) {
            $table->dropUnique(['subdomain_slug']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'subdomain_slug']);
        });
    }
};
