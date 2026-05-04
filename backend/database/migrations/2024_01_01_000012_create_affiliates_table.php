<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('product_id');
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended', 'active', 'inactive', 'blocked'])->default('pending');
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->bigInteger('commission_fixed')->nullable();
            $table->string('token', 64)->unique();
            $table->bigInteger('total_sales')->default(0);
            $table->bigInteger('total_commission')->default(0);
            $table->integer('total_orders')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->unique(['user_id', 'product_id']);
            $table->index('user_id');
            $table->index('product_id');
            $table->index('token');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
