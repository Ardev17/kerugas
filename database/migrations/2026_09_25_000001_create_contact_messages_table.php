<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensagens do formulário de Contacto público do site (Setembro 2026).
 * Guardadas mesmo quando o envio de email falha (ex.: SMTP mal
 * configurado no `.env`) — o Admin continua a conseguir ler tudo em
 * /admin/contact-messages sem depender só de o email ter chegado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Email OU telefone — o formulário público aceita qualquer um
            // dos dois (o cliente pode não ter email), por isso texto
            // livre em vez de um campo `email` validado.
            $table->string('contact');
            $table->text('message');
            // NEW -> READ, marcado manualmente por um Admin (ver
            // ContactMessageController::markRead()).
            $table->string('status')->default('NEW');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
