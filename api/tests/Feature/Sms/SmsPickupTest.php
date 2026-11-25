<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BottlePickup;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use App\Services\Sms\MockSmsProvider;
use App\Services\Sms\SmsCommandParser;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Carregamento por SMS (Outubro 2026). O fornecedor não recebe SMS: o
 * "CARREGAR <qtd> <posto> <PIN>" cai no telemóvel da central e quem atende
 * cola o texto no painel. O PIN prova que veio mesmo do condutor.
 */
beforeEach(function () {
    $this->app->bind(SmsProviderInterface::class, fn () => new MockSmsProvider());
    $this->province = Province::factory()->create();
    $this->other = Province::factory()->create();
    $this->operator = User::factory()->create(['role' => UserRole::STATION_OPERATOR]);
    $this->station = assignOperator($this->operator, Station::factory()->create(['name' => 'Posto Norte']));
    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $this->driver = User::factory()->create([
        'role' => UserRole::DRIVER, 'province_id' => $this->province->id, 'status' => 'ACTIVE',
        'phone' => '+244923111222', 'sms_pin_hash' => Hash::make('4821'),
    ]);
});

function smsRegister(User $by, array $extra = [])
{
    Sanctum::actingAs($by);

    return test()->postJson('/api/v1/admin/pickups/sms', array_merge([
        'sender_phone' => '923 111 222', 'sms_text' => 'CARREGAR 10 ' . test()->station->code . ' 4821',
    ], $extra));
}

it('interpreta o comando', function () {
    $p = new SmsCommandParser();
    expect($p->parsePickup('CARREGAR 10 00023 4821'))->toBe(['quantity' => 10, 'station_code' => '00023', 'pin' => '4821'])
        ->and($p->parsePickup("  carregar   5  00001   123456 "))->toBe(['quantity' => 5, 'station_code' => '00001', 'pin' => '123456'])
        ->and($p->parsePickup('CARREGAR 10 1002 4821'))->toBeNull()
        ->and($p->parsePickup('CARREGAR dez 00023 4821'))->toBeNull()
        ->and($p->parsePickup('10'))->toBeNull();
});

it('a central regista o SMS do condutor; fica como registo dele, com o posto a confirmar', function () {
    $r = smsRegister($this->admin)->assertCreated()
        ->assertJsonPath('data.source', 'SMS')
        ->assertJsonPath('data.state', 'DRIVER_ONLY')
        ->assertJsonPath('data.awaiting_confirmation', false)
        ->assertJsonPath('data.registered_by_name', $this->admin->name);

    $p = BottlePickup::find($r->json('data.id'));
    expect($p->driver_id)->toBe($this->driver->id)->and($p->station_id)->toBe($this->station->id)
        ->and($p->quantity)->toBe(10)->and($p->registered_by)->toBe($this->admin->id);
    expect(BottlePickup::payable()->count())->toBe(1); // é do condutor, não precisa de confirmação extra

    expect(AppNotification::where('user_id', $this->operator->id)->where('title', 'Confirme as garrafas levadas')->count())->toBe(1);
    expect(AppNotification::where('user_id', $this->driver->id)->where('title', 'Carregamento registado por SMS')->count())->toBe(1);
    expect(AuditLog::where('action', 'pickup.sms_registered')->count())->toBe(1);
});

it('não guarda o PIN em lado nenhum', function () {
    smsRegister($this->admin)->assertCreated();
    expect(json_encode(AuditLog::all()->toArray()))->not->toContain('4821');
    expect(json_encode(AppNotification::all()->toArray()))->not->toContain('4821');
});

it('o valor vem do preço por garrafa do último carregamento nesse posto (ou do que a central indicar)', function () {
    BottlePickup::create([
        'client_uuid' => (string) Str::uuid(), 'station_id' => $this->station->id, 'driver_id' => $this->driver->id,
        'quantity' => 10, 'amount' => 12000, 'registered_at' => now()->subDay(),
    ]);

    smsRegister($this->admin, ['sms_text' => 'CARREGAR 5 ' . $this->station->code . ' 4821'])->assertCreated();
    expect((float) BottlePickup::where('source', 'SMS')->first()->amount)->toBe(6000.0);

    smsRegister($this->admin, ['amount' => 7000])->assertCreated();
    expect((float) BottlePickup::where('source', 'SMS')->latest('id')->first()->amount)->toBe(7000.0);
});

it('PIN errado não regista, e 5 erros bloqueiam o PIN (mesmo com o certo)', function () {
    foreach (range(1, 5) as $_) {
        smsRegister($this->admin, ['sms_text' => 'CARREGAR 10 ' . $this->station->code . ' 0001'])->assertStatus(422);
    }
    expect(BottlePickup::count())->toBe(0);
    expect($this->driver->fresh()->sms_pin_locked_until)->not->toBeNull();

    smsRegister($this->admin)->assertStatus(422);
    expect(BottlePickup::count())->toBe(0);

    $this->travel(61)->minutes();
    smsRegister($this->admin)->assertCreated();
});

it('número que não é de um condutor da província de quem regista é recusado, sem revelar nada', function () {
    smsRegister($this->admin, ['sender_phone' => '900000000'])->assertStatus(422);

    $forasteiro = User::factory()->admin()->create(['province_id' => $this->other->id]);
    smsRegister($forasteiro)->assertStatus(422)->assertJsonFragment(['message' => 'Não há nenhum condutor da sua província com este número.']);
    expect(BottlePickup::count())->toBe(0);
});

it('sem PIN definido não regista', function () {
    $this->driver->forceFill(['sms_pin_hash' => null])->save();
    smsRegister($this->admin)->assertStatus(422);
    expect(BottlePickup::count())->toBe(0);
});

it('recusa texto inválido, posto inexistente, quantidade acima do limite, futuro e excesso diário', function () {
    smsRegister($this->admin, ['sms_text' => 'CARREGAR 10'])->assertStatus(422);
    smsRegister($this->admin, ['sms_text' => 'CARREGAR 10 99999 4821'])->assertStatus(422);
    smsRegister($this->admin, ['sms_text' => 'CARREGAR 51 ' . $this->station->code . ' 4821'])->assertStatus(422);
    smsRegister($this->admin, ['received_at' => now()->addHour()->toIso8601String()])->assertStatus(422);
    expect(BottlePickup::count())->toBe(0);

    config(['kerugas.pickups.sms_per_day' => 2]);
    smsRegister($this->admin)->assertCreated();
    smsRegister($this->admin)->assertCreated();
    smsRegister($this->admin)->assertStatus(422);
    expect(BottlePickup::count())->toBe(2);
});

it('só Admin e acima usam esta rota', function () {
    smsRegister($this->driver)->assertForbidden();
    smsRegister(User::factory()->create(['role' => UserRole::USER]))->assertForbidden();
});

it('o condutor define o PIN na app: precisa da palavra-passe e recusa PINs fáceis', function () {
    $this->driver->forceFill(['sms_pin_hash' => null, 'password' => Hash::make('Segredo123')])->save();
    Sanctum::actingAs($this->driver);

    $this->putJson('/api/v1/driver/sms-pin', ['password' => 'errada', 'pin' => '4821'])->assertStatus(422);
    $this->putJson('/api/v1/driver/sms-pin', ['password' => 'Segredo123', 'pin' => '1234'])->assertStatus(422);
    $this->putJson('/api/v1/driver/sms-pin', ['password' => 'Segredo123', 'pin' => '12'])->assertUnprocessable();
    $this->putJson('/api/v1/driver/sms-pin', ['password' => 'Segredo123', 'pin' => '4821'])->assertOk();

    expect(Hash::check('4821', $this->driver->fresh()->sms_pin_hash))->toBeTrue();
    expect(AuditLog::where('action', 'driver.sms_pin_set')->count())->toBe(1);
    $this->getJson('/api/v1/driver/stock')->assertJsonPath('data.sms_pin_set', true);
});

// --- PIN definido ao criar o condutor (provisório) ---

function createDriverVia(User $by, array $extra = [])
{
    Sanctum::actingAs($by);

    return test()->postJson('/api/v1/admin/users', array_merge([
        'name' => 'Novo Condutor', 'phone' => '923777888', 'password' => 'segredo123', 'role' => 'DRIVER',
        'province_id' => test()->province->id, 'municipality_id' => \App\Models\Municipality::factory()->create(['province_id' => test()->province->id])->id,
        'sms_pin' => '4821',
    ], $extra));
}

it('ao criar um condutor o PIN de SMS é obrigatório, cifrado e provisório', function () {
    $super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);

    createDriverVia($super, ['sms_pin' => null])->assertStatus(422)->assertJsonValidationErrors('sms_pin');

    $r = createDriverVia($super)->assertCreated();
    expect(json_encode($r->json()))->not->toContain('4821');

    $d = User::where('phone', '+244923777888')->first();
    expect(Hash::check('4821', $d->sms_pin_hash))->toBeTrue()->and($d->sms_pin_temporary)->toBeTrue()
        ->and(Hash::check('4821', $d->password))->toBeFalse();
});

it('o PIN não pode ser igual à palavra-passe, nem fácil, nem ter letras', function () {
    $super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);

    createDriverVia($super, ['password' => '482100', 'sms_pin' => '482100'])->assertStatus(422)->assertJsonValidationErrors('sms_pin');
    createDriverVia($super, ['sms_pin' => '1234'])->assertStatus(422)->assertJsonValidationErrors('sms_pin');
    createDriverVia($super, ['sms_pin' => '48a1'])->assertStatus(422)->assertJsonValidationErrors('sms_pin');
    expect(User::where('phone', '+244923777888')->exists())->toBeFalse();
});

it('SMS com PIN provisório: o admin que o definiu sabe-o, por isso não conta para pagar até alguém confirmar', function () {
    $this->driver->forceFill(['sms_pin_temporary' => true])->save();

    $r = smsRegister($this->admin)->assertCreated()->assertJsonPath('data.awaiting_confirmation', true);
    expect(BottlePickup::payable()->count())->toBe(0);

    // O condutor confirma na app.
    Sanctum::actingAs($this->driver);
    $this->postJson('/api/v1/driver/pickups/' . $r->json('data.id') . '/confirm')->assertOk();
    expect(BottlePickup::payable()->count())->toBe(1);
});

it('depois de o condutor mudar o PIN na app, os SMS contam normalmente', function () {
    $this->driver->forceFill(['sms_pin_temporary' => true, 'sms_pin_hash' => Hash::make('4821'), 'password' => Hash::make('Segredo123')])->save();
    Sanctum::actingAs($this->driver);
    $this->putJson('/api/v1/driver/sms-pin', ['password' => 'Segredo123', 'pin' => '7392'])->assertOk();
    expect($this->driver->fresh()->sms_pin_temporary)->toBeFalse();

    // O PIN antigo (que o admin sabia) já não serve.
    smsRegister($this->admin)->assertStatus(422);
    smsRegister($this->admin, ['sms_text' => 'CARREGAR 10 ' . $this->station->code . ' 7392'])->assertCreated()->assertJsonPath('data.awaiting_confirmation', false);
    expect(BottlePickup::payable()->count())->toBe(1);
});

it('o PIN novo do condutor também não pode ser igual à palavra-passe', function () {
    $this->driver->forceFill(['password' => Hash::make('738291')])->save();
    Sanctum::actingAs($this->driver);
    $this->putJson('/api/v1/driver/sms-pin', ['password' => '738291', 'pin' => '738291'])->assertStatus(422);
});

it('só o Super Admin/Provincial redefine o PIN, e fica provisório', function () {
    $prov = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->province->id]);

    Sanctum::actingAs($this->admin);
    $this->putJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin", ['pin' => '9051'])->assertForbidden();

    Sanctum::actingAs($prov);
    $this->putJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin", ['pin' => '1111'])->assertStatus(422);
    $this->putJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin", ['pin' => '9051'])->assertOk();
    $d = $this->driver->fresh();
    expect(Hash::check('9051', $d->sms_pin_hash))->toBeTrue()->and($d->sms_pin_temporary)->toBeTrue();
    expect(AuditLog::where('action', 'driver.sms_pin_reset')->count())->toBe(1);

    $outro = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->other->id]);
    Sanctum::actingAs($outro);
    $this->putJson("/api/v1/admin/drivers/{$this->driver->id}/sms-pin", ['pin' => '9051'])->assertForbidden();
});
