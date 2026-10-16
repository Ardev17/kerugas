<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissão por Admin para mudar o papel de utilizadores (Outubro 2026,
 * pedido explícito do cliente): promover um utilizador a Moderador ou
 * Operador deixa de ser automático para todos os Admins — o Super Admin
 * liga ou desliga a permissão Admin a Admin, em /admin/users.
 *
 * Por omissão DESLIGADA: nenhum Admin existente mantém o poder sem o
 * Super Admin o conceder. Só tem significado para role === ADMIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_promote_users')->default(false)->after('sms_daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_promote_users');
        });
    }
};
