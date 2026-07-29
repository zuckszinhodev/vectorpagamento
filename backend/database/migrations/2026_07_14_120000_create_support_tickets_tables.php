<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets + threaded messages.
 *
 * A ticket belongs to an account (merchant) and is opened by a user.
 * Messages are appended by both the seller and admin support staff.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('user_id'); // criador (vendedor)
            $table->string('subject');
            $table->enum('category', ['financial', 'technical', 'acquirer', 'kyc', 'other'])->default('other');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->default('open');
            // Quem tem a "bola" agora: 'seller' → esperando resposta do vendedor,
            // 'admin' → esperando resposta do suporte. Guia filtros e badges.
            $table->enum('awaiting', ['admin', 'seller'])->default('admin');
            $table->uuid('assigned_admin_id')->nullable(); // admin responsável (opcional)
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('assigned_admin_id')->references('id')->on('users')->onDelete('set null');

            $table->index('account_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('awaiting');
            $table->index('created_at');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('user_id'); // autor
            $table->enum('author_type', ['seller', 'admin', 'system'])->default('seller');
            $table->text('body');
            $table->boolean('is_internal_note')->default(false); // nota admin-only, não visível ao vendedor
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('support_tickets')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index('ticket_id');
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
