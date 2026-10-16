<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat por município (Setembro 2026): admins, super admins e
 * condutores trocam mensagens tipo "alguém conhece o local X no
 * município Z?" para ajudar entregas quando a geolocalização exata
 * falha.
 *
 * `province_id` é denormalizado a partir do município no momento do
 * envio (nunca lido de `municipality.province_id` em cada consulta) —
 * o mesmo padrão de "guardar explicitamente para nunca ficar ambíguo"
 * já usado em `delivery_price`/`price_set_by`. Torna a query de
 * âmbito do Admin (por província, com filtro opcional por município)
 * um único `where`, sem join.
 *
 * ÂMBITO DE VISIBILIDADE (pedido explícito do cliente):
 *   - Condutor: só mensagens do seu próprio município.
 *   - Admin: só mensagens da sua própria província, com filtro
 *     opcional por município dentro dela.
 *   - Super Admin: tudo, com filtro opcional por província e depois
 *     por município.
 * Aplicado em ChatController::index, seguindo o mesmo padrão de dois
 * pontos de bloqueio de ScopesToAdminProvince (aqui só há um ponto,
 * porque não há "mostrar uma mensagem específica por ID").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('province_id')->constrained('provinces')->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['province_id', 'municipality_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
