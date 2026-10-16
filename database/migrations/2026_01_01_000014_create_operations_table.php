<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registo de um atendimento manual iniciado por um admin/super admin
 * na Central de Operações KeruGás.
 *
 * IMPORTANTE: esta tabela NÃO representa a receção de um SMS pelo
 * sistema. Representa apenas que um operador leu uma comunicação no
 * telefone físico da Central e iniciou manualmente um atendimento no
 * KeruGás. O campo `original_message`, quando preenchido, é sempre
 * texto introduzido manualmente pelo admin — nunca recebido
 * automaticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('phone'); // já normalizado, formato +244XXXXXXXXX
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained('municipalities')->nullOnDelete();
            $table->text('original_message')->nullable(); // introduzido manualmente pelo admin
            $table->foreignId('sms_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->string('status')->default('DRAFT'); // DRAFT, SENT, CANCELLED
            $table->timestamps();

            $table->index(['municipality_id']);
            $table->index(['admin_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
