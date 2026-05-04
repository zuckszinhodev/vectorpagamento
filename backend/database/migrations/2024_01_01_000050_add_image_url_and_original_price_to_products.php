<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'image_url')) {
                $table->string('image_url')->nullable()->after('images');
            }
            if (!Schema::hasColumn('products', 'original_price')) {
                $table->bigInteger('original_price')->nullable()->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'original_price']);
        });
    }
};
