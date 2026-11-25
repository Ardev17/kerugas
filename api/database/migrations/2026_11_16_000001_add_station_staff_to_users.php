<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pessoal do posto (Novembro 2026, decisão do cliente):
 *
 * - `managed_station_id`,Admin de Posto (gerente, papel STATION_MANAGER).
 *   Um posto pode ter vários gerentes; cada gerente pertence a um só posto.
 * - `driver_station_id`,Condutor de Posto (papel STATION_DRIVER). Um posto
 *   pode ter vários; cada condutor pertence a um só posto.
 *
 * Colunas próprias (e não `operated_station_id`) para que a lista de
 * OPERADORES de um posto nunca inclua gerentes nem condutores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('managed_station_id')->nullable()->after('operated_station_id')
                ->constrained('stations')->nullOnDelete();
            $table->foreignId('driver_station_id')->nullable()->after('managed_station_id')
                ->constrained('stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_station_id');
            $table->dropConstrainedForeignId('managed_station_id');
        });
    }
};
