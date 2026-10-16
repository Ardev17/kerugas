<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona:
 * 1. Localização exata OPCIONAL do utilizador no registo — usada para
 *    traçar rota de entrega quando o utilizador pede gás e pode não
 *    conseguir atender chamada ou ler mensagens.
 * 2. Tipos de garrafa disponíveis no posto — só atualizado manualmente
 *    (Admin/Operador via web); SMS nunca escreve neste campo, por isso
 *    mantém sempre o valor da última atualização manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('municipality_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->string('current_cylinder_types')->nullable()->after('current_confidence_score');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('current_cylinder_types');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
