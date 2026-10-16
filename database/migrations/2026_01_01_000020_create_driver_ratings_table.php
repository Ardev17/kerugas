<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliação do condutor (pedido explícito do cliente, Setembro 2026):
 * o próprio cliente (identificado por referência do pedido + telefone
 * — o mesmo nível de "prova de posse" já usado nesta app, nunca exige
 * conta/login) avalia a entrega depois de `DELIVERED`. Uma avaliação
 * por pedido (`gas_order_id` único) — nunca por condutor diretamente,
 * porque é sempre uma entrega concreta que está a ser avaliada.
 *
 * `driver_id` é guardado tal como `GasOrder::assigned_to` no momento
 * da avaliação (nunca via JOIN a `gas_orders` para descobrir o
 * condutor) — mesmo padrão de "guardar explicitamente" já usado em
 * `delivery_price`/`price_set_by`, para as estatísticas do condutor
 * (`DriverStatsService`) nunca dependerem de uma segunda tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gas_order_id')->unique()->constrained('gas_orders')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1-5, validado no FormRequest
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_ratings');
    }
};
