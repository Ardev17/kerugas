<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eliminação de conta pela própria pessoa (Outubro 2026 — exigência da
 * Google Play). Pedido → 7 dias de arrependimento (entrar de novo cancela)
 * → anonimização. Ver App\Services\Account\AccountDeletionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('avatar_path');
            $table->timestamp('deletion_scheduled_for')->nullable()->after('deletion_requested_at');
            $table->index('deletion_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for']);
        });
    }
};
