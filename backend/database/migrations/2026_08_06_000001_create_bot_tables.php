<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Atendimento bot: um por conta pode estar ativo por vez (modo fluxo ou IA).
        Schema::create('bot_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('name');
            $table->enum('mode', ['flow', 'ai'])->default('flow');
            $table->boolean('is_active')->default(false);

            // Modo fluxo: arvore de nos {id, type, ...}. Modo IA: persona + regras.
            $table->json('flow_data')->nullable();
            $table->json('ai_config')->nullable();

            // Quando o bot deve assumir a conversa.
            $table->json('trigger_keywords')->nullable();
            $table->boolean('only_business_hours')->default(false);
            $table->json('business_hours')->nullable();

            $table->integer('conversations_count')->default(0);
            $table->integer('messages_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index(['account_id', 'is_active']);
        });

        Schema::create('bot_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('flow_id')->nullable();
            $table->string('contact_phone');
            $table->string('contact_name')->nullable();

            // Posicao atual no fluxo; null no modo IA.
            $table->string('current_node_id')->nullable();
            // Variaveis coletadas + dados do gateway resolvidos para este contato.
            $table->json('context')->nullable();

            $table->enum('status', ['active', 'handoff', 'ended'])->default('active');
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();

            // Continuacao agendada por um no de espera. O token invalida
            // retomadas obsoletas quando a conversa anda antes da hora.
            $table->string('resume_node_id')->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->uuid('resume_token')->nullable();

            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('flow_id')->references('id')->on('bot_flows')->onDelete('set null');
            $table->unique(['account_id', 'contact_phone']);
            $table->index(['account_id', 'status']);
        });

        Schema::create('bot_conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->enum('direction', ['in', 'out']);
            $table->text('body');
            $table->string('node_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('bot_conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'created_at']);
        });

        // Token da URL publica que o provedor chama ao receber mensagem.
        Schema::table('messaging_providers', function (Blueprint $table) {
            $table->string('inbound_token', 64)->nullable()->unique()->after('sender_number');
        });
    }

    public function down(): void
    {
        Schema::table('messaging_providers', function (Blueprint $table) {
            $table->dropUnique(['inbound_token']);
            $table->dropColumn('inbound_token');
        });

        Schema::dropIfExists('bot_conversation_messages');
        Schema::dropIfExists('bot_conversations');
        Schema::dropIfExists('bot_flows');
    }
};
