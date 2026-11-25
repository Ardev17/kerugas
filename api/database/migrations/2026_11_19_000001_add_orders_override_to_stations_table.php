<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interruptor do administrador para os pedidos de um posto (Novembro 2026).
 *
 * Até aqui, quem decidia se um posto recebia os pedidos do seu município era
 * só a subscrição paga. Isto põe por cima disso a mão do administrador:
 *
 *   INHERIT  -> como sempre foi: decide a subscrição paga (valor por omissão,
 *               portanto nada muda nos postos que já existem)
 *   ALLOW    -> recebe pedidos mesmo sem subscrição (cortesia, parceiro,
 *               lançamento de um mercado novo)
 *   BLOCK    -> não recebe, mesmo com a subscrição paga e em dia (travão
 *               imediato para um posto com problemas)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('orders_override', 10)->default('INHERIT')->after('analytics_enabled');
            $table->string('orders_override_reason')->nullable()->after('orders_override');
            $table->foreignId('orders_override_by_id')->nullable()->after('orders_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('orders_override_at')->nullable()->after('orders_override_by_id');

            $table->index('orders_override');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropIndex(['orders_override']);
            $table->dropConstrainedForeignId('orders_override_by_id');
            $table->dropColumn(['orders_override', 'orders_override_reason', 'orders_override_at']);
        });
    }
};
