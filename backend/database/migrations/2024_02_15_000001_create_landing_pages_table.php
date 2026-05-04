<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Relationships
            $table->uuid('account_id');
            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('cascade');
            
            $table->uuid('product_id')->nullable();
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('set null');
            
            $table->uuid('checkout_id')->nullable();
            $table->foreign('checkout_id')
                  ->references('id')
                  ->on('checkouts')
                  ->onDelete('set null');
            
            // Basic info
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            
            // AI Generation
            $table->text('prompt')->nullable(); // Original prompt used
            $table->string('ai_model')->nullable(); // gpt-4, claude, etc
            
            // Page content (JSON structure)
            $table->json('content'); // Full page structure
            $table->json('sections')->nullable(); // Individual sections for editing
            $table->json('styles')->nullable(); // Custom CSS/styles
            $table->json('seo')->nullable(); // SEO metadata
            
            // Customizations
            $table->string('template')->default('modern'); // Template base
            $table->string('color_scheme')->default('auto'); // auto, light, dark, custom
            $table->json('custom_colors')->nullable();
            $table->json('custom_fonts')->nullable();
            
            // Status
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            
            // Analytics
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->unsignedBigInteger('conversions_count')->default(0);
            
            // Domain
            $table->string('custom_domain')->nullable();
            $table->string('subdomain')->nullable();
            
            // Versioning
            $table->unsignedInteger('version')->default(1);
            $table->json('version_history')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['account_id', 'status']);
            $table->index('slug');
            $table->index('custom_domain');
        });

        // Landing page A/B test variants
        Schema::create('landing_page_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->uuid('landing_page_id');
            $table->foreign('landing_page_id')
                  ->references('id')
                  ->on('landing_pages')
                  ->onDelete('cascade');
            
            $table->string('name');
            $table->json('content');
            $table->json('sections')->nullable();
            
            $table->unsignedInteger('traffic_weight')->default(50); // Percentage of traffic
            
            // Analytics per variant
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('conversions_count')->default(0);
            
            $table->boolean('is_control')->default(false);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_page_variants');
        Schema::dropIfExists('landing_pages');
    }
};
