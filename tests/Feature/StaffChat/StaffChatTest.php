<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\StaffChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Chat da Equipa (Outubro 2026): sala única dos admins de todo o país.
 */
beforeEach(function () {
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->luanda = Province::factory()->create(['name' => 'Luanda']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);
    $this->viana = Municipality::factory()->create(['province_id' => $this->luanda->id, 'name' => 'Viana']);

    $this->geral = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'name' => 'Ana Geral']);
    $this->prov = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'name' => 'Paulo Provincial', 'province_id' => $this->huila->id, 'municipality_id' => $this->lubango->id]);
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Rita Admin', 'province_id' => $this->luanda->id, 'municipality_id' => $this->viana->id]);
});

function scSay(User $user, string $body, array $extra = [])
{
    Cache::flush(); // limpa o intervalo entre mensagens
    Sanctum::actingAs($user);

    return test()->postJson('/api/v1/staff-chat/messages', array_merge(['body' => $body], $extra));
}

it('só admins entram: utilizador, moderador, condutor e operador levam 403', function () {
    foreach ([UserRole::USER, UserRole::MODERATOR, UserRole::DRIVER, UserRole::STATION_OPERATOR] as $role) {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->getJson('/api/v1/staff-chat/messages')->assertForbidden();
        $this->postJson('/api/v1/staff-chat/messages', ['body' => 'olá'])->assertForbidden();
    }
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/staff-chat/messages')->assertUnauthorized();
});

it('os três tipos de admin conversam na mesma sala', function () {
    scSay($this->geral, 'Bom dia equipa')->assertCreated();
    scSay($this->prov, 'Bom dia, Huíla presente')->assertCreated();
    scSay($this->admin, 'Luanda presente')->assertCreated();

    Sanctum::actingAs($this->admin);
    $bodies = collect($this->getJson('/api/v1/staff-chat/messages')->assertOk()->json('data'))->pluck('body');
    expect($bodies->all())->toBe(['Bom dia equipa', 'Bom dia, Huíla presente', 'Luanda presente']);
});

it('cada mensagem identifica o autor por nome, tipo, província e município', function () {
    scSay($this->geral, 'a');
    scSay($this->prov, 'b');
    scSay($this->admin, 'c');

    Sanctum::actingAs($this->geral);
    $rows = collect($this->getJson('/api/v1/staff-chat/messages')->json('data'))->pluck('author')->keyBy('name');

    expect($rows['Ana Geral']['type_label'])->toBe('Admin Geral')
        ->and($rows['Paulo Provincial']['type_label'])->toBe('Admin Provincial')
        ->and($rows['Paulo Provincial']['province'])->toBe('Huíla')
        ->and($rows['Paulo Provincial']['municipality'])->toBe('Lubango')
        ->and($rows['Rita Admin']['type_label'])->toBe('Admin')
        ->and($rows['Rita Admin']['province'])->toBe('Luanda')
        ->and($rows['Rita Admin']['municipality'])->toBe('Viana')
        ->and($rows['Rita Admin'])->not->toHaveKey('phone');
});

it('entre admins, telefones e links são permitidos', function () {
    scSay($this->admin, 'Liga para 923456789 ou vê https://kerugas.ao')->assertCreated();
});

it('menciona colegas pelo nome e notifica; responder notifica o autor', function () {
    $r = scSay($this->admin, "Olá @[{$this->prov->id}] podes ver isto?")->assertCreated();
    expect($r->json('data.mentions'))->toHaveKey((string) $this->prov->id);
    expect(AppNotification::where('user_id', $this->prov->id)->where('title', 'like', '%mencionou%')->exists())->toBeTrue();

    $id = $r->json('data.id');
    scSay($this->prov, 'Já vi', ['reply_to_id' => $id])->assertCreated();
    expect(AppNotification::where('user_id', $this->admin->id)->where('title', 'like', '%respondeu%')->exists())->toBeTrue();
});

it('não se menciona quem não é admin', function () {
    $user = User::factory()->create(['role' => UserRole::USER]);
    $r = scSay($this->admin, "oi @[{$user->id}]")->assertCreated();
    expect($r->json('data.body'))->toBe('oi');
});

it('a lista de menções só tem admins', function () {
    User::factory()->create(['role' => UserRole::USER, 'name' => 'Rita Cliente']);
    Sanctum::actingAs($this->geral);
    $names = collect($this->getJson('/api/v1/staff-chat/mentionable?q=Rita')->assertOk()->json('data'))->pluck('name');
    expect($names->all())->toBe(['Rita Admin']);
});

it('edita só nos primeiros 15 minutos e só as suas', function () {
    $id = scSay($this->admin, 'versão 1')->json('data.id');

    Sanctum::actingAs($this->prov);
    $this->putJson("/api/v1/staff-chat/messages/{$id}", ['body' => 'x'])->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->putJson("/api/v1/staff-chat/messages/{$id}", ['body' => 'versão 2'])->assertOk()->assertJsonPath('data.edited', true);

    StaffChatMessage::whereKey($id)->update(['created_at' => now()->subMinutes(16)]);
    $this->putJson("/api/v1/staff-chat/messages/{$id}", ['body' => 'versão 3'])->assertForbidden();
});

it('o autor apaga: sai do chat dos outros mas o Admin Geral vê o registo', function () {
    $id = scSay($this->admin, 'apagar esta')->json('data.id');
    Sanctum::actingAs($this->admin);
    $this->deleteJson("/api/v1/staff-chat/messages/{$id}")->assertOk();

    Sanctum::actingAs($this->prov);
    expect(collect($this->getJson('/api/v1/staff-chat/messages')->json('data'))->pluck('id'))->not->toContain($id);

    Sanctum::actingAs($this->geral);
    $row = collect($this->getJson('/api/v1/staff-chat/messages')->json('data'))->firstWhere('id', $id);
    expect($row['status'])->toBe('deleted')->and($row['body'])->toBe('apagar esta');
});

it('reage com um emoji por pessoa e pode retirar', function () {
    [$up, $heart] = \App\Models\CommunityReaction::EMOJIS;
    $id = scSay($this->admin, 'ideia')->json('data.id');
    Sanctum::actingAs($this->prov);
    expect($this->putJson("/api/v1/staff-chat/messages/{$id}/reaction", ['emoji' => $up])->assertOk()->json('data.reactions'))->toBe([$up => 1]);
    expect($this->putJson("/api/v1/staff-chat/messages/{$id}/reaction", ['emoji' => $up])->assertOk()->json('data.reactors.0.me'))->toBeTrue();
    expect($this->putJson("/api/v1/staff-chat/messages/{$id}/reaction", ['emoji' => $heart])->assertOk()->json('data.reactions'))->toBe([$heart => 1]);
    expect($this->putJson("/api/v1/staff-chat/messages/{$id}/reaction", ['emoji' => null])->assertOk()->json('data.reactions'))->toBe([]);
    $this->putJson("/api/v1/staff-chat/messages/{$id}/reaction", ['emoji' => 'X'])->assertStatus(422);
});

it('só o Admin Geral fixa (máx. 3), remove, restaura, apaga de vez e silencia', function () {
    $id = scSay($this->admin, 'importante')->json('data.id');

    foreach ([$this->prov, $this->admin] as $who) {
        Sanctum::actingAs($who);
        $this->postJson("/api/v1/staff-chat/messages/{$id}/pin")->assertForbidden();
        $this->postJson("/api/v1/staff-chat/messages/{$id}/remove", ['reason' => 'teste'])->assertForbidden();
        $this->postJson("/api/v1/staff-chat/mutes/{$this->admin->id}", ['hours' => 1])->assertForbidden();
    }

    Sanctum::actingAs($this->geral);
    $this->postJson("/api/v1/staff-chat/messages/{$id}/pin")->assertOk();
    expect(collect($this->getJson('/api/v1/staff-chat/messages')->json('pinned'))->pluck('id'))->toContain($id);

    $this->postJson("/api/v1/staff-chat/messages/{$id}/remove", ['reason' => 'fora de tema'])->assertOk();
    Sanctum::actingAs($this->prov);
    $row = collect($this->getJson('/api/v1/staff-chat/messages')->json('data'))->firstWhere('id', $id);
    expect($row['status'])->toBe('removed')->and($row['body'])->toBeNull()->and($row['author'])->toBeNull();
    expect(AppNotification::where('user_id', $this->admin->id)->where('title', 'like', '%removida%')->exists())->toBeTrue();

    Sanctum::actingAs($this->geral);
    $this->postJson("/api/v1/staff-chat/messages/{$id}/restore")->assertOk();
    $this->deleteJson("/api/v1/staff-chat/messages/{$id}/purge")->assertOk();
    $this->assertDatabaseMissing('staff_chat_messages', ['id' => $id]);
});

it('limita os fixados a 3', function () {
    $ids = collect(range(1, 4))->map(fn ($i) => scSay($this->geral, "aviso {$i}")->json('data.id'));
    Sanctum::actingAs($this->geral);
    foreach ($ids->take(3) as $id) {
        $this->postJson("/api/v1/staff-chat/messages/{$id}/pin")->assertOk();
    }
    $this->postJson("/api/v1/staff-chat/messages/{$ids[3]}/pin")->assertStatus(422);
});

it('um admin silenciado não escreve mas continua a ler', function () {
    Sanctum::actingAs($this->geral);
    $this->postJson("/api/v1/staff-chat/mutes/{$this->admin->id}", ['hours' => 2, 'reason' => 'calma'])->assertOk();
    $this->postJson("/api/v1/staff-chat/mutes/{$this->geral->id}", ['hours' => 2])->assertStatus(422);

    scSay($this->admin, 'olá')->assertForbidden();
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/staff-chat/messages')->assertOk();
    $this->getJson('/api/v1/staff-chat/overview')->assertOk()->assertJsonPath('data.me.muted', true);

    Sanctum::actingAs($this->geral);
    $this->deleteJson("/api/v1/staff-chat/mutes/{$this->admin->id}")->assertOk();
    scSay($this->admin, 'voltei')->assertCreated();
});

it('filtra por tipo, província, minhas e menções, e pesquisa texto', function () {
    scSay($this->prov, 'sobre a Huíla');
    $mid = scSay($this->admin, "sobre Luanda @[{$this->geral->id}]")->json('data.id');

    Sanctum::actingAs($this->geral);
    $ids = fn (string $qs) => collect($this->getJson('/api/v1/staff-chat/messages?' . $qs)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('type=PROVINCE_SUPER_ADMIN'))->toHaveCount(1)
        ->and($ids('province_id=' . $this->luanda->id))->toBe([$mid])
        ->and($ids('mentions_me=1'))->toBe([$mid])
        ->and($ids('q=Huíla'))->toHaveCount(1)
        ->and($ids('mine=1'))->toBe([]);
});

it('pagina em janelas de 30 com before_id', function () {
    foreach (range(1, 35) as $i) {
        StaffChatMessage::create(['user_id' => $this->admin->id, 'body' => "m{$i}"]);
    }
    Sanctum::actingAs($this->admin);
    $first = $this->getJson('/api/v1/staff-chat/messages')->assertOk();
    expect($first->json('data'))->toHaveCount(30)->and($first->json('meta.has_older'))->toBeTrue();

    $older = $this->getJson('/api/v1/staff-chat/messages?before_id=' . $first->json('meta.oldest_id'))->json('data');
    expect($older)->toHaveCount(5);
});

it('recusa mensagem vazia, duplicada e com mais de 1000 caracteres', function () {
    scSay($this->admin, '   ')->assertStatus(422);
    scSay($this->admin, str_repeat('a', 1001))->assertStatus(422);
    scSay($this->admin, 'igual')->assertCreated();
    scSay($this->admin, 'igual')->assertStatus(422);
});

it('mostra presença só de admins', function () {
    $this->prov->forceFill(['last_seen_at' => now()])->save();
    User::factory()->create(['role' => UserRole::USER, 'last_seen_at' => now()]);

    Sanctum::actingAs($this->geral);
    $data = $this->getJson('/api/v1/staff-chat/presence')->assertOk()->json('data');
    expect(collect($data['people'])->pluck('name')->all())->toBe(['Paulo Provincial'])
        ->and($data['online'])->toBe(1);
});

it('o overview diz quem sou e o que posso', function () {
    Sanctum::actingAs($this->prov);
    $this->getJson('/api/v1/staff-chat/overview')->assertOk()
        ->assertJsonPath('data.me.type_label', 'Admin Provincial')
        ->assertJsonPath('data.me.is_super', false)
        ->assertJsonPath('data.me.can_write', true)
        ->assertJsonCount(3, 'data.types');
});
