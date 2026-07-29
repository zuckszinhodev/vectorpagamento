<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado por-usuário para notificações compartilhadas de conta.
 *
 * Notificações "de conta" (user_id null) são visíveis a todos os usuários da
 * mesma account. Antes disso, marcar como lida afetava todos ao mesmo tempo.
 * Esta tabela guarda o estado de leitura/ocultação por usuário: cada linha diz
 * "o usuário X leu/ocultou a notificação Y".
 *
 * Notificações direcionadas (user_id preenchido) continuam usando a coluna
 * `notifications.read` — só as compartilhadas caem aqui.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->uuid('user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable(); // "ocultei para mim"
            $table->timestamps();

            $table->foreign('notification_id')->references('id')->on('notifications')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
