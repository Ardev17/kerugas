<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consulta dos postos fechada por município (Novembro 2026).
 *
 * A ideia é a torneira do período grátis: durante os primeiros meses toda a
 * gente consulta o estado dos postos sem pagar nada; depois fecha-se município
 * a município, à medida que cada mercado passa a pago.
 *
 * Guarda-se uma DATA e não um sim/não, para o bloqueio poder ficar agendado:
 * marca-se hoje o dia em que os 90 dias acabam e no dia certo fecha sozinho,
 * sem ninguém ter de se lembrar.
 *
 *   null              -> nunca bloqueado
 *   data no futuro    -> agendado, ainda aberto
 *   data no passado   -> fechado
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->timestamp('station_data_blocked_from')->nullable()->after('community_enabled');
            $table->string('station_data_blocked_reason')->nullable()->after('station_data_blocked_from');
            $table->foreignId('station_data_blocked_by_id')->nullable()->after('station_data_blocked_reason')
                ->constrained('users')->nullOnDelete();

            $table->index('station_data_blocked_from');
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropIndex(['station_data_blocked_from']);
            $table->dropConstrainedForeignId('station_data_blocked_by_id');
            $table->dropColumn(['station_data_blocked_from', 'station_data_blocked_reason']);
        });
    }
};
