<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relatórios de erros do site e do app (Outubro 2026). Um registo por erro
 * DIFERENTE (fingerprint), com contagem — nunca uma linha por ocorrência.
 * Apagados 90 dias depois da última ocorrência (kerugas:client-errors-prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_errors', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('source', 10); // web | android
            $table->string('name', 120)->nullable();
            $table->string('message', 500);
            $table->text('stack')->nullable();
            $table->string('url_path', 255)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_errors');
    }
};
