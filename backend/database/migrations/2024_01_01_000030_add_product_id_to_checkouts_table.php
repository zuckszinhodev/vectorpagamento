<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            if (!Schema::hasColumn('checkouts', 'product_id')) {
                $table->uuid('product_id')->nullable()->after('account_id');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
                $table->index('product_id');
            }
            if (!Schema::hasColumn('checkouts', 'views_count')) {
                $table->integer('views_count')->default(0)->after('webhook_url');
            }
            if (!Schema::hasColumn('checkouts', 'conversions_count')) {
                $table->integer('conversions_count')->default(0)->after('views_count');
            }
            if (!Schema::hasColumn('checkouts', 'conversion_rate')) {
                $table->decimal('conversion_rate', 5, 2)->default(0)->after('conversions_count');
            }
            if (!Schema::hasColumn('checkouts', 'total_revenue')) {
                $table->bigInteger('total_revenue')->default(0)->after('conversion_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'views_count', 'conversions_count', 'conversion_rate', 'total_revenue']);
        });
    }
};
