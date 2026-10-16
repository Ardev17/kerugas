<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos push de um pedido para quem NÃO tem conta (Outubro 2026).
 *
 * A maioria dos clientes pede gás sem criar conta, por isso as
 * subscrições de `web_push_subscriptions` (ligadas a um utilizador) não
 * lhes servem. Aqui a subscrição fica ligada ao PEDIDO, com a mesma
 * prova de posse do acompanhamento (referência + telefone), e apaga-se
 * quando o pedido termina ou é apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gas_order_id')->constrained('gas_orders')->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            // O mesmo navegador pode seguir pedidos diferentes, mas só uma vez cada.
            $table->unique(['gas_order_id', 'endpoint_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_push_subscriptions');
    }
};
