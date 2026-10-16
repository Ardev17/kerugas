<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registo de carregamentos pela central e por SMS (Outubro 2026).
 *
 * bottle_pickups.source: APP (o condutor), CENTRAL (um admin regista por ele,
 * com motivo, e só conta para pagamento depois de confirmado) ou SMS.
 *
 * users: interruptor "registo pela central" por condutor (só o Super Admin e
 * o Super Admin Provincial ligam/desligam; caduca sozinho) e o PIN do SMS
 * (guardado cifrado, com bloqueio por tentativas falhadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bottle_pickups', function (Blueprint $table) {
            $table->string('source', 10)->default('APP')->after('driver_id');
            $table->foreignId('registered_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->string('central_channel', 12)->nullable()->after('registered_by');
            $table->string('central_reason', 255)->nullable()->after('central_channel');
            // Só conta para pagar ao posto depois de confirmado (registo da central, ou SMS com PIN provisório).
            $table->boolean('needs_confirmation')->default(false)->after('central_reason');
            $table->index(['source', 'confirmed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('central_pickup_enabled_at')->nullable();
            $table->timestamp('central_pickup_expires_at')->nullable();
            $table->foreignId('central_pickup_enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('central_pickup_reason', 255)->nullable();
            $table->string('sms_pin_hash')->nullable();
            // PIN definido por um admin ao criar a conta: provisório até o condutor o mudar na app.
            $table->boolean('sms_pin_temporary')->default(false);
            $table->unsignedTinyInteger('sms_pin_failures')->default(0);
            $table->timestamp('sms_pin_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('central_pickup_enabled_by');
            $table->dropColumn([
                'central_pickup_enabled_at', 'central_pickup_expires_at', 'central_pickup_reason',
                'sms_pin_hash', 'sms_pin_temporary', 'sms_pin_failures', 'sms_pin_locked_until',
            ]);
        });

        Schema::table('bottle_pickups', function (Blueprint $table) {
            $table->dropIndex(['source', 'confirmed_at']);
            $table->dropConstrainedForeignId('registered_by');
            $table->dropColumn(['source', 'central_channel', 'central_reason', 'needs_confirmation']);
        });
    }
};
