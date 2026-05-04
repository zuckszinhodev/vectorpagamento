<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Showcase fields
            $table->boolean('is_showcase')->default(false)->after('status');
            $table->string('showcase_status')->default('pending')->after('is_showcase'); // pending, approved, rejected
            $table->string('showcase_visibility')->default('private')->after('showcase_status'); // public, private
            $table->text('showcase_rejection_reason')->nullable()->after('showcase_visibility');
            $table->timestamp('showcase_approved_at')->nullable()->after('showcase_rejection_reason');
            $table->uuid('showcase_approved_by')->nullable()->after('showcase_approved_at');
            $table->timestamp('showcase_reviewed_at')->nullable()->after('showcase_approved_by');
            $table->uuid('showcase_reviewed_by')->nullable()->after('showcase_reviewed_at');
            $table->integer('sales_count')->default(0)->after('showcase_reviewed_by');
            
            // Indexes
            $table->index('is_showcase');
            $table->index('showcase_status');
            $table->index('showcase_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_showcase']);
            $table->dropIndex(['showcase_status']);
            $table->dropIndex(['showcase_visibility']);
            
            $table->dropColumn([
                'is_showcase',
                'showcase_status',
                'showcase_visibility',
                'showcase_rejection_reason',
                'showcase_approved_at',
                'showcase_approved_by',
                'showcase_reviewed_at',
                'showcase_reviewed_by',
                'sales_count',
            ]);
        });
    }
};
