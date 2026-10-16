<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\RoomMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Conversa dos condutores (menções, fotos, localização, reações) e
 * Conversa com a Central (canal direto utilizador ↔ direção) — Outubro 2026.
 */
beforeEach(function () {
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->huila = Province::factory()->create(['name' => 'Huíla']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->ganda = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Ganda']);
    $this->lubango = Municipality::factory()->create(['province_id' => $this->huila->id, 'name' => 'Lubango']);

    $this->joao = User::factory()->create(['role' => UserRole::DRIVER, 'name' => 'João Condutor', 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id]);
    $this->pedro = User::factory()->create(['role' => UserRole::DRIVER, 'name' => 'Pedro Condutor', 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id]);
    $this->outro = User::factory()->create(['role' => UserRole::DRIVER, 'name' => 'Rui Ganda', 'province_id' => $this->benguela->id, 'municipality_id' => $this->ganda->id]);

    $this->adminBg = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Admin Benguela', 'province_id' => $this->benguela->id]);
    $this->adminHu = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Admin Huíla', 'province_id' => $this->huila->id]);
    $this->geral = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'name' => 'Admin Geral']);

    $this->cidadao = User::factory()->create(['role' => UserRole::USER, 'name' => 'Maria Cliente', 'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id]);
});

function roomSay(User $user, string $type, int $roomId, array $payload)
{
    Cache::flush(); // limpa o intervalo entre mensagens
    Sanctum::actingAs($user);

    return test()->postJson("/api/v1/rooms/{$type}/{$roomId}/messages", $payload);
}

// --- Conversa dos condutores ---

it('o condutor escreve no seu município, menciona um colega e este é avisado', function () {
    $r = roomSay($this->joao, 'drivers', $this->lobito->id, ['body' => "Olá @[{$this->pedro->id}], conheces a Rua 5?"])->assertCreated();

    expect($r->json('data.mentions'))->toBe([(string) $this->pedro->id => 'Pedro Condutor'])
        ->and($r->json('data.author.type_label'))->toBe('Condutor');
    expect(AppNotification::where('user_id', $this->pedro->id)->where('title', 'like', '%mencionou-o na conversa dos condutores%')->exists())->toBeTrue();
});

it('o condutor não entra na conversa de outro município; o Admin só na sua província', function () {
    roomSay($this->joao, 'drivers', $this->ganda->id, ['body' => 'intruso'])->assertForbidden();

    Sanctum::actingAs($this->joao);
    $this->getJson("/api/v1/rooms/drivers/{$this->ganda->id}/messages")->assertForbidden();

    Sanctum::actingAs($this->adminHu);
    $this->getJson("/api/v1/rooms/drivers/{$this->lobito->id}/messages")->assertForbidden();
    Sanctum::actingAs($this->adminBg);
    $this->getJson("/api/v1/rooms/drivers/{$this->lobito->id}/messages")->assertOk();
    Sanctum::actingAs($this->cidadao);
    $this->getJson("/api/v1/rooms/drivers/{$this->lobito->id}/messages")->assertForbidden();
});

it('não se menciona quem não está na conversa (o texto fica sem a menção)', function () {
    $r = roomSay($this->joao, 'drivers', $this->lobito->id, ['body' => "Olá @[{$this->outro->id}]"])->assertCreated();
    expect($r->json('data.mentions'))->toBe([]);
});

it('partilha a localização com prazo; quem partilhou pode parar', function () {
    $r = roomSay($this->joao, 'drivers', $this->lobito->id, ['latitude' => -12.35, 'longitude' => 13.54, 'accuracy' => 20, 'minutes' => 30])->assertCreated();
    expect($r->json('data.location.active'))->toBeTrue()
        ->and($r->json('data.location.latitude'))->toBe(-12.35);

    roomSay($this->joao, 'drivers', $this->lobito->id, ['latitude' => -12.35, 'longitude' => 13.54, 'minutes' => 7])->assertStatus(422);

    Sanctum::actingAs($this->pedro);
    $this->postJson('/api/v1/rooms/messages/' . $r->json('data.id') . '/location/stop')->assertForbidden();
    Sanctum::actingAs($this->joao);
    $this->postJson('/api/v1/rooms/messages/' . $r->json('data.id') . '/location/stop')->assertOk()
        ->assertJsonPath('data.location.active', false)->assertJsonPath('data.location.latitude', null);
});

it('reage e todos veem quem reagiu', function () {
    $id = roomSay($this->joao, 'drivers', $this->lobito->id, ['body' => 'Bom dia'])->json('data.id');
    $up = \App\Models\CommunityReaction::EMOJIS[0];

    Sanctum::actingAs($this->pedro);
    $this->putJson("/api/v1/rooms/messages/{$id}/reaction", ['emoji' => $up])->assertOk();

    Sanctum::actingAs($this->adminBg);
    $m = collect($this->getJson("/api/v1/rooms/drivers/{$this->lobito->id}/messages")->json('data'))->firstWhere('id', $id);
    expect($m['reactions'])->toBe([$up => 1])->and($m['reactors'][0]['name'])->toBe('Pedro Condutor');
});

it('fotos: só saem enquanto a mensagem está visível', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    $r = roomSay($this->joao, 'drivers', $this->lobito->id, ['body' => 'É aqui', 'image' => Illuminate\Http\UploadedFile::fake()->image('casa.png', 800, 600)])->assertCreated();

    $url = $r->json('data.image_url');
    expect($url)->toMatch('#/api/v1/rooms/images/[a-f0-9]{40}\.(webp|jpg)$#');
    $this->get($url)->assertOk();

    // o Admin da província remove a mensagem → a foto deixa de sair
    Sanctum::actingAs($this->adminBg);
    $this->deleteJson('/api/v1/rooms/messages/' . $r->json('data.id'), ['reason' => 'Fora do tema'])->assertOk();
    $this->get($url)->assertNotFound();
})->skip(!function_exists('imagecreatetruecolor'), 'Precisa da extensão GD');

it('o condutor só apaga as suas; o Admin da província remove qualquer uma', function () {
    $id = roomSay($this->joao, 'drivers', $this->lobito->id, ['body' => 'Mensagem do João'])->json('data.id');

    Sanctum::actingAs($this->pedro);
    $this->deleteJson("/api/v1/rooms/messages/{$id}")->assertForbidden();

    Sanctum::actingAs($this->adminBg);
    $this->deleteJson("/api/v1/rooms/messages/{$id}")->assertOk();
    expect(RoomMessage::find($id)->removed_at)->not->toBeNull();
});

it('as versões antigas da app continuam a ler e escrever na mesma conversa', function () {
    roomSay($this->joao, 'drivers', $this->lobito->id, ['body' => 'Nova app'])->assertCreated();

    Sanctum::actingAs($this->pedro);
    $old = $this->getJson('/api/v1/chat/messages')->assertOk()->json('data');
    expect(collect($old)->pluck('body')->all())->toContain('Nova app');

    $this->postJson('/api/v1/chat/messages', ['municipality_id' => $this->lobito->id, 'body' => 'App antiga'])->assertCreated();
    expect(RoomMessage::where('body', 'App antiga')->where('room_id', $this->lobito->id)->exists())->toBeTrue();
});

// --- Conversa com a Central ---

it('o utilizador não vê o botão nem escreve antes de a Central lhe escrever', function () {
    Sanctum::actingAs($this->cidadao);
    $this->getJson('/api/v1/central-chat/me')->assertOk()->assertJsonPath('data.available', false);
    roomSay($this->cidadao, 'central', $this->cidadao->id, ['body' => 'Olá?'])->assertForbidden();
});

it('o Admin da província escreve, o utilizador é avisado e responde; os admins são avisados', function () {
    roomSay($this->adminBg, 'central', $this->cidadao->id, ['body' => 'Bom dia, o seu pedido vai atrasar 1 hora.'])->assertCreated()
        ->assertJsonPath('data.author.type_label', 'Central KeruGás');

    expect(AppNotification::where('user_id', $this->cidadao->id)->where('title', 'Nova mensagem da Central KeruGás')->exists())->toBeTrue();

    Sanctum::actingAs($this->cidadao);
    $this->getJson('/api/v1/central-chat/me')->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.unread', 1);
    $this->getJson("/api/v1/rooms/central/{$this->cidadao->id}/messages")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/central-chat/me')->assertJsonPath('data.unread', 0);

    roomSay($this->cidadao, 'central', $this->cidadao->id, ['body' => 'Obrigada pelo aviso'])->assertCreated();
    expect(AppNotification::where('user_id', $this->adminBg->id)->where('title', 'like', '%respondeu na Conversa com a Central%')->exists())->toBeTrue();

    // lista de conversas dos admins
    Sanctum::actingAs($this->adminBg);
    $threads = $this->getJson('/api/v1/admin/central-threads')->assertOk()->json('data');
    expect($threads)->toHaveCount(1)->and($threads[0]['user']['name'])->toBe('Maria Cliente')
        ->and($threads[0]['unread'])->toBe(1);

    Sanctum::actingAs($this->geral);
    expect($this->getJson('/api/v1/admin/central-threads')->json('data'))->toHaveCount(1);
});

it('o Admin de outra província não fala com o utilizador; outro utilizador não lê a conversa', function () {
    roomSay($this->adminHu, 'central', $this->cidadao->id, ['body' => 'Olá'])->assertForbidden();

    roomSay($this->adminBg, 'central', $this->cidadao->id, ['body' => 'Olá Maria'])->assertCreated();
    Sanctum::actingAs($this->adminHu);
    expect($this->getJson('/api/v1/admin/central-threads')->json('data'))->toBe([]);

    Sanctum::actingAs($this->joao);
    $this->getJson("/api/v1/rooms/central/{$this->cidadao->id}/messages")->assertForbidden();
});

it('a Central não abre conversa com outro admin', function () {
    roomSay($this->geral, 'central', $this->adminBg->id, ['body' => 'Olá'])->assertForbidden();
});
