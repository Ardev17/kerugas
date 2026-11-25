<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localização dos condutores (Novembro 2026, decisão do cliente): a app do
 * condutor envia a posição sempre (também em segundo plano), e só o Super
 * Admin geral e o Provincial a veem, para escolherem o condutor mais perto
 * do cliente. Guarda-se só a ÚLTIMA posição (sem histórico de percursos).
 *
 * Colunas próprias,`users.latitude/longitude` é a morada do cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('driver_latitude', 10, 7)->nullable()->after('driver_available');
            $table->decimal('driver_longitude', 10, 7)->nullable()->after('driver_latitude');
            $table->unsignedInteger('driver_location_accuracy')->nullable()->after('driver_longitude');
            $table->timestamp('driver_location_at')->nullable()->after('driver_location_accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['driver_latitude', 'driver_longitude', 'driver_location_accuracy', 'driver_location_at']);
        });
    }
};
