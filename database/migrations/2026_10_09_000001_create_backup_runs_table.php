<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cópias de segurança (Outubro 2026): uma linha por vez que o Super Admin
 * geral carrega em "Fazer backup". Guarda só o estado e os nomes dos
 * ficheiros — os dados da cópia ficam em disco (ver config/backup.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 12)->default('manual'); // manual | scheduled
            $table->string('status', 12)->default('pending'); // pending | running | completed | failed
            $table->boolean('include_files')->default(true);
            $table->string('db_file')->nullable();
            $table->unsignedBigInteger('db_bytes')->nullable();
            $table->char('db_sha256', 64)->nullable();
            $table->string('files_file')->nullable();
            $table->unsignedBigInteger('files_bytes')->nullable();
            $table->unsignedInteger('files_count')->nullable();
            $table->text('error')->nullable();
            $table->text('warning')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('purged_at')->nullable(); // ficheiros já apagados (retenção ou pelo Super Admin)
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
