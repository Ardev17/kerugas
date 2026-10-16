<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscrições mensais (Outubro 2026).
 *
 * `subscriptions`: um registo por PERÍODO PAGO. Renovar não altera o
 * registo anterior — cria outro a seguir, por isso o histórico de
 * pagamentos (comprovativos recebidos por WhatsApp, valor, quem ativou)
 * fica sempre completo. A ativação é manual, feita pelo Super Admin.
 *   - SMS_CONSULT: cliente identificado pelo telemóvel (consulta de gás
 *     por SMS na Central de Operações, depois das consultas grátis).
 *   - STATION_ANALYTICS: posto (analytics completo do operador).
 *
 * `app_settings`: pares chave/valor editáveis pelo Super Admin sem mexer
 * em código (interruptor de obrigatoriedade, consultas grátis, IBAN…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable(); // JSON
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // SMS_CONSULT | STATION_ANALYTICS
            $table->string('phone')->nullable(); // +244…, só SMS_CONSULT
            $table->foreignId('station_id')->nullable()->constrained('stations')->cascadeOnDelete();
            $table->string('status')->default('ACTIVE'); // ACTIVE | CANCELLED (expirada calcula-se pela data)
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('months')->default(1);
            $table->unsignedBigInteger('amount_kz')->nullable(); // valor pago (registo interno; nunca vai em SMS)
            $table->string('payment_reference')->nullable(); // ex.: "Comprovativo BAI via WhatsApp, 04/10"
            $table->text('notes')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'phone', 'expires_at']);
            $table->index(['type', 'station_id', 'expires_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('app_settings');
    }
};
