<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limite diário de SMS por Admin (Setembro 2026, pedido explícito do
 * cliente) — controlo de custo/auditoria: cada Admin pode ter o seu
 * próprio limite de SMS enviados por dia através da Central de
 * Operações, definido pelo Super Admin em /admin/users.
 *
 * `null` = sem limite (comportamento atual, não regressivo — nenhum
 * Admin existente fica bloqueado por esta migração). Só faz sentido
 * para ADMIN (quem usa a Central de Operações), mas mantido como um
 * campo genérico em `users` em vez de uma tabela à parte — mesma
 * decisão de simplicidade já usada para outros campos por-utilizador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('sms_daily_limit')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_daily_limit');
        });
    }
};
