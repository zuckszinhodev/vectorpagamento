<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fraud Rules Table
        Schema::create('fraud_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('name');
            $table->string('rule_type'); // velocity, blacklist, amount, geolocation, device, bin
            $table->json('conditions');
            $table->integer('risk_score'); // 0-100
            $table->string('action'); // flag, block, review
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['account_id', 'is_active']);
        });

        // Blacklists Table
        Schema::create('blacklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->nullable();
            $table->string('type'); // email, document, ip, bin, fingerprint
            $table->string('value');
            $table->string('reason')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['type', 'value']);
            $table->index(['account_id', 'type']);
        });

        // Risk Scores Table
        Schema::create('risk_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('charge_id');
            $table->integer('total_score'); // 0-100
            $table->json('score_breakdown'); // Each rule contribution
            $table->string('risk_level'); // low, medium, high, critical
            $table->string('recommendation'); // approve, review, decline
            $table->json('triggered_rules')->nullable();
            $table->json('device_fingerprint')->nullable();
            $table->string('ip_address')->nullable();
            $table->json('geolocation')->nullable();
            $table->timestamps();

            $table->foreign('charge_id')->references('id')->on('charges')->onDelete('cascade');
            $table->index(['charge_id']);
            $table->index(['risk_level']);
        });

        // Device Fingerprints Table
        Schema::create('device_fingerprints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('fingerprint_hash')->unique();
            $table->string('customer_email')->nullable();
            $table->json('device_data'); // User agent, screen, timezone, etc
            $table->string('ip_address');
            $table->integer('transaction_count')->default(0);
            $table->integer('declined_count')->default(0);
            $table->integer('chargeback_count')->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('trust_level')->default('unknown'); // trusted, neutral, suspicious, blocked
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->index(['fingerprint_hash']);
            $table->index(['customer_email']);
            $table->index(['trust_level']);
        });

        // Velocity Tracking Table
        Schema::create('velocity_tracking', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tracking_key'); // email, ip, fingerprint, card_bin
            $table->string('tracking_type');
            $table->integer('transaction_count');
            $table->decimal('total_amount', 10, 2);
            $table->timestamp('window_start')->useCurrent();
            $table->timestamp('window_end')->nullable();
            $table->timestamps();

            $table->index(['tracking_key', 'tracking_type', 'window_start']);
        });

        // Add fraud-related columns to charges table
        Schema::table('charges', function (Blueprint $table) {
            $table->integer('risk_score')->nullable()->after('status');
            $table->string('risk_level')->nullable()->after('risk_score');
            $table->boolean('requires_review')->default(false)->after('risk_level');
            $table->string('device_fingerprint')->nullable()->after('requires_review');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn(['risk_score', 'risk_level', 'requires_review', 'device_fingerprint']);
        });

        Schema::dropIfExists('velocity_tracking');
        Schema::dropIfExists('device_fingerprints');
        Schema::dropIfExists('risk_scores');
        Schema::dropIfExists('blacklists');
        Schema::dropIfExists('fraud_rules');
    }
};
