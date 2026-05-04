<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('code', 50)->unique();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->decimal('min_purchase', 10, 2)->nullable();
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('first_purchase_only')->default(false);
            $table->json('product_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['account_id', 'code']);
            $table->index(['account_id', 'is_active']);
        });

        Schema::create('coupon_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('coupon_id');
            $table->uuid('charge_id');
            $table->string('customer_email');
            $table->decimal('discount_amount', 10, 2);
            $table->timestamp('used_at');
            $table->timestamps();

            $table->foreign('coupon_id')->references('id')->on('coupons')->onDelete('cascade');
            $table->foreign('charge_id')->references('id')->on('charges')->onDelete('cascade');
            $table->index(['coupon_id', 'used_at']);
            $table->index(['customer_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usage');
        Schema::dropIfExists('coupons');
    }
};
