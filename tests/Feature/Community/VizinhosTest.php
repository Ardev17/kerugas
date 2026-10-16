<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\CommunityBannedWord;
use App\Models\CommunityMessage;
use App\Models\Municipality;
use App\Models\Neighborhood;
use App\Models\Province;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Vizinhos KeruGás (Outubro 2026): a comunidade de cada município.
 */
beforeEach(function () {
    CommunityAccess::flush();
    $this->province = Province::factory()->create();
    $this->luanda = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->viana = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->otherProvince = Province::factory()->create();
    $this->huambo = Municipality::factory()->create(['province_id' => $this->otherProvince->id]);
});

function vzUser(Municipality $m, UserRole $role = UserRole::USER, array $extra = []): User
{
    return User::factory()->create(array_merge([
        'role' => $role, 'province_id' => $m->province_id, 'municipality_id' => $m->id,
        'community_accepted_at' => now(),
    ], $extra));
}

function vzSay(User $user, int $mid, string $body, array $extra = [])
{
    Illuminate\Support\Facades\Cache::flush(); // limpa o intervalo de 5 s entre mensagens
    Sanctum::actingAs($user);

    return test()->postJson('/api/v1/community/messages?municipality_id=' . $mid, array_merge(['body' => $body], $extra));
}

function vzMessage(User $author, Municipality $m, array $extra = []): CommunityMessage
{
    return CommunityMessage::create(array_merge(['municipality_id' => $m->id, 'user_id' => $author->id, 'body' => 'Olá vizinhos'], $extra));
}

it('publica logo, sem aprovação, e só quem vive no município vê', function () {
    $ana = vzUser($this->luanda);
    vzSay($ana, $this->luanda->id, 'Bom dia, vizinhos!')->assertCreated()->assertJsonPath('data.body', 'Bom dia, vizinhos!');

    $vizinho = vzUser($this->luanda);
    Sanctum::actingAs($vizinho);
    $this->getJson('/api/v1/community/messages')->assertOk()->assertJsonCount(1, 'data');

    $deFora = vzUser($this->viana);
    Sanctum::actingAs($deFora);
    $this->getJson('/api/v1/community/messages?municipality_id=' . $this->luanda->id)->assertForbidden();
    $this->postJson('/api/v1/community/messages?municipality_id=' . $this->luanda->id, ['body' => 'intruso'])->assertForbidden();
});

it('sem município no perfil pede para o escolher', function () {
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::USER, 'municipality_id' => null]));
    $this->getJson('/api/v1/community/messages')->assertStatus(422)->assertJsonPath('code', 'needs_municipality');
});

it('exige aceitar as regras antes de escrever', function () {
    $novo = vzUser($this->luanda, UserRole::USER, ['community_accepted_at' => null]);
    vzSay($novo, $this->luanda->id, 'Olá')->assertForbidden();

    Sanctum::actingAs($novo);
    $this->postJson('/api/v1/community/accept-rules')->assertOk();
    vzSay($novo->fresh(), $this->luanda->id, 'Olá')->assertCreated();
});

it('bloqueia telemóveis e links para utilizadores comuns, mas não para a equipa', function () {
    $ana = vzUser($this->luanda);
    vzSay($ana, $this->luanda->id, 'Liga para 923 456 789')->assertStatus(422)->assertJsonValidationErrors('body');
    vzSay($ana, $this->luanda->id, 'Veja www.promo.com')->assertStatus(422);
    vzSay($ana, $this->luanda->id, 'Vendem a 15000 kz no posto')->assertCreated();

    $mod = vzUser($this->luanda, UserRole::MODERATOR);
    vzSay($mod, $this->luanda->id, 'Contacto oficial 923456789 e www.kerugas.ao')->assertCreated();
});

it('recusa palavras proibidas (ignorando acentos e maiúsculas)', function () {
    CommunityBannedWord::create(['word' => 'burla']);
    vzSay(vzUser($this->luanda), $this->luanda->id, 'Isto é uma BÚRLA!')->assertStatus(422);
});

it('menciona por nome (@[id]) e por número, avisa o mencionado e nunca guarda o número', function () {
    $ana = vzUser($this->luanda);
    $joao = vzUser($this->luanda, UserRole::USER, ['name' => 'João Manuel', 'phone' => '+244923456789']);

    $res = vzSay($ana, $this->luanda->id, 'Olá @923456789, viste isto?')->assertCreated();

    expect($res->json('data.body'))->toBe("Olá @[{$joao->id}], viste isto?");
    expect($res->json('data.mentions'))->toBe([(string) $joao->id => 'João Manuel']);
    expect(CommunityMessage::first()->body)->not->toContain('923456789');

    $n = AppNotification::where('user_id', $joao->id)->first();
    expect($n->title)->toContain('mencionou');
    expect($n->data['path'])->toBe('/comunidade?municipio=' . $this->luanda->id . '&mensagem=' . $res->json('data.id'));
});

it('ignora menções a quem é de outro município, a si próprio e passa de 5', function () {
    $ana = vzUser($this->luanda);
    $estranho = vzUser($this->viana);

    $res = vzSay($ana, $this->luanda->id, "Oi @[{$estranho->id}] e @[{$ana->id}]")->assertCreated();
    expect($res->json('data.body'))->toBe("Oi  e @[{$ana->id}]");
    expect(AppNotification::count())->toBe(0);

    $seis = collect(range(1, 6))->map(fn () => vzUser($this->luanda))->map(fn ($u) => "@[{$u->id}]")->implode(' ');
    vzSay($ana, $this->luanda->id, $seis)->assertStatus(422);
});

it('responder a alguém avisa o autor da mensagem original', function () {
    $ana = vzUser($this->luanda);
    $rui = vzUser($this->luanda);
    $original = vzMessage($ana, $this->luanda);

    vzSay($rui, $this->luanda->id, 'Concordo!', ['reply_to_id' => $original->id])->assertCreated();

    expect(AppNotification::where('user_id', $ana->id)->first()->title)->toContain('respondeu');
});

it('janela de 30 mensagens com anterior/seguinte e abrir à volta de uma menção', function () {
    $ana = vzUser($this->luanda);
    foreach (range(1, 70) as $i) {
        vzMessage($ana, $this->luanda, ['body' => "msg $i"]);
    }
    Sanctum::actingAs($ana);

    $latest = $this->getJson('/api/v1/community/messages')->assertOk();
    expect($latest->json('data'))->toHaveCount(30);
    expect($latest->json('data.29.body'))->toBe('msg 70');
    expect($latest->json('meta.has_older'))->toBeTrue()->and($latest->json('meta.has_newer'))->toBeFalse();

    $older = $this->getJson('/api/v1/community/messages?before_id=' . $latest->json('meta.oldest_id'))->assertOk();
    expect($older->json('data.29.body'))->toBe('msg 40')->and($older->json('data'))->toHaveCount(30);
    expect($older->json('meta.has_newer'))->toBeTrue();

    $target = CommunityMessage::where('body', 'msg 30')->first();
    $around = $this->getJson('/api/v1/community/messages?around_id=' . $target->id)->assertOk();
    expect(collect($around->json('data'))->pluck('id'))->toContain($target->id);
    expect($around->json('meta.has_older'))->toBeTrue()->and($around->json('meta.has_newer'))->toBeTrue();
});

it('filtra por bairro, tema, "só as minhas" e "menções a mim"', function () {
    $ana = vzUser($this->luanda);
    $rui = vzUser($this->luanda);
    $bairro = Neighborhood::factory()->create(['municipality_id' => $this->luanda->id]);
    vzMessage($ana, $this->luanda, ['body' => 'no bairro', 'neighborhood_id' => $bairro->id, 'topic' => 'gas']);
    vzMessage($rui, $this->luanda, ['body' => 'geral']);
    $m = vzMessage($rui, $this->luanda, ['body' => 'olá ana']);
    $m->mentions()->create(['user_id' => $ana->id]);
    Sanctum::actingAs($ana);

    expect($this->getJson('/api/v1/community/messages?neighborhood_id=' . $bairro->id)->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/community/messages?topic=gas')->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/community/messages?mine=1')->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/community/messages?mentions_me=1')->json('data.0.body'))->toBe('olá ana');
});

it('o autor apaga: sai do chat, mas a moderação continua a ver o registo', function () {
    $ana = vzUser($this->luanda);
    $msg = vzMessage($ana, $this->luanda, ['body' => 'apaga-me']);
    Sanctum::actingAs($ana);
    $this->deleteJson("/api/v1/community/messages/{$msg->id}")->assertOk();

    expect($this->getJson('/api/v1/community/messages')->json('data'))->toHaveCount(0);

    Sanctum::actingAs(vzUser($this->huambo, UserRole::MODERATOR));
    $rows = $this->getJson('/api/v1/community/moderation/messages?tab=deleted')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['status'])->toBe('deleted')->and($rows[0]['body'])->toBe('apaga-me');
});

it('só edita as suas mensagens, dentro de 15 minutos', function () {
    $ana = vzUser($this->luanda);
    $nova = vzMessage($ana, $this->luanda);
    $velha = vzMessage($ana, $this->luanda);
    $velha->forceFill(['created_at' => now()->subMinutes(30)])->save();
    Sanctum::actingAs($ana);

    $this->putJson("/api/v1/community/messages/{$nova->id}", ['body' => 'corrigido'])->assertOk()->assertJsonPath('data.edited', true);
    $this->putJson("/api/v1/community/messages/{$velha->id}", ['body' => 'tarde'])->assertForbidden();

    Sanctum::actingAs(vzUser($this->luanda));
    $this->putJson("/api/v1/community/messages/{$nova->id}", ['body' => 'roubar'])->assertForbidden();
});

it('3 denúncias de pessoas diferentes escondem a mensagem até a moderação rever', function () {
    $autor = vzUser($this->luanda);
    $msg = vzMessage($autor, $this->luanda, ['body' => 'conteúdo duvidoso']);

    foreach (range(1, 3) as $i) {
        Sanctum::actingAs(vzUser($this->luanda));
        $this->postJson("/api/v1/community/messages/{$msg->id}/report", ['reason' => 'spam'])->assertOk();
    }
    expect($msg->fresh()->hidden_at)->not->toBeNull();

    Sanctum::actingAs(vzUser($this->luanda));
    $row = $this->getJson('/api/v1/community/messages')->json('data.0');
    expect($row['status'])->toBe('hidden')->and($row['body'])->toBeNull()->and($row['author'])->toBeNull();

    // a mesma pessoa a denunciar 3 vezes não conta
    $outra = vzMessage($autor, $this->luanda);
    $chato = vzUser($this->luanda);
    Sanctum::actingAs($chato);
    foreach (range(1, 3) as $i) {
        $this->postJson("/api/v1/community/messages/{$outra->id}/report", ['reason' => 'spam']);
    }
    expect($outra->fresh()->hidden_at)->toBeNull();

    // a moderação decide que não procedem: volta ao chat
    Sanctum::actingAs(vzUser($this->luanda, UserRole::ADMIN));
    $this->postJson("/api/v1/community/moderation/messages/{$msg->id}/dismiss")->assertOk();
    expect($msg->fresh()->hidden_at)->toBeNull();
});

it('a moderação remove com motivo (o autor é avisado) e o admin apaga de vez', function () {
    $autor = vzUser($this->luanda);
    $msg = vzMessage($autor, $this->luanda, ['body' => 'ofensa']);
    $mod = vzUser($this->luanda, UserRole::MODERATOR);

    Sanctum::actingAs($mod);
    $this->postJson("/api/v1/community/moderation/messages/{$msg->id}/remove", ['reason' => 'Insulto'])->assertOk();
    expect($msg->fresh()->removed_at)->not->toBeNull();
    expect(AppNotification::where('user_id', $autor->id)->first()->body)->toContain('Insulto');

    // o moderador não apaga de vez
    $this->deleteJson("/api/v1/community/moderation/messages/{$msg->id}")->assertForbidden();

    Sanctum::actingAs(vzUser($this->luanda, UserRole::ADMIN));
    $this->deleteJson("/api/v1/community/moderation/messages/{$msg->id}")->assertOk();
    expect(CommunityMessage::find($msg->id))->toBeNull();
});

it('âmbito da moderação: moderador país inteiro, admin só a sua província', function () {
    $msgHuambo = vzMessage(vzUser($this->huambo), $this->huambo);

    Sanctum::actingAs(vzUser($this->luanda, UserRole::MODERATOR));
    $this->postJson("/api/v1/community/moderation/messages/{$msgHuambo->id}/remove", ['reason' => 'Spam'])->assertOk();

    $outra = vzMessage(vzUser($this->huambo), $this->huambo);
    Sanctum::actingAs(vzUser($this->luanda, UserRole::ADMIN));
    $this->postJson("/api/v1/community/moderation/messages/{$outra->id}/remove", ['reason' => 'Spam'])->assertForbidden();

    Sanctum::actingAs(vzUser($this->luanda, UserRole::USER));
    $this->getJson('/api/v1/community/moderation/messages')->assertForbidden();
});

it('Super Admin e PSA escrevem noutro município como Equipa KeruGás', function () {
    $super = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => null, 'municipality_id' => null]);
    $res = vzSay($super, $this->huambo->id, 'Aviso importante para todos')->assertCreated();
    expect($res->json('data.official'))->toBeTrue()->and($res->json('data.author.name'))->toBe('Equipa KeruGás');

    $psa = vzUser($this->luanda, UserRole::PROVINCE_SUPER_ADMIN);
    vzSay($psa, $this->viana->id, 'Aviso da província')->assertCreated();
    vzSay($psa, $this->huambo->id, 'Fora da minha província')->assertForbidden();

    $admin = vzUser($this->luanda, UserRole::ADMIN);
    vzSay($admin, $this->viana->id, 'Admin não escreve fora')->assertForbidden();
});

it('silenciar respeita os limites de cada papel e impede de escrever', function () {
    $alvo = vzUser($this->luanda);
    $mod = vzUser($this->luanda, UserRole::MODERATOR);
    Sanctum::actingAs($mod);

    $this->postJson("/api/v1/community/moderation/mutes/{$alvo->id}", ['hours' => 72])->assertForbidden();
    $this->postJson("/api/v1/community/moderation/mutes/{$alvo->id}", [])->assertForbidden(); // permanente
    $this->postJson("/api/v1/community/moderation/mutes/{$alvo->id}", ['hours' => 24, 'reason' => 'Insultos'])->assertOk();

    vzSay($alvo, $this->luanda->id, 'Posso falar?')->assertForbidden();

    Sanctum::actingAs(vzUser($this->luanda, UserRole::PROVINCE_SUPER_ADMIN));
    $this->postJson("/api/v1/community/moderation/mutes/{$alvo->id}", [])->assertOk(); // permanente
});

it('esconder uma pessoa só afeta quem esconde', function () {
    $ana = vzUser($this->luanda);
    $chato = vzUser($this->luanda);
    vzMessage($chato, $this->luanda, ['body' => 'do chato']);
    vzMessage($ana, $this->luanda, ['body' => 'da ana']);

    Sanctum::actingAs($ana);
    $this->postJson("/api/v1/community/blocks/{$chato->id}")->assertOk();
    expect(collect($this->getJson('/api/v1/community/messages')->json('data'))->pluck('body')->all())->toBe(['da ana']);

    Sanctum::actingAs(vzUser($this->luanda));
    expect($this->getJson('/api/v1/community/messages')->json('data'))->toHaveCount(2);
});

it('presença: online agora e visto há X, só do próprio município', function () {
    $ana = vzUser($this->luanda, UserRole::USER, ['last_seen_at' => now()]);
    $rui = vzUser($this->luanda, UserRole::USER, ['name' => 'Rui', 'last_seen_at' => now()->subHours(3)]);
    vzUser($this->viana, UserRole::USER, ['last_seen_at' => now()]);

    Sanctum::actingAs($ana);
    $data = $this->getJson('/api/v1/community/presence')->assertOk()->json('data');

    expect($data['online'])->toBe(1)->and($data['active_today'])->toBe(2);
    expect($data['people'])->toHaveCount(1)->and($data['people'][0]['name'])->toBe('Rui')->and($data['people'][0]['online'])->toBeFalse();
    expect(json_encode($data))->not->toContain($rui->phone);
});

it('a lista de sugestões de @ nunca traz o telemóvel', function () {
    $ana = vzUser($this->luanda);
    $joao = vzUser($this->luanda, UserRole::USER, ['name' => 'João Silva']);
    vzUser($this->viana, UserRole::USER, ['name' => 'João de Viana']);
    Sanctum::actingAs($ana);

    $res = $this->getJson('/api/v1/community/mentionable?q=João')->assertOk();
    expect($res->json('data'))->toHaveCount(1);
    expect($res->getContent())->not->toContain($joao->phone);
});

it('bairros: lista pública, gestão pelo admin da província', function () {
    $this->postJson('/api/v1/community/neighborhoods', [])->assertUnauthorized();

    Sanctum::actingAs(vzUser($this->luanda, UserRole::ADMIN));
    $this->postJson('/api/v1/community/neighborhoods', ['municipality_id' => $this->viana->id, 'name' => 'Zango'])->assertCreated();
    $this->postJson('/api/v1/community/neighborhoods', ['municipality_id' => $this->viana->id, 'name' => 'Zango'])->assertStatus(422);
    $this->postJson('/api/v1/community/neighborhoods', ['municipality_id' => $this->huambo->id, 'name' => 'Fora'])->assertForbidden();

    $this->getJson('/api/v1/neighborhoods?municipality_id=' . $this->viana->id)->assertOk()->assertJsonCount(1, 'data');
});

it('o bairro vai no perfil e volta no login/me', function () {
    $bairro = Neighborhood::factory()->create(['municipality_id' => $this->luanda->id]);
    $outro = Neighborhood::factory()->create(['municipality_id' => $this->viana->id]);
    $ana = vzUser($this->luanda);
    Sanctum::actingAs($ana);

    $this->putJson('/api/v1/users/me', ['neighborhood_id' => $outro->id])->assertStatus(422)->assertJsonValidationErrors('neighborhood_id');
    $this->putJson('/api/v1/users/me', ['neighborhood_id' => $bairro->id])->assertOk()->assertJsonPath('data.neighborhood', $bairro->name);
    $this->getJson('/api/v1/users/me')->assertJsonPath('data.neighborhood_id', $bairro->id);
});

it('a eliminação da conta apaga as mensagens da pessoa na comunidade', function () {
    $ana = vzUser($this->luanda);
    vzMessage($ana, $this->luanda);
    $ana->forceFill(['deletion_scheduled_for' => now()->subMinute()])->save();

    expect(app(App\Services\Account\AccountDeletionService::class)->purge($ana))->toBeTrue();
    expect(CommunityMessage::where('user_id', $ana->id)->count())->toBe(0);
});

it('fotos: guarda re-codificada no disco privado e só serve enquanto a mensagem está visível', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    $ana = vzUser($this->luanda);
    $file = Illuminate\Http\UploadedFile::fake()->image('casa.png', 800, 600);

    Illuminate\Support\Facades\Cache::flush();
    Sanctum::actingAs($ana);
    $res = $this->postJson('/api/v1/community/messages?municipality_id=' . $this->luanda->id, ['body' => 'A fila hoje', 'image' => $file])->assertCreated();

    $url = $res->json('data.image_url');
    expect($url)->toMatch('#/api/v1/community/images/[a-f0-9]{40}\.(webp|jpg)$#');
    expect(json_encode($res->json()))->not->toContain('casa.png');

    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    // removida pela moderação: a foto deixa de sair
    CommunityMessage::first()->forceFill(['removed_at' => now()])->save();
    $this->get($url)->assertNotFound();
})->skip(!function_exists('imagecreatetruecolor'), 'Precisa da extensão GD');

it('rejeita ficheiros que não são imagem', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    $file = Illuminate\Http\UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;');
    Illuminate\Support\Facades\Cache::flush();
    Sanctum::actingAs(vzUser($this->luanda));

    $this->postJson('/api/v1/community/messages?municipality_id=' . $this->luanda->id, ['body' => 'olá', 'image' => $file])->assertStatus(422);
});

it('qualquer vizinho vê quantas reações e quem reagiu (Outubro 2026)', function () {
    [$up, $heart] = [\App\Models\CommunityReaction::EMOJIS[0], \App\Models\CommunityReaction::EMOJIS[1]];
    $autor = vzUser($this->luanda);
    $ana = vzUser($this->luanda, UserRole::USER, ['name' => 'Ana']);
    $rui = vzUser($this->luanda, UserRole::USER, ['name' => 'Rui']);
    $msg = vzMessage($autor, $this->luanda);

    Sanctum::actingAs($ana);
    $this->putJson("/api/v1/community/messages/{$msg->id}/reaction", ['emoji' => $up])->assertOk();
    Sanctum::actingAs($rui);
    $this->putJson("/api/v1/community/messages/{$msg->id}/reaction", ['emoji' => $heart])->assertOk();

    // um terceiro vizinho (que não reagiu) vê tudo
    Sanctum::actingAs($autor);
    $m = collect($this->getJson('/api/v1/community/messages')->assertOk()->json('data'))->firstWhere('id', $msg->id);
    expect($m['reactions'])->toBe([$up => 1, $heart => 1])
        ->and(collect($m['reactors'])->pluck('name')->all())->toBe(['Ana', 'Rui'])
        ->and(collect($m['reactors'])->firstWhere('name', 'Rui')['emoji'])->toBe($heart)
        ->and(collect($m['reactors'])->every(fn ($r) => !array_key_exists('phone', $r)))->toBeTrue();
});
