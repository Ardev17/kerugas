<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\MunicipalityInvestment;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Investidores por município (Outubro 2026): quem vê o quê.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->catumbela = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Catumbela']);
    $this->ganda = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Ganda']);

    $this->geral = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->provincial = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->benguela->id]);
    $this->admin = User::factory()->admin()->create(['province_id' => $this->benguela->id]);

    $this->ana = User::factory()->create(['name' => 'Ana Investidora', 'province_id' => $this->benguela->id]);
    $this->rui = User::factory()->create(['name' => 'Rui Investidor', 'province_id' => $this->benguela->id]);
});

function invOrder(Municipality $m, array $o = []): GasOrder
{
    return GasOrder::factory()->create(array_merge([
        'province_id' => $m->province_id, 'municipality_id' => $m->id, 'status' => 'DELIVERED',
        'bottle_price' => 10000, 'commission' => 2000, 'delivery_price' => 12000,
        'price_set_at' => now(), 'completed_at' => now(),
    ], $o));
}

function invAssign(User $by, User $investor, Municipality $m, float $pct, array $extra = [])
{
    Sanctum::actingAs($by);

    return test()->postJson('/api/v1/admin/investors', ['user_id' => $investor->id, 'municipality_id' => $m->id, 'percentage' => $pct] + $extra);
}

it('só o Super Admin geral associa investidores (Provincial e Admin levam 403)', function () {
    invAssign($this->provincial, $this->ana, $this->lobito, 10)->assertForbidden();
    invAssign($this->admin, $this->ana, $this->lobito, 10)->assertForbidden();
    invAssign($this->ana, $this->ana, $this->lobito, 10)->assertForbidden();

    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    expect($this->ana->fresh()->role)->toBe(UserRole::INVESTOR);
    expect(AuditLog::where('action', 'investor.assigned')->exists())->toBeTrue();
    expect(AppNotification::where('user_id', $this->ana->id)->where('title', 'Foi associado como investidor')->exists())->toBeTrue();
});

it('um município só tem um investidor; um investidor pode ter vários municípios', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    invAssign($this->geral, $this->ana, $this->catumbela, 15)->assertCreated();

    invAssign($this->geral, $this->rui, $this->lobito, 20)->assertStatus(422)->assertJsonValidationErrors('municipality_id');
    expect(MunicipalityInvestment::active()->where('municipality_id', $this->lobito->id)->count())->toBe(1);
});

it('contas de gestão, condutores e operadores não podem ser investidores', function () {
    foreach ([UserRole::ADMIN, UserRole::DRIVER, UserRole::STATION_OPERATOR, UserRole::PROVINCE_SUPER_ADMIN] as $role) {
        $u = User::factory()->create(['role' => $role, 'province_id' => $this->benguela->id]);
        invAssign($this->geral, $u, $this->ganda, 10)->assertStatus(422);
    }
    invAssign($this->geral, $this->ana, $this->ganda, 0)->assertStatus(422);
    invAssign($this->geral, $this->ana, $this->ganda, 120)->assertStatus(422);
});

it('o investidor vê o total, a comissão e a sua parte — só do seu município', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    invAssign($this->geral, $this->rui, $this->catumbela, 50)->assertCreated();

    $this->travel(1)->minutes();
    invOrder($this->lobito);                         // 12 000 / comissão 2 000
    invOrder($this->lobito, ['delivery_price' => 6000, 'bottle_price' => 5000, 'commission' => 1000]);
    invOrder($this->catumbela);                      // é do Rui
    invOrder($this->lobito, ['status' => 'CANCELLED']); // não conta

    Sanctum::actingAs($this->ana->fresh());
    $r = $this->getJson('/api/v1/investor/overview')->assertOk();
    expect($r->json('data.summary.orders'))->toBe(2)
        ->and($r->json('data.summary.collected_total'))->toEqual(18000)
        ->and($r->json('data.summary.commission_total'))->toEqual(3000)
        ->and($r->json('data.summary.share_total'))->toEqual(300)
        ->and(collect($r->json('data.municipalities'))->pluck('name')->all())->toBe(['Lobito']);

    // pedir o município de outro investidor → 403, nunca "zero"
    $this->getJson('/api/v1/investor/overview?municipality_id=' . $this->catumbela->id)->assertForbidden();
    $this->getJson('/api/v1/investor/overview?municipality_id=' . $this->ganda->id)->assertForbidden();
});

it('com vários municípios filtra por município ou vê o total', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    invAssign($this->geral, $this->ana, $this->catumbela, 20)->assertCreated();
    $this->travel(1)->minutes();
    invOrder($this->lobito);
    invOrder($this->catumbela);

    Sanctum::actingAs($this->ana->fresh());
    expect($this->getJson('/api/v1/investor/overview')->json('data.summary.share_total'))->toEqual(200 + 400);
    expect($this->getJson('/api/v1/investor/overview?municipality_id=' . $this->catumbela->id)->json('data.summary.share_total'))->toEqual(400);
});

it('pedidos de antes da associação não contam', function () {
    invOrder($this->lobito, ['completed_at' => now()->subDays(2)]);
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();

    Sanctum::actingAs($this->ana->fresh());
    expect($this->getJson('/api/v1/investor/overview?from=' . now()->subDays(5)->format('Y-m-d'))->json('data.summary.orders'))->toBe(0);
});

it('mudar a percentagem só vale daí para a frente', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    $this->travel(1)->minutes();
    invOrder($this->lobito); // 10% de 2000 = 200

    $this->travel(1)->minutes();
    Sanctum::actingAs($this->geral);
    $this->putJson("/api/v1/admin/investors/{$this->ana->id}/municipalities/{$this->lobito->id}", ['percentage' => 25])->assertOk();
    $this->travel(1)->minutes();
    invOrder($this->lobito); // 25% de 2000 = 500

    Sanctum::actingAs($this->ana->fresh());
    $r = $this->getJson('/api/v1/investor/overview')->assertOk();
    expect($r->json('data.summary.share_total'))->toEqual(700)
        ->and($r->json('data.municipalities.0.percentage'))->toEqual(25)
        ->and($r->json('data.periods'))->toHaveCount(2);
});

it('retirado do município deixa de o ver; sem municípios volta a Utilizador', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();

    Sanctum::actingAs($this->geral);
    $this->deleteJson("/api/v1/admin/investors/{$this->ana->id}/municipalities/{$this->lobito->id}")->assertOk();
    expect($this->ana->fresh()->role)->toBe(UserRole::USER);

    Sanctum::actingAs($this->ana->fresh());
    $this->getJson('/api/v1/investor/overview')->assertForbidden(); // já não é investidor

    // o município fica livre para outro investidor (a partir de agora)
    invAssign($this->geral, $this->rui, $this->lobito, 30)->assertCreated();
    invAssign($this->geral, $this->ana, $this->ganda, 10, ['starts_on' => now()->subYear()->format('Y-m-d')])->assertCreated();
});

it('o novo investidor não pode começar antes de o anterior sair', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    Sanctum::actingAs($this->geral);
    $this->deleteJson("/api/v1/admin/investors/{$this->ana->id}/municipalities/{$this->lobito->id}")->assertOk();

    invAssign($this->geral, $this->rui, $this->lobito, 30, ['starts_on' => now()->subDays(3)->format('Y-m-d')])
        ->assertStatus(422)->assertJsonValidationErrors('starts_on');
});

it('ninguém além do investidor e do Super Admin geral vê a faturação de investidor', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();

    foreach ([$this->provincial, $this->admin, $this->rui] as $u) {
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/investor/overview')->assertForbidden();
        $this->getJson("/api/v1/admin/investors/{$this->ana->id}/overview")->assertForbidden();
        $this->getJson('/api/v1/admin/investors')->assertForbidden();
    }

    Sanctum::actingAs($this->geral);
    $this->getJson("/api/v1/admin/investors/{$this->ana->id}/overview")->assertOk();
});

it('o investidor não entra na faturação geral nem no painel de gestão', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    Sanctum::actingAs($this->ana->fresh());

    $this->getJson('/api/v1/admin/billing')->assertForbidden();
    $this->getJson('/api/v1/admin/pickups')->assertForbidden();
    $this->getJson('/api/v1/admin/orders')->assertForbidden();
});

it('Admin e Provincial não mexem em contas de investidor; não se promove pela página Utilizadores', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();

    Sanctum::actingAs($this->provincial);
    $this->putJson("/api/v1/admin/users/{$this->ana->id}", ['status' => 'SUSPENDED'])->assertForbidden();

    Sanctum::actingAs($this->geral);
    $this->putJson("/api/v1/admin/users/{$this->rui->id}", ['role' => 'INVESTOR'])->assertStatus(422);

    // o Super Admin geral muda o papel → as associações terminam
    $this->putJson("/api/v1/admin/users/{$this->ana->id}", ['role' => 'USER'])->assertOk();
    expect(MunicipalityInvestment::active()->where('user_id', $this->ana->id)->exists())->toBeFalse();
});

// --- Conversa privada Super Admin ↔ investidor ---

function invSay(User $user, User $investor, string $body)
{
    Cache::flush();
    Sanctum::actingAs($user);

    return test()->postJson("/api/v1/rooms/investor/{$investor->id}/messages", ['body' => $body]);
}

it('a conversa é privada: só o investidor e os Super Admins gerais', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    invAssign($this->geral, $this->rui, $this->catumbela, 10)->assertCreated();

    invSay($this->geral, $this->ana->fresh(), 'Olá Ana, o relatório de outubro está pronto.')->assertCreated()
        ->assertJsonPath('data.author.type_label', 'Super Admin');
    expect(AppNotification::where('user_id', $this->ana->id)->where('title', 'Nova mensagem do Super Admin')->exists())->toBeTrue();

    invSay($this->ana->fresh(), $this->ana, 'Obrigada!')->assertCreated();
    expect(AppNotification::where('user_id', $this->geral->id)->where('title', 'like', '%(investidor) escreveu-lhe%')->exists())->toBeTrue();

    // outro investidor, Provincial e Admin: nem leem nem escrevem
    foreach ([$this->rui->fresh(), $this->provincial, $this->admin] as $u) {
        Sanctum::actingAs($u);
        $this->getJson("/api/v1/rooms/investor/{$this->ana->id}/messages")->assertForbidden();
        invSay($u, $this->ana, 'intruso')->assertForbidden();
    }

    // não há conversa de investidor com quem não é investidor
    invSay($this->geral, $this->admin, 'Olá')->assertForbidden();

    // o Super Admin vê as mensagens por ler na lista de investidores
    Sanctum::actingAs($this->geral);
    $list = collect($this->getJson('/api/v1/admin/investors')->json('data.investors'));
    expect($list->firstWhere('id', $this->ana->id)['chat_unread'])->toBe(1);
});

// --- Exportar e ativar/desativar (Outubro 2026) ---

it('o investidor exporta a sua faturação em Excel, só do seu município', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    invAssign($this->geral, $this->rui, $this->catumbela, 10)->assertCreated();
    $this->travel(1)->minutes();
    invOrder($this->lobito, ['order_reference' => 'KG-LOB-001']);
    invOrder($this->catumbela, ['order_reference' => 'KG-CAT-001']);

    Sanctum::actingAs($this->ana->fresh());
    $r = $this->get('/api/v1/investor/export?format=csv')->assertOk();
    expect($r->headers->get('content-type'))->toContain('text/csv');
    $csv = $r->streamedContent();
    expect($csv)->toContain('Faturação do investidor')->toContain('KG-LOB-001')->toContain('200,00 Kz')
        ->not->toContain('KG-CAT-001')->not->toContain('+2449'); // nunca dados de clientes

    $this->getJson('/api/v1/investor/export?format=csv&municipality_id=' . $this->catumbela->id)->assertForbidden();
});

it('só o Super Admin geral exporta a faturação de um investidor', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();

    foreach ([$this->provincial, $this->admin, $this->rui] as $u) {
        Sanctum::actingAs($u);
        $this->getJson("/api/v1/admin/investors/{$this->ana->id}/export?format=csv")->assertForbidden();
    }

    Sanctum::actingAs($this->geral);
    $this->get("/api/v1/admin/investors/{$this->ana->id}/export?format=csv")->assertOk();
    expect(AuditLog::where('action', 'investor.exported')->exists())->toBeTrue();
});

it('desativar: deixa de ver e exportar, os municípios ficam livres; a conversa continua', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    invAssign($this->geral, $this->ana, $this->catumbela, 12)->assertCreated();

    Sanctum::actingAs($this->provincial);
    $this->postJson("/api/v1/admin/investors/{$this->ana->id}/deactivate")->assertForbidden();

    Sanctum::actingAs($this->geral);
    $this->postJson("/api/v1/admin/investors/{$this->ana->id}/deactivate", ['reason' => 'Contrato terminado'])->assertOk()
        ->assertJsonCount(2, 'data.freed_municipalities');
    expect($this->ana->fresh()->role)->toBe(UserRole::INVESTOR)
        ->and(AppNotification::where('user_id', $this->ana->id)->where('title', 'A sua conta de investidor foi desativada')->exists())->toBeTrue();

    Sanctum::actingAs($this->ana->fresh());
    $this->getJson('/api/v1/investor/overview')->assertForbidden()->assertJsonPath('message', 'A conta de investidor está desativada.');
    $this->getJson('/api/v1/investor/export?format=csv')->assertForbidden();
    invSay($this->ana->fresh(), $this->ana, 'Podemos falar sobre o fecho?')->assertCreated();

    // o Lobito fica livre para outro investidor
    invAssign($this->geral, $this->rui, $this->lobito, 15)->assertCreated();
    // não se associa um investidor desativado
    invAssign($this->geral, $this->ana->fresh(), $this->ganda, 10)->assertStatus(422);

    Sanctum::actingAs($this->geral);
    $row = collect($this->getJson('/api/v1/admin/investors')->json('data.investors'))->firstWhere('id', $this->ana->id);
    expect($row['active'])->toBeFalse()->and($row['paused_municipalities'])->toHaveCount(2);
});

it('reativar: volta aos municípios livres com a mesma percentagem, a partir de agora', function () {
    invAssign($this->geral, $this->ana, $this->lobito, 10)->assertCreated();
    invAssign($this->geral, $this->ana, $this->catumbela, 12)->assertCreated();
    Sanctum::actingAs($this->geral);
    $this->postJson("/api/v1/admin/investors/{$this->ana->id}/deactivate")->assertOk();
    invAssign($this->geral, $this->rui, $this->lobito, 15)->assertCreated(); // entretanto ocupado

    $this->travel(1)->minutes();
    Sanctum::actingAs($this->geral);
    $this->postJson("/api/v1/admin/investors/{$this->ana->id}/reactivate")->assertOk()
        ->assertJsonPath('data.restored', ['Catumbela'])->assertJsonPath('data.skipped', ['Lobito']);

    $now = MunicipalityInvestment::active()->where('user_id', $this->ana->id)->get();
    expect($now)->toHaveCount(1)->and($now[0]->percentage)->toEqual(12.0);

    Sanctum::actingAs($this->ana->fresh());
    $this->getJson('/api/v1/investor/overview')->assertOk();
});
