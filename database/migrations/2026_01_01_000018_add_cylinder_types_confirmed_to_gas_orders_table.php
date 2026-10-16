<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmação do(s) tipo(s) de garrafa (pedido explícito do cliente,
 * Setembro 2026): campo DISTINTO de `cylinder_type` (o tipo que o
 * cliente indicou no pedido inicial, um texto livre e opcional — pode
 * nem ter sido preenchido). `cylinder_types_confirmed` é o que o Admin
 * confirma como realmente entregue, no mesmo passo em que confirma o
 * preço (AdminOrderController::confirm) — sempre obrigatório nesse
 * momento, separado por vírgula quando há mais de um tipo/garrafa
 * (mesmo padrão de texto livre configurável que `cylinder_type` já
 * usa, sem lista fixa no código).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_orders', function (Blueprint $table) {
            $table->string('cylinder_types_confirmed')->nullable()->after('cylinder_type');
        });
    }

    public function down(): void
    {
        Schema::table('gas_orders', function (Blueprint $table) {
            $table->dropColumn('cylinder_types_confirmed');
        });
    }
};
