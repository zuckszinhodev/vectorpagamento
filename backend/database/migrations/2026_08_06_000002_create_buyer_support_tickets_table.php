<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chamados abertos por compradores na central de ajuda publica.
     *
     * Tabela separada de `support_tickets` de proposito: aquela exige
     * account_id e user_id, que um comprador nao tem. Tornar as colunas
     * nulaveis quebraria as consultas do fluxo do vendedor.
     */
    public function up(): void
    {
        Schema::create('buyer_support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Codigo curto que o comprador guarda para acompanhar o caso.
            $table->string('protocol', 20)->unique();

            $table->string('name');
            $table->string('email');
            $table->string('subject');
            $table->string('category', 40)->default('outro');
            $table->text('message');

            // Referencia informada pelo cliente (id da cobranca, e-mail da
            // compra ou o que ele tiver). Texto livre: nao da para confiar.
            $table->string('order_reference')->nullable();

            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->default('open');
            $table->text('admin_reply')->nullable();
            $table->uuid('replied_by')->nullable();
            $table->timestamp('replied_at')->nullable();

            // Para triagem de abuso; rota publica recebe o que vier da internet.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_support_tickets');
    }
};
