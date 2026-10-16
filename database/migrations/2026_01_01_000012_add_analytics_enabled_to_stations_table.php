<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlo exclusivo do Super Admin: liga/desliga o acesso do
 * operador ao dashboard de analytics e exportação do SEU PRÓPRIO
 * posto. Nunca afeta o que o Admin/Super Admin veem — só restringe
 * o autoatendimento do operador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('analytics_enabled')->default(true)->after('operator_id');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('analytics_enabled');
        });
    }
};
