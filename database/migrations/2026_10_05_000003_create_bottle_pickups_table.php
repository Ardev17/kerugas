<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carregamentos (Outubro 2026): o condutor vai a um posto buscar garrafas
 * para o stock da KeruGás. Regista ele (também offline — `client_uuid`
 * garante que reenvios não duplicam); o operador do posto confirma se
 * tiver internet. Sem confirmação fica "só condutor" e conta na mesma.
 *
 * Nunca se apaga nem se reduz `quantity`. O valor (`amount`) é o que se
 * deve ao posto por estas garrafas; pode ser corrigido (auditado) até
 * estar pago. "Pago ao posto" é binário por carregamento.
 *
 * `station_counter_resets`: o contador do posto (garrafas levadas pela
 * KeruGás) é reiniciado À MÃO pelo Super Admin; o histórico fica aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bottle_pickups', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 12, 2);
            $table->timestamp('registered_at');
            $table->boolean('synced_offline')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disputed_at')->nullable();
            $table->foreignId('disputed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dispute_note')->nullable();
            $table->timestamp('station_paid_at')->nullable();
            $table->foreignId('station_paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['station_id', 'registered_at']);
            $table->index(['driver_id', 'registered_at']);
            $table->index(['station_id', 'station_paid_at']);
        });

        Schema::create('station_counter_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('reset_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('bottles_before');
            $table->timestamp('reset_at');
            $table->timestamps();

            $table->index(['station_id', 'reset_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_counter_resets');
        Schema::dropIfExists('bottle_pickups');
    }
};
