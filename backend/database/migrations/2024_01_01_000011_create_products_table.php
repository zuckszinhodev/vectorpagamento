<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('checkout_id')->nullable();
            $table->uuid('category_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['digital', 'physical', 'service'])->default('digital');
            $table->bigInteger('price');
            $table->string('currency', 3)->default('BRL');
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->bigInteger('commission_fixed')->default(0);
            $table->boolean('affiliate_enabled')->default(false);
            $table->boolean('stock_enabled')->default(false);
            $table->integer('stock_quantity')->nullable();
            $table->jsonb('images')->default('[]');
            $table->jsonb('metadata')->default('{}');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('checkout_id')->references('id')->on('checkouts')->onDelete('set null');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');

            $table->index('account_id');
            $table->index('slug');
            $table->index('is_active');
            $table->index('affiliate_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
