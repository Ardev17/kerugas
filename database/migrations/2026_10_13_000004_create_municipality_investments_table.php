<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investidores por município (Outubro 2026).
 *
 * Cada linha é um PERÍODO: o investidor X tem Y% no município Z desde
 * `starts_at` até `ends_at` (null = ainda em vigor). Mudar a percentagem
 * fecha o período e abre outro — assim o que já foi calculado nunca muda
 * para trás (credibilidade). Regras (aplicadas em InvestorService):
 *  - um município tem no máximo UM investidor em vigor;
 *  - um investidor pode estar em vários municípios;
 *  - só o Super Admin geral associa, muda a percentagem ou retira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipality_investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('percentage', 5, 2);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['municipality_id', 'ends_at']);
            $table->index(['user_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipality_investments');
    }
};
