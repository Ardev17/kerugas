<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto de perfil (Outubro 2026). Guarda só o caminho RELATIVO do ficheiro
 * gerado pelo servidor (ex.: `avatars/<40 hex>.webp`) no disco privado —
 * nunca o nome original nem um URL. Ver App\Services\Media\AvatarService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path', 120)->nullable()->after('can_promote_users');
            $table->index('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['avatar_path']);
            $table->dropColumn('avatar_path');
        });
    }
};
