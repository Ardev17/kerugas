<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de segurança de conta (secção 18 do master prompt):
 * rate limiting e account lockout após tentativas de login falhadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('last_login_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until']);
        });
    }
};
