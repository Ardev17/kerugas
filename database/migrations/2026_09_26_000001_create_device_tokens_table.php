<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemóveis registados para notificações push (app Android, Setembro
 * 2026). Um token FCM identifica UMA instalação do app num telemóvel.
 *
 * `token` é único: se o mesmo telemóvel fizer login com outra conta
 * (telemóvel partilhado, ex: posto com vários operadores por turno), o
 * registo passa para a conta nova — nunca continua a receber push da
 * conta anterior.
 *
 * `personal_access_token_id` liga o telemóvel à SESSÃO (token Sanctum)
 * com que fez login. Ao terminar sessão, esse token é apagado e o
 * registo do telemóvel vai com ele (FK em cascata, e também apagado
 * explicitamente em AuthController::logout, para o caso de a tabela do
 * Sanctum não aceitar a FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->string('token', 512)->unique();
            $table->string('platform', 20)->default('android');
            $table->string('app_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            if (Schema::hasTable('personal_access_tokens')) {
                $table->foreign('personal_access_token_id')
                    ->references('id')->on('personal_access_tokens')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
