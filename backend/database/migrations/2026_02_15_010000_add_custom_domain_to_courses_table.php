<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('courses', 'custom_domain')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table) {
            $table->string('custom_domain')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('custom_domain');
        });
    }
};
