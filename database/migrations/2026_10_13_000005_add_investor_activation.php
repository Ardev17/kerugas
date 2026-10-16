<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ativar/desativar investidor (Outubro 2026) — quando o contrato é desfeito.
 *  - users.investor_deactivated_at: a conta de investidor está desativada
 *    (deixa de ver e exportar a faturação; os municípios ficam livres);
 *  - municipality_investments.end_reason: porque terminou cada período
 *    (removed, percentage_changed, deactivated, role_changed, account_deleted)
 *    — ao reativar, só voltam os municípios que terminaram por desativação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('investor_deactivated_at')->nullable()->after('operated_station_id');
        });
        Schema::table('municipality_investments', function (Blueprint $table) {
            $table->string('end_reason', 24)->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('municipality_investments', function (Blueprint $table) {
            $table->dropColumn('end_reason');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('investor_deactivated_at');
        });
    }
};
