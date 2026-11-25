<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversas por sala (Outubro 2026), com as mesmas características dos
 * Vizinhos (respostas, @menções, reações, fotos e localização):
 *
 *  - room_type = 'drivers' → conversa dos condutores; room_id = município;
 *  - room_type = 'central' → "Conversa com a Central", o canal direto entre
 *    um utilizador e a direção; room_id = id do utilizador.
 *
 * As mensagens antigas do chat por município (chat_messages) são copiadas
 * para aqui, para nada se perder. A tabela antiga fica como estava.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_messages', function (Blueprint $table) {
            $table->id();
            $table->string('room_type', 12);
            $table->unsignedBigInteger('room_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('reply_to_id')->nullable()->constrained('room_messages')->nullOnDelete();
            $table->string('image_path', 120)->nullable();
            // Partilha de localização (ponto GPS, expira sozinha).
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('location_accuracy')->nullable();
            $table->timestamp('location_expires_at')->nullable();
            $table->timestamp('location_ended_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            // Removida por um admin (com motivo).
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 200)->nullable();
            // Apagada pelo próprio autor.
            $table->timestamp('author_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['room_type', 'room_id', 'id']);
            $table->index('user_id');
        });

        Schema::create('room_message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('room_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['message_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('room_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('room_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 8);
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });

        // Até onde cada pessoa já leu cada conversa (para "mensagens novas").
        Schema::create('room_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('room_type', 12);
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'room_type', 'room_id']);
        });

        // Histórico do chat por município → conversa dos condutores.
        if (Schema::hasTable('chat_messages')) {
            DB::table('chat_messages')->orderBy('id')->chunk(500, function ($rows) {
                DB::table('room_messages')->insert($rows->map(fn ($r) => [
                    'room_type' => 'drivers',
                    'room_id' => $r->municipality_id,
                    'user_id' => $r->user_id,
                    'body' => $r->body,
                    'created_at' => $r->created_at,
                    'updated_at' => $r->updated_at,
                ])->all());
            });
        }
    }

    public function down(): void
    {
        foreach (['room_reads', 'room_message_reactions', 'room_message_mentions', 'room_messages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
