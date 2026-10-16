<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Número do posto (Outubro 2026): 5 dígitos, sequencial e automático
 * (00001, 00002, …) — ninguém o escreve à mão. Serve para identificar o
 * posto sem ambiguidade (nomes repetem-se) e para o comando SMS
 * `CARREGAR <qtd> <número> <PIN>`.
 *
 * Aditivo: só uma coluna nova. Os postos que já existem recebem o número
 * por ordem de criação (inclui os apagados, para um número nunca repetir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('code', 5)->nullable()->unique()->after('id');
        });

        $next = 1;
        DB::table('stations')->orderBy('id')->select('id')->chunkById(200, function ($rows) use (&$next) {
            foreach ($rows as $row) {
                DB::table('stations')->where('id', $row->id)->update(['code' => sprintf('%05d', $next++)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
