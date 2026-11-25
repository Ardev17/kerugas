<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presença paga do posto na aplicação (Novembro 2026, a dormir).
 *
 * Terceira via de receita B2B, pensada para uma fase bem mais à frente: quando
 * a KeruGás já tiver postos próprios e aderência que chegue, o posto parceiro
 * passa a pagar uma taxa para estar listado.
 *
 * NADA DISTO FAZ EFEITO enquanto a definição `listing_enforcement` estiver
 * desligada, e ela nasce desligada. As colunas ficam criadas para que a
 * mudança, quando chegar, seja uma decisão de negócio e não uma migração.
 *
 *   listing_override: INHERIT -> decide a subscrição de presença
 *                     ALLOW   -> aparece sempre, sem pagar
 *                     BLOCK   -> não aparece, mesmo tendo pago
 *   platform_owned:   posto da própria KeruGás. Nunca paga e nunca sai do
 *                     mapa,é o que garante que a aplicação continua útil
 *                     ao cliente mesmo que um parceiro deixe de pagar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('listing_override', 10)->default('INHERIT')->after('orders_override_at');
            $table->string('listing_override_reason')->nullable()->after('listing_override');
            $table->foreignId('listing_override_by_id')->nullable()->after('listing_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('listing_override_at')->nullable()->after('listing_override_by_id');
            $table->boolean('platform_owned')->default(false)->after('listing_override_at');

            $table->index(['listing_override', 'platform_owned']);
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropIndex(['listing_override', 'platform_owned']);
            $table->dropConstrainedForeignId('listing_override_by_id');
            $table->dropColumn(['listing_override', 'listing_override_reason', 'listing_override_at', 'platform_owned']);
        });
    }
};
