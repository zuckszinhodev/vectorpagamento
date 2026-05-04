<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            // Add missing columns that the model/controllers expect
            if (!Schema::hasColumn('affiliates', 'account_id')) {
                $table->uuid('account_id')->nullable()->after('id');
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
                $table->index('account_id');
            }

            if (!Schema::hasColumn('affiliates', 'code')) {
                $table->string('code', 64)->nullable()->unique()->after('token');
            }

            if (!Schema::hasColumn('affiliates', 'name')) {
                $table->string('name')->nullable()->after('code');
            }

            if (!Schema::hasColumn('affiliates', 'email')) {
                $table->string('email')->nullable()->after('name');
            }

            if (!Schema::hasColumn('affiliates', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }

            if (!Schema::hasColumn('affiliates', 'document')) {
                $table->string('document')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('affiliates', 'commission_type')) {
                $table->string('commission_type')->default('percentage')->after('document');
            }

            if (!Schema::hasColumn('affiliates', 'commission_value')) {
                $table->decimal('commission_value', 10, 2)->default(0)->after('commission_type');
            }

            // Make product_id nullable (controller allows it)
            // Note: This requires dropping and re-adding the foreign key
        });

        // Update status enum to include 'active', 'inactive', 'blocked' values used by controllers
        // PostgreSQL supports ALTER TYPE, but for safety we skip enum alteration
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $columns = ['account_id', 'code', 'name', 'email', 'phone', 'document', 'commission_type', 'commission_value'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('affiliates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
