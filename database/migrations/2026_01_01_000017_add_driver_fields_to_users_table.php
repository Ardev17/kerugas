<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condutor (Setembro 2026): reaproveita `province_id`/`municipality_id`
 * (já existentes, usados pelo registo de clientes) como a
 * província/município FIXOS do condutor — nenhuma coluna nova para
 * isso. A única coluna nova é `driver_available`, a disponibilidade
 * para receber entregas.
 *
 * Automático + manual (pedido explícito do cliente): fica `false`
 * automaticamente quando um pedido lhe é atribuído
 * (GasOrderService::assign) e volta a `true` automaticamente quando o
 * pedido é concluído/cancelado (GasOrderService::complete/cancel), mas
 * o próprio condutor também pode alterná-lo manualmente a qualquer
 * momento (DriverOrderController::toggleAvailability) — ex: para se
 * marcar indisponível por almoço/fim de turno mesmo sem entrega em
 * curso.
 *
 * Default `true`: irrelevante para quem não é DRIVER, mas assim uma
 * conta promovida a Condutor já começa "disponível" em vez de invisível
 * por engano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('driver_available')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('driver_available');
        });
    }
};
