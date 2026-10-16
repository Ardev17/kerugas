<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prestação de contas do condutor (Outubro 2026): o condutor cobra o
 * valor completo ao cliente e entrega o dinheiro a um Admin/Super Admin.
 * Cada linha é um "recebi X": o que o condutor ainda deve é a soma dos
 * totais dos pedidos que entregou menos a soma destes recebimentos.
 * Nunca se apaga nem edita — é um registo financeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['driver_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_cash_receipts');
    }
};
