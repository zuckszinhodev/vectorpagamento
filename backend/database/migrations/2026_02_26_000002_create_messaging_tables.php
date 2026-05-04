<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Messaging provider configurations (WhatsApp / SMS)
        Schema::create('messaging_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->enum('channel', ['whatsapp', 'sms']);
            $table->string('provider_name'); // e.g. evolution_api, twilio, vonage, z-api
            $table->text('api_url')->nullable();
            $table->text('api_key')->nullable();
            $table->text('instance_name')->nullable(); // for WhatsApp instances
            $table->text('instance_token')->nullable();
            $table->string('sender_number')->nullable(); // phone number
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // extra config
            $table->integer('messages_sent')->default(0);
            $table->integer('messages_failed')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['account_id', 'channel']);
            $table->index(['account_id', 'is_active']);
        });

        // Message templates
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('provider_id')->nullable();
            $table->enum('channel', ['whatsapp', 'sms']);
            $table->string('name');
            $table->string('trigger_event'); // payment.approved, payment.expired, charge.created, etc.
            $table->text('message_body'); // template with {{variables}}
            $table->boolean('is_active')->default(true);
            $table->integer('times_sent')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('provider_id')->references('id')->on('messaging_providers')->onDelete('set null');
            $table->index(['account_id', 'channel']);
            $table->index(['account_id', 'trigger_event']);
        });

        // Message logs
        Schema::create('message_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('provider_id')->nullable();
            $table->uuid('template_id')->nullable();
            $table->enum('channel', ['whatsapp', 'sms']);
            $table->string('recipient_phone');
            $table->string('recipient_name')->nullable();
            $table->text('message_body');
            $table->string('trigger_event')->nullable();
            $table->uuid('charge_id')->nullable();
            $table->enum('status', ['pending', 'sent', 'delivered', 'failed', 'read'])->default('pending');
            $table->text('provider_response')->nullable();
            $table->string('external_id')->nullable(); // provider message ID
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('provider_id')->references('id')->on('messaging_providers')->onDelete('set null');
            $table->foreign('template_id')->references('id')->on('message_templates')->onDelete('set null');
            $table->index(['account_id', 'channel']);
            $table->index(['account_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('messaging_providers');
    }
};
