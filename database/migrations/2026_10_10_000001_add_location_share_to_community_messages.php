<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vizinhos — "Partilhar a minha localização" (Outubro 2026).
 *
 * Uma mensagem pode levar um ponto no mapa (a posição do telemóvel) OU um posto
 * (a pessoa diz "estou no Posto X" sem revelar a sua posição exata). A partilha
 * dura o que a pessoa escolher (30 min, 1 h ou 2 h, sempre até às 18h) e depois
 * as coordenadas são APAGADAS da base de dados — fica só o aviso de que expirou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_messages', function (Blueprint $table) {
            // 'point' (posição do telemóvel) ou 'station' (um posto registado).
            $table->string('location_kind', 10)->nullable()->after('image_path');
            $table->decimal('location_lat', 10, 7)->nullable()->after('location_kind');
            $table->decimal('location_lng', 10, 7)->nullable()->after('location_lat');
            // Precisão do GPS em metros, como o telemóvel a mediu.
            $table->unsignedSmallInteger('location_accuracy')->nullable()->after('location_lng');
            $table->foreignId('location_station_id')->nullable()->after('location_accuracy')->constrained('stations')->nullOnDelete();
            // Nome do posto, para o aviso continuar a fazer sentido depois de expirar.
            $table->string('location_label', 120)->nullable()->after('location_station_id');
            $table->timestamp('location_expires_at')->nullable()->after('location_label');
            // Quando acabou: expirou, a pessoa parou, ou começou outra partilha.
            $table->timestamp('location_ended_at')->nullable()->after('location_expires_at');

            $table->index('location_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('community_messages', function (Blueprint $table) {
            $table->dropIndex(['location_expires_at']);
            $table->dropConstrainedForeignId('location_station_id');
            $table->dropColumn(['location_kind', 'location_lat', 'location_lng', 'location_accuracy', 'location_label', 'location_expires_at', 'location_ended_at']);
        });
    }
};
