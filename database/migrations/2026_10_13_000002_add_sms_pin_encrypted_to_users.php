<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIN de carregamento visível ao Super Admin Geral e Provincial (Outubro 2026).
 * O hash continua a ser o que valida o SMS; esta cópia é CIFRADA com a APP_KEY
 * e só é lida pela central (cada consulta fica no registo de auditoria).
 * PINs definidos antes desta versão não têm cópia — basta redefinir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('sms_pin_encrypted')->nullable()->after('sms_pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_pin_encrypted');
        });
    }
};
