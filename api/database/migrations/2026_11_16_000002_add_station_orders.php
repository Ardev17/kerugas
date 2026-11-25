<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos aceites por postos subscritos (Novembro 2026, decisão do cliente).
 *
 * - `gas_orders.station_id`: o posto que aceitou o pedido (o primeiro a
 *   aceitar fica com ele). Pedidos de posto não entram na faturação nem na
 *   comissão da KeruGás (o posto já paga a subscrição).
 * - `subscriptions.weeks`: subscrição semanal do posto para receber os
 *   pedidos do seu município (paga em blocos de semanas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_orders', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->after('assigned_to')
                ->constrained('stations')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('station_id');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['municipality_id', 'station_id', 'status']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('weeks')->nullable()->after('months');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('weeks');
        });

        Schema::table('gas_orders', function (Blueprint $table) {
            $table->dropIndex(['municipality_id', 'station_id', 'status']);
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn('accepted_at');
            $table->dropConstrainedForeignId('station_id');
        });
    }
};
