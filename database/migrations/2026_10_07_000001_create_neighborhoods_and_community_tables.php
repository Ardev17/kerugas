<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vizinhos KeruGás (Outubro 2026) — a comunidade de cada município.
 *
 * - `neighborhoods`: os bairros de cada município (geridos pelos admins).
 * - `users.neighborhood_id`: o bairro da pessoa (opcional; vai no perfil e no login).
 * - `users.last_seen_at`: "online agora / visto há X" (ver CommunityPresence).
 * - `community_*`: mensagens, menções, reações, denúncias, silenciamentos,
 *   "esconder pessoa" e palavras proibidas. Tudo à parte do chat de
 *   condutores e dos comentários dos postos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['municipality_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('neighborhood_id')->nullable()->after('municipality_id')->constrained('neighborhoods')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
            $table->timestamp('community_accepted_at')->nullable()->after('last_seen_at');
            $table->index(['municipality_id', 'last_seen_at']);
        });

        Schema::table('municipalities', function (Blueprint $table) {
            $table->boolean('community_enabled')->default(true);
        });

        Schema::create('community_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('neighborhood_id')->nullable()->constrained('neighborhoods')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('topic', 20)->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('community_messages')->nullOnDelete();
            $table->string('image_path', 120)->nullable();
            // Mensagem escrita pela "Equipa KeruGás" (Super Admin/PSA noutro município).
            $table->boolean('official')->default(false);
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            // Ocultada automaticamente (3 denúncias de pessoas diferentes), à espera de revisão.
            $table->timestamp('hidden_at')->nullable();
            // Removida pela moderação (reversível até à eliminação total).
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 200)->nullable();
            // Apagada pelo próprio autor: sai do chat, a moderação continua a ver o registo.
            $table->timestamp('author_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'id']);
            $table->index(['municipality_id', 'neighborhood_id', 'id']);
            $table->index(['user_id', 'id']);
            $table->index('pinned_at');
        });

        Schema::create('community_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('community_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['message_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('community_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('community_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 8);
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('community_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('community_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 20);
            $table->string('details', 300)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('community_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('muted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 200)->nullable();
            // null = permanente
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'lifted_at']);
        });

        Schema::create('community_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'blocked_user_id']);
        });

        Schema::create('community_banned_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 60)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['community_banned_words', 'community_blocks', 'community_mutes', 'community_reports', 'community_reactions', 'community_mentions', 'community_messages'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('municipalities', fn (Blueprint $t) => $t->dropColumn('community_enabled'));
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['municipality_id', 'last_seen_at']);
            $table->dropConstrainedForeignId('neighborhood_id');
            $table->dropColumn(['last_seen_at', 'community_accepted_at']);
        });
        Schema::dropIfExists('neighborhoods');
    }
};
