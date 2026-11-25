<?php

use App\Enums\StationListingOverride;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);

    $this->parceiro = Station::factory()->create([
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
        'status' => 'ACTIVE', 'name' => 'Posto Parceiro',
    ]);
    $this->proprio = Station::factory()->create([
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
        'status' => 'ACTIVE', 'name' => 'Posto KeruGás', 'platform_owned' => true,
    ]);

    $this->global = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
});

function exigirPresenca(bool $ligado = true): void
{
    app(SubscriptionSettings::class)->update(['listing_enforcement' => $ligado]);
}

function pagarPresenca(Station $s, int $semanas = 4): Subscription
{
    return Subscription::create([
        'type' => SubscriptionType::STATION_LISTING, 'station_id' => $s->id,
        'status' => Subscription::STATUS_ACTIVE, 'starts_at' => now()->subDay(),
        'expires_at' => now()->addWeeks($semanas), 'months' => 0, 'weeks' => $semanas,
        'amount_kz' => 0, 'activated_by' => test()->global->id,
    ]);
}

// ─────────────────────── o mecanismo nasce a dormir

it('por omissão o mecanismo está desligado e todos os postos aparecem', function () {
    expect(app(SubscriptionSettings::class)->listingEnforced())->toBeFalse();

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/stations/{$this->parceiro->id}")->assertOk();
});

it('desligado, nem um posto marcado como "não aparece" desaparece', function () {
    $this->parceiro->update(['listing_override' => StationListingOverride::BLOCK]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/stations/{$this->parceiro->id}")->assertOk();
});

it('desligado, não se faz uma única consulta por causa da presença', function () {
    $consultas = 0;
    DB::listen(function ($q) use (&$consultas) {
        if (str_contains($q->sql, 'listing_override')) {
            $consultas++;
        }
    });

    $this->getJson('/api/v1/map/stations')->assertOk();

    expect($consultas)->toBe(0);
});

// ─────────────────────── ligado

it('ligado, o posto parceiro sem pagar sai do mapa e o próprio fica', function () {
    exigirPresenca();

    $this->getJson('/api/v1/map/stations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Posto KeruGás');

    $this->getJson("/api/v1/stations/{$this->parceiro->id}")->assertNotFound();
    $this->getJson("/api/v1/stations/{$this->proprio->id}")->assertOk();
});

it('ligado, pagar a presença devolve o posto ao mapa', function () {
    exigirPresenca();
    pagarPresenca($this->parceiro);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/stations/{$this->parceiro->id}")->assertOk();
});

it('ligado, uma subscrição expirada há muito não vale', function () {
    exigirPresenca();
    // Para além dos dias de tolerância,expirada ontem ainda conta (ver abaixo).
    pagarPresenca($this->parceiro)->update(['expires_at' => now()->subDays(10)]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(1, 'data');
});

it('ligado, "aparece sempre" dispensa o pagamento', function () {
    exigirPresenca();
    $this->parceiro->update(['listing_override' => StationListingOverride::ALLOW]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(2, 'data');
});

it('ligado, "não aparece" ganha à subscrição paga', function () {
    exigirPresenca();
    pagarPresenca($this->parceiro);
    $this->parceiro->update(['listing_override' => StationListingOverride::BLOCK]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(1, 'data');
});

it('ligado, os postos próprios nunca saem, mesmo marcados como "não aparece"', function () {
    exigirPresenca();
    $this->proprio->update(['listing_override' => StationListingOverride::BLOCK]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Posto KeruGás');
});

it('ligado, a lista de postos próximos respeita a presença', function () {
    exigirPresenca();

    $this->getJson("/api/v1/stations/nearby?municipality_id={$this->lobito->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Posto KeruGás');
});

// ─────────────────────── painel

it('o Super Admin muda a presença e marca um posto como próprio, com auditoria', function () {
    Sanctum::actingAs($this->global);

    $this->postJson("/api/v1/admin/stations/{$this->parceiro->id}/listing",
        ['override' => 'BLOCK', 'reason' => 'Deixou de pagar'])
        ->assertOk()
        ->assertJsonPath('data.listing_override', 'BLOCK')
        ->assertJsonPath('meta.listing_enforced', false);

    $this->postJson("/api/v1/admin/stations/{$this->parceiro->id}/listing", ['platform_owned' => true])
        ->assertOk()
        ->assertJsonPath('data.platform_owned', true);

    $registo = AuditLog::where('action', 'station.listing_changed')->oldest('id')->first();
    expect($registo->old_values['listing_override'])->toBe('INHERIT')
        ->and($registo->new_values['listing_override'])->toBe('BLOCK')
        ->and($registo->new_values['reason'])->toBe('Deixou de pagar');
});

it('voltar a INHERIT limpa o motivo e o autor', function () {
    Sanctum::actingAs($this->global);

    $this->postJson("/api/v1/admin/stations/{$this->parceiro->id}/listing", ['override' => 'ALLOW', 'reason' => 'Parceiro'])->assertOk();
    $this->postJson("/api/v1/admin/stations/{$this->parceiro->id}/listing", ['override' => 'INHERIT'])
        ->assertOk()
        ->assertJsonPath('data.listing_override_reason', null);

    expect($this->parceiro->fresh()->listing_override_by_id)->toBeNull();
});

it('o Provincial não mexe em postos de outra província e o Admin comum não mexe de todo', function () {
    $huila = Province::factory()->create();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $huila->id]));
    $this->postJson("/api/v1/admin/stations/{$this->parceiro->id}/listing", ['override' => 'BLOCK'])->assertForbidden();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN, 'province_id' => $this->benguela->id]));
    $this->postJson("/api/v1/admin/stations/{$this->parceiro->id}/listing", ['override' => 'BLOCK'])->assertForbidden();
});

it('a subscrição de presença é cobrada por semana, ao preço definido', function () {
    Sanctum::actingAs($this->global);
    $this->putJson('/api/v1/admin/subscriptions/settings', ['price_station_listing_week_kz' => 750])->assertOk();

    $this->postJson('/api/v1/admin/subscriptions',
        ['type' => 'STATION_LISTING', 'station_id' => $this->parceiro->id, 'weeks' => 4])
        ->assertCreated()
        ->assertJsonPath('data.amount_kz', 3000);
});

// ─────────────────────── tolerância de 4 dias

it('expirada há 2 dias, o posto continua no mapa: está em tolerância', function () {
    exigirPresenca();
    pagarPresenca($this->parceiro)->update(['expires_at' => now()->subDays(2)]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/stations/{$this->parceiro->id}")->assertOk();
});

it('ao quinto dia já saiu', function () {
    exigirPresenca();
    pagarPresenca($this->parceiro)->update(['expires_at' => now()->subDays(5)]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/stations/{$this->parceiro->id}")->assertNotFound();
});

it('a tolerância é configurável e zero faz sair no próprio dia', function () {
    exigirPresenca();
    app(SubscriptionSettings::class)->update(['listing_grace_days' => 0]);
    pagarPresenca($this->parceiro)->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/api/v1/map/stations')->assertOk()->assertJsonCount(1, 'data');
});

it('o painel mostra o dia em que o posto sai', function () {
    exigirPresenca();
    pagarPresenca($this->parceiro)->update(['expires_at' => now()->subDay()]);

    Sanctum::actingAs($this->global);
    $linha = collect($this->getJson('/api/v1/admin/stations?search=Parceiro')->assertOk()->json('data'))
        ->firstWhere('id', $this->parceiro->id);

    expect($linha['listing_grace_until'])->not->toBeNull()
        ->and(\Illuminate\Support\Carbon::parse($linha['listing_grace_until'])->isFuture())->toBeTrue();
});

it('quem está dentro do período pago não aparece em tolerância', function () {
    exigirPresenca();
    pagarPresenca($this->parceiro);

    Sanctum::actingAs($this->global);
    $linha = collect($this->getJson('/api/v1/admin/stations?search=Parceiro')->assertOk()->json('data'))
        ->firstWhere('id', $this->parceiro->id);

    expect($linha['listing_grace_until'])->toBeNull();
});

it('o comando diário avisa o operador uma única vez, durante a tolerância', function () {
    exigirPresenca();
    $operador = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->parceiro->id]);
    pagarPresenca($this->parceiro)->update(['expires_at' => now()->subDay()]);

    $this->artisan('kerugas:subscriptions-check')->assertSuccessful();
    $this->artisan('kerugas:subscriptions-check')->assertSuccessful();

    expect(\App\Models\AppNotification::where('user_id', $operador->id)->where('title', 'Presença a terminar')->count())->toBe(1);
});

it('com o mecanismo desligado o comando não avisa ninguém da presença', function () {
    $operador = User::factory()->create(['role' => UserRole::STATION_OPERATOR, 'operated_station_id' => $this->parceiro->id]);
    pagarPresenca($this->parceiro)->update(['expires_at' => now()->subDay()]);

    $this->artisan('kerugas:subscriptions-check')->assertSuccessful();

    expect(\App\Models\AppNotification::where('user_id', $operador->id)->where('title', 'Presença a terminar')->exists())->toBeFalse();
});

// ─────────────────────── portas das traseiras

it('os favoritos não deixam ver um posto sem presença', function () {
    $cliente = User::factory()->create(['role' => UserRole::USER, 'province_id' => $this->benguela->id]);
    $cliente->favorites()->create(['station_id' => $this->parceiro->id]);
    $cliente->favorites()->create(['station_id' => $this->proprio->id]);

    Sanctum::actingAs($cliente);

    // Desligado: vê os dois, como sempre.
    $this->getJson('/api/v1/favorites')->assertOk()->assertJsonCount(2, 'data');

    exigirPresenca();

    $r = $this->getJson('/api/v1/favorites')->assertOk();
    $r->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.station.name', 'Posto KeruGás')
        ->assertJsonPath('meta.hidden_count', 1);
});

it('os favoritos respeitam o bloqueio do município e explicam a falta', function () {
    $cliente = User::factory()->create(['role' => UserRole::USER, 'province_id' => $this->benguela->id]);
    $cliente->favorites()->create(['station_id' => $this->parceiro->id]);

    $this->lobito->forceFill([
        'station_data_blocked_from' => now(),
        'station_data_blocked_reason' => 'Só para assinantes.',
    ])->save();

    Sanctum::actingAs($cliente);

    $this->getJson('/api/v1/favorites')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.hidden_count', 1)
        ->assertJsonPath('meta.blocked_municipalities.0.name', 'Lobito')
        ->assertJsonPath('meta.blocked_municipalities.0.reason', 'Só para assinantes.');
});

it('uma conta bloqueada não contorna o bloqueio pelos favoritos', function () {
    $cliente = User::factory()->create(['role' => UserRole::USER, 'province_id' => $this->benguela->id]);
    $cliente->favorites()->create(['station_id' => $this->parceiro->id]);

    \App\Models\StationDataGrant::create([
        'user_id' => $cliente->id,
        'effect' => \App\Enums\StationDataAccessEffect::DENY,
    ]);

    Sanctum::actingAs($cliente);

    $this->getJson('/api/v1/favorites')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.access_block.reason', 'user_blocked');
});

it('o sitemap não anuncia postos sem presença', function () {
    exigirPresenca();

    $xml = $this->get('/api/v1/seo/sitemap-postos.xml')->assertOk()->getContent();

    expect($xml)->toContain("/postos/{$this->proprio->id}-")
        ->and($xml)->not->toContain("/postos/{$this->parceiro->id}-");
});

it('com o mecanismo desligado o sitemap anuncia tudo, como sempre', function () {
    $xml = $this->get('/api/v1/seo/sitemap-postos.xml')->assertOk()->getContent();

    expect($xml)->toContain("/postos/{$this->proprio->id}-")
        ->and($xml)->toContain("/postos/{$this->parceiro->id}-");
});
