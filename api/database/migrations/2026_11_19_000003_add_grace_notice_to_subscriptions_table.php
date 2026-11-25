<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso enviado DURANTE a tolerância da presença (Novembro 2026, a dormir).
 *
 * O lembrete que já existia é enviado ANTES de a subscrição expirar. Este é o
 * segundo: depois de expirar, a contar os dias que faltam até o posto sair do
 * mapa. Sem ele a tolerância não serve de nada,o posto desaparecia na mesma,
 * só que quatro dias mais tarde e sem ninguém ter avisado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('grace_notice_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('grace_notice_sent_at');
        });
    }
};
