<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Divisão do valor do pedido (Outubro 2026): a KeruGas é intermediária.
 * O cliente paga um total (`delivery_price` — o que ele vê e o que o
 * condutor cobra) que se divide em:
 *   - `bottle_price`: valor real das garrafas (custo, vai para os postos);
 *   - `commission`: comissão de entrega (da KeruGas).
 * Invariante: delivery_price = bottle_price + commission.
 *
 * O pedido NÃO tem posto: sai do stock da KeruGás que o condutor leva
 * consigo. O posto só aparece no CARREGAMENTO (bottle_pickups).
 * `stock_driver_id` = condutor de cujo stock saíram as garrafas (gravado
 * ao concluir) — o stock só conta pedidos com este campo, por isso os
 * pedidos antigos nunca deixam o stock negativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_orders', function (Blueprint $table) {
            $table->decimal('bottle_price', 12, 2)->nullable()->after('delivery_price');
            $table->decimal('commission', 12, 2)->nullable()->after('bottle_price');
            $table->foreignId('stock_driver_id')->nullable()->after('commission')->constrained('users')->nullOnDelete();

            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('gas_orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropConstrainedForeignId('stock_driver_id');
            $table->dropColumn(['bottle_price', 'commission']);
        });
    }
};
