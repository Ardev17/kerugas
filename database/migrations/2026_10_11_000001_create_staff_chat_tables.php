<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat da Equipa (Outubro 2026) — a sala única onde os admins de todo o país
 * conversam (Super Admin, Super Admin Provincial e Admin). À parte dos
 * Vizinhos e do chat de condutores. As fotos usam a mesma pasta privada
 * `community/` (por isso entram nos backups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('reply_to_id')->nullable()->constrained('staff_chat_messages')->nullOnDelete();
            $table->string('image_path', 120)->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            // Removida pelo Super Admin (com motivo; reversível até à eliminação total).
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 200)->nullable();
            // Apagada pelo próprio autor: sai do chat, o Super Admin continua a ver o registo.
            $table->timestamp('author_deleted_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('pinned_at');
        });

        Schema::create('staff_chat_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('staff_chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['message_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('staff_chat_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('staff_chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 8);
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('staff_chat_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('muted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 200)->nullable();
            $table->timestamp('expires_at')->nullable(); // null = permanente
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'lifted_at']);
        });
    }

    public function down(): void
    {
        foreach (['staff_chat_mutes', 'staff_chat_reactions', 'staff_chat_mentions', 'staff_chat_messages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
