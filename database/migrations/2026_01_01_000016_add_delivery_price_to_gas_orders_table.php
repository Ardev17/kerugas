<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturação por entrega (pedido explícito do cliente, Setembro 2026):
 * o preço da entrega é acertado por telefone entre o Admin e o cliente
 * (varia com a distância à Central, mas isso é critério humano de
 * negociação — nunca calculado automaticamente pelo sistema) e só é
 * registado no momento em que o Admin confirma o pedido
 * (`AdminOrderController::confirm`), nunca antes.
 *
 * `price_set_by`/`price_set_at` guardam quem registou o valor
 * originalmente — é ISSO que decide "quanto este Admin faturou", não a
 * província do pedido (um pedido é sempre da província do Admin que o
 * confirmou, já que o âmbito por província impede agir fora dela, mas
 * guardar explicitamente evita qualquer ambiguidade se essa regra
 * mudar um dia).
 *
 * `price_corrected_by`/`price_corrected_at` existem à parte, porque só
 * o SUPER_ADMIN pode corrigir um valor já registado (ex: erro de
 * digitação) — nunca sobrescrevem quem fez o registo original, só
 * documentam a última correção para exibição rápida sem precisar de
 * ir ao `AuditLog` (que continua a guardar o histórico completo,
 * incluindo valor antigo/novo, em `AdminOrderController::updatePrice`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_orders', function (Blueprint $table) {
            $table->decimal('delivery_price', 12, 2)->nullable()->after('cylinder_type');
            $table->foreignId('price_set_by')->nullable()->after('delivery_price')->constrained('users')->nullOnDelete();
            $table->timestamp('price_set_at')->nullable()->after('price_set_by');
            $table->foreignId('price_corrected_by')->nullable()->after('price_set_at')->constrained('users')->nullOnDelete();
            $table->timestamp('price_corrected_at')->nullable()->after('price_corrected_by');
        });
    }

    public function down(): void
    {
        Schema::table('gas_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_set_by');
            $table->dropConstrainedForeignId('price_corrected_by');
            $table->dropColumn(['delivery_price', 'price_set_at', 'price_corrected_at']);
        });
    }
};
