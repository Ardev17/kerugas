<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vários operadores por posto (Outubro 2026, decisão do cliente):
 * um posto pode ter mais de um operador, mas um operador só pertence a
 * UM posto. A ligação passa de `stations.operator_id` (um por posto) para
 * `users.operated_station_id` (um posto por operador). Os operadores atuais
 * são copiados antes de a coluna antiga sair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('operated_station_id')->nullable()->after('municipality_id')
                ->constrained('stations')->nullOnDelete();
        });

        DB::table('stations')->whereNotNull('operator_id')->orderBy('id')
            ->each(function ($s) {
                DB::table('users')->where('id', $s->operator_id)->whereNull('operated_station_id')
                    ->update(['operated_station_id' => $s->id]);
            });

        Schema::table('stations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operator_id');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('operator_id')->nullable()->after('sms_phone')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('users')->whereNotNull('operated_station_id')->orderBy('id')
            ->each(function ($u) {
                DB::table('stations')->where('id', $u->operated_station_id)->whereNull('operator_id')
                    ->update(['operator_id' => $u->id]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operated_station_id');
        });
    }
};
