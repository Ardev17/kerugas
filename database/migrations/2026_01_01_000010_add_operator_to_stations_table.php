<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Associa um utilizador com papel STATION_OPERATOR a um posto
 * (secção 94/95). Um operador só pode gerir o seu próprio posto —
 * nunca aceder a dados de outros postos (secção 95).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('operator_id')->nullable()->after('sms_phone')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operator_id');
        });
    }
};
