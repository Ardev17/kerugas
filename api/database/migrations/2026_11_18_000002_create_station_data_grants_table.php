<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excepções por pessoa (Novembro 2026).
 *
 * O município é a regra geral; isto é a excepção, e a excepção ganha sempre:
 *
 *   ALLOW -> esta pessoa consulta mesmo que o município esteja fechado
 *   DENY  -> esta pessoa não consulta mesmo que o município esteja aberto
 *
 * `expires_at` existe para quem paga um período: põe-se a data e o acesso
 * fecha-se sozinho no fim, sem ninguém ter de voltar lá. Null = sem prazo.
 *
 * Uma linha por utilizador: a regra mais recente substitui a anterior, e
 * "voltar ao normal" é apagar a linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_data_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('effect', 10);
            $table->timestamp('expires_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['effect', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_data_grants');
    }
};
