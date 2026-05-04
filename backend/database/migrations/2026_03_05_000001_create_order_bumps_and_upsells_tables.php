<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Order Bumps — ofertas adicionais exibidas no checkout antes do pagamento
        Schema::create('order_bumps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('checkout_id');
            $table->uuid('product_id'); // produto do order bump
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('call_to_action', 255)->default('Sim, eu quero!');
            $table->string('image_url', 500)->nullable();
            $table->integer('price'); // preço em centavos (pode ser diferente do produto original)
            $table->integer('original_price')->nullable(); // preço riscado (ancoragem)
            $table->unsignedSmallInteger('discount_percent')->nullable(); // desconto visual
            $table->enum('display_position', ['before_payment', 'after_product', 'sidebar'])->default('before_payment');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_pre_selected')->default(false); // checkbox pré-marcado
            $table->jsonb('settings')->default('{}'); // customizações (cores, estilos)
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('conversions_count')->default(0);
            $table->unsignedBigInteger('total_revenue')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('checkout_id')->references('id')->on('checkouts')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->index(['checkout_id', 'is_active', 'sort_order']);
            $table->index('account_id');
        });

        // Upsells — ofertas pós-compra exibidas após pagamento aprovado
        Schema::create('upsells', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('checkout_id'); // checkout de origem
            $table->uuid('product_id'); // produto do upsell
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('headline', 500)->nullable(); // headline persuasiva
            $table->string('call_to_action', 255)->default('Aproveitar oferta');
            $table->string('decline_text', 255)->default('Não, obrigado');
            $table->string('image_url', 500)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->integer('price'); // preço em centavos
            $table->integer('original_price')->nullable();
            $table->unsignedSmallInteger('discount_percent')->nullable();
            $table->enum('type', ['upsell', 'downsell'])->default('upsell');
            $table->uuid('next_upsell_id')->nullable(); // encadeamento: próximo upsell se aceitar
            $table->uuid('downsell_id')->nullable(); // se recusar, mostra este downsell
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('one_click_purchase')->default(true); // compra com 1 clique (reutiliza cartão)
            $table->unsignedInteger('timer_seconds')->nullable(); // timer de urgência
            $table->jsonb('settings')->default('{}'); // customizações
            $table->jsonb('benefits')->default('[]'); // lista de benefícios
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('conversions_count')->default(0);
            $table->unsignedBigInteger('total_revenue')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('checkout_id')->references('id')->on('checkouts')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->index(['checkout_id', 'is_active', 'sort_order']);
            $table->index('account_id');
        });

        // Pivot: registros de order bumps/upsells aceitos em cada cobrança
        Schema::create('charge_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('charge_id');
            $table->uuid('account_id');
            $table->enum('offer_type', ['order_bump', 'upsell', 'downsell']);
            $table->uuid('offer_id'); // ID do order_bump ou upsell
            $table->uuid('product_id');
            $table->integer('amount'); // valor cobrado
            $table->enum('status', ['pending', 'accepted', 'declined', 'paid', 'refunded'])->default('pending');
            $table->uuid('upsell_charge_id')->nullable(); // charge separado para upsell (pós-compra)
            $table->timestamps();

            $table->foreign('charge_id')->references('id')->on('charges')->onDelete('cascade');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->index(['charge_id', 'offer_type']);
            $table->index(['offer_id', 'offer_type']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_offers');
        Schema::dropIfExists('upsells');
        Schema::dropIfExists('order_bumps');
    }
};
