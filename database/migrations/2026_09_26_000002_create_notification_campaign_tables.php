<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campanhas de notificações (Setembro 2026).
 *
 * - notification_campaigns: só as AFINAÇÕES que o Super Admin fizer
 *   (ligar/desligar, SMS, textos). As campanhas em si e os textos por
 *   omissão vivem no código (App\Services\Campaigns\CampaignCatalog) —
 *   uma campanha sem linha aqui funciona com os valores por omissão, por
 *   isso não é preciso correr nenhum seeder em produção.
 * - campaign_deliveries: cada envio a cada pessoa. Serve para não repetir
 *   (dedupe_key único), para o limite de promoções por semana, e para
 *   medir resultados (pedido feito até 48h depois → converted_order_id).
 * - broadcasts: envios manuais do Super Admin (quem, a quem, quando).
 * - web_push_subscriptions: navegadores inscritos para Web Push (VAPID).
 * - users.notification_preferences: o que cada pessoa aceita receber.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            // null = segue o catálogo (CampaignCatalog). Só guarda valor
            // quando o Super Admin muda algo — assim editar só os textos
            // nunca desliga, por engano, o SMS que vem ligado por omissão.
            $table->boolean('enabled')->nullable();
            $table->boolean('sms_enabled')->nullable();
            // [{title, body, sms}] — variantes; null = textos por omissão.
            $table->json('templates')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('audience', 20); // users, drivers, operators, admins, all
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained('municipalities')->nullOnDelete();
            $table->string('kind', 20)->default('announcement'); // announcement | promo
            $table->string('title', 120);
            $table->string('body', 500);
            $table->string('path', 190)->nullable();
            $table->boolean('send_sms')->default(false);
            $table->string('sms_text', 160)->nullable();
            $table->string('status', 20)->default('SCHEDULED'); // SCHEDULED, SENDING, SENT, CANCELLED, FAILED
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sms_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('campaign_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_key', 60);
            $table->foreignId('broadcast_id')->nullable()->constrained('broadcasts')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Chave anti-repetição (ex: "gas_reminder:12:order:345") — a
            // mesma pessoa nunca recebe duas vezes o mesmo lembrete.
            $table->string('dedupe_key', 190)->nullable()->unique();
            $table->unsignedTinyInteger('variant')->default(0);
            $table->boolean('marketing')->default(false);
            $table->foreignId('app_notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->string('sms_status', 20)->nullable(); // null (não enviado), QUEUED, SENT, FAILED
            $table->foreignId('converted_order_id')->nullable()->constrained('gas_orders')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['user_id', 'sent_at']);
            $table->index(['campaign_key', 'sent_at']);
        });

        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->text('endpoint');
            // Endpoints podem passar dos 500 carateres — o índice único é
            // sobre o hash, não sobre o texto.
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            if (Schema::hasTable('personal_access_tokens')) {
                $table->foreign('personal_access_token_id')
                    ->references('id')->on('personal_access_tokens')
                    ->cascadeOnDelete();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('sms_daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('campaign_deliveries');
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('notification_campaigns');
    }
};
