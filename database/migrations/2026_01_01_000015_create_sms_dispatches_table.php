<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de SMS ENVIADOS pela Central de Operações KeruGás.
 *
 * Tabela deliberadamente separada de `sms_messages` (que regista SMS
 * recebidos dos postos via webhook — direção inbound). Esta tabela
 * cobre apenas a direção outbound: mensagens que o KeruGás gera e
 * envia através do gateway de SMS configurado, sempre após confirmação
 * manual de um admin/super admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // identificador do provider usado no envio (ex: mock, production)
            $table->string('provider_message_id')->nullable();
            $table->string('recipient'); // formato +244XXXXXXXXX
            $table->string('sender')->nullable(); // sender ID, quando aplicável
            $table->text('message');
            $table->foreignId('sms_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->string('status')->default('PENDING'); // PENDING, QUEUED, SENT, DELIVERED, FAILED, UNKNOWN
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['recipient', 'created_at']);
            $table->index(['operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_dispatches');
    }
};
