<?php

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Favorite;
use App\Models\GasOrder;
use App\Models\Station;
use App\Models\User;
use App\Services\Media\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Eliminação de conta pela própria pessoa (Outubro 2026, Google Play):
 * pedido com palavra-passe → 7 dias → anonimização; entrar cancela.
 */
function deletionTestUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Ana Silva', 'phone' => '+244923555111', 'email' => 'ana@exemplo.ao',
        'password' => Hash::make('Segredo123'), 'role' => UserRole::USER,
    ], $attrs));
}

function deletionTestRequest($test, array $payload = []): \Illuminate\Testing\TestResponse
{
    return $test->postJson('/api/v1/users/me/deletion', array_merge([
        'current_password' => 'Segredo123', 'confirmation' => 'ELIMINAR',
    ], $payload));
}

it('pede a eliminação: fica agendada para daqui a 7 dias e todas as sessões terminam', function () {
    $user = deletionTestUser();
    $user->createToken('telemovel');
    $user->createToken('computador');
    Sanctum::actingAs($user);

    $res = deletionTestRequest($this)->assertOk();

    $user->refresh();
    expect($user->deletion_scheduled_for)->not->toBeNull();
    expect((int) round(now()->diffInDays($user->deletion_scheduled_for)))->toBe(7);
    expect($res->json('data.grace_days'))->toBe(7);
    expect($user->tokens()->count())->toBe(0);
    expect(AuditLog::where('action', 'account.deletion_requested')->where('entity_id', $user->id)->exists())->toBeTrue();
    // durante o prazo não recebe promoções nem lembretes
    expect($user->wantsNotifications('promos'))->toBeFalse();
});

it('exige a palavra-passe certa e a palavra ELIMINAR', function () {
    $user = deletionTestUser();
    Sanctum::actingAs($user);

    deletionTestRequest($this, ['current_password' => 'errada'])->assertStatus(422)->assertJsonValidationErrors('current_password');
    deletionTestRequest($this, ['confirmation' => 'eliminar'])->assertStatus(422)->assertJsonValidationErrors('confirmation');

    expect($user->fresh()->deletion_scheduled_for)->toBeNull();
});

it('entrar de novo durante o prazo cancela a eliminação', function () {
    $user = deletionTestUser(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(5)]);

    $this->postJson('/api/v1/auth/login', ['identifier' => '923555111', 'password' => 'Segredo123'])
        ->assertOk()
        ->assertJsonPath('data.deletion_cancelled', true);

    expect($user->fresh()->deletion_scheduled_for)->toBeNull();
    expect(AuditLog::where('action', 'account.deletion_cancelled')->exists())->toBeTrue();
});

it('bloqueia o condutor com garrafas ou dinheiro por acertar', function () {
    $driver = deletionTestUser(['role' => UserRole::DRIVER]);
    giveDriverStock($driver, 3);
    Sanctum::actingAs($driver);

    $this->getJson('/api/v1/users/me/deletion')->assertOk()->assertJsonCount(1, 'data.blockers');
    deletionTestRequest($this)->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_DELETION_BLOCKED');

    expect($driver->fresh()->deletion_scheduled_for)->toBeNull();
});

it('bloqueia o operador com posto, um pedido em curso e o último Super Admin', function () {
    $operator = deletionTestUser(['role' => UserRole::STATION_OPERATOR]);
    assignOperator($operator, Station::factory()->create());
    Sanctum::actingAs($operator);
    deletionTestRequest($this)->assertStatus(422);

    $customer = deletionTestUser(['phone' => '+244923555222', 'email' => null]);
    GasOrder::factory()->create(['user_id' => $customer->id, 'status' => GasOrderStatus::CONFIRMED]);
    Sanctum::actingAs($customer);
    deletionTestRequest($this)->assertStatus(422);

    $super = deletionTestUser(['phone' => '+244923555333', 'email' => null, 'role' => UserRole::SUPER_ADMIN]);
    Sanctum::actingAs($super);
    deletionTestRequest($this)->assertStatus(422);
});

it('passado o prazo, anonimiza a conta, mantém os pedidos sem dados pessoais e liberta o número', function () {
    Storage::fake(AvatarService::DISK);
    $user = deletionTestUser();
    $avatar = 'avatars/' . str_repeat('e', 40) . '.webp';
    Storage::disk(AvatarService::DISK)->put($avatar, 'img');
    $user->forceFill([
        'avatar_path' => $avatar, 'latitude' => -14.9, 'longitude' => 13.5,
        'deletion_requested_at' => now()->subDays(8), 'deletion_scheduled_for' => now()->subDay(),
    ])->save();
    $user->createToken('x');
    $order = GasOrder::factory()->create([
        'user_id' => $user->id, 'status' => GasOrderStatus::DELIVERED, 'customer_name' => 'Ana Silva',
        'customer_phone' => '+244923555111', 'address' => 'Rua 1', 'delivery_price' => 9000,
    ]);
    Favorite::create(['user_id' => $user->id, 'station_id' => Station::factory()->create()->id]);
    // restos noutras tabelas
    $guestOrder = GasOrder::factory()->create([
        'user_id' => null, 'status' => GasOrderStatus::DELIVERED, 'customer_name' => 'Ana (sem conta)', 'customer_phone' => '923555111',
    ]);
    AuditLog::create(['user_id' => $user->id, 'action' => 'profile.updated', 'entity_type' => User::class, 'entity_id' => $user->id,
        'old_values' => ['phone' => '+244923000000'], 'new_values' => ['phone' => '+244923555111'], 'ip_address' => '1.2.3.4', 'user_agent' => 'Android']);
    \App\Models\StationReview::create(['station_id' => Station::factory()->create()->id, 'user_id' => $user->id, 'rating' => 4, 'comment' => 'Sou a Ana do bairro X']);
    \App\Models\ContactMessage::create(['name' => 'Ana', 'contact' => '923 555 111', 'message' => 'Olá', 'status' => 'NEW']);

    $this->artisan('kerugas:accounts-purge')->assertSuccessful();

    expect($guestOrder->fresh()->customer_name)->toBe('Cliente eliminado');
    $audit = AuditLog::where('action', 'profile.updated')->first();
    expect($audit->old_values)->toBeNull();
    expect($audit->ip_address)->toBeNull();
    expect(\App\Models\StationReview::where('user_id', $user->id)->value('comment'))->toBeNull();
    expect(\App\Models\ContactMessage::count())->toBe(0);

    $deleted = User::withTrashed()->find($user->id);
    expect($deleted->trashed())->toBeTrue();
    expect($deleted->name)->toBe('Conta eliminada');
    expect($deleted->phone)->not->toContain('923555111');
    expect($deleted->email)->toBeNull();
    expect($deleted->avatar_path)->toBeNull();
    expect($deleted->latitude)->toBeNull();
    expect($deleted->tokens()->count())->toBe(0);
    expect(Favorite::where('user_id', $user->id)->count())->toBe(0);
    Storage::disk(AvatarService::DISK)->assertMissing($avatar);

    $order->refresh();
    expect($order->customer_name)->toBe('Cliente eliminado');
    expect($order->address)->toBeNull();
    expect((float) $order->delivery_price)->toBe(9000.0); // o registo financeiro fica

    expect(AuditLog::where('action', 'account.deleted')->where('entity_id', $user->id)->exists())->toBeTrue();

    // a pessoa pode criar uma conta nova com o mesmo número
    $province = \App\Models\Province::factory()->create();
    $municipality = \App\Models\Municipality::factory()->create(['province_id' => $province->id]);
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ana Nova', 'phone' => '923555111', 'password' => 'Segredo123', 'password_confirmation' => 'Segredo123',
        'province_id' => $province->id, 'municipality_id' => $municipality->id,
    ])->assertCreated();
});

it('não elimina antes do prazo nem quem cancelou', function () {
    $early = deletionTestUser(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(3)]);

    $this->artisan('kerugas:accounts-purge')->assertSuccessful();

    expect($early->fresh())->not->toBeNull();
    expect($early->fresh()->name)->toBe('Ana Silva');
});

it('adia a eliminação se, entretanto, ficou algo por acertar', function () {
    $driver = deletionTestUser(['role' => UserRole::DRIVER, 'deletion_requested_at' => now()->subDays(8), 'deletion_scheduled_for' => now()->subDay()]);
    giveDriverStock($driver, 2);

    $this->artisan('kerugas:accounts-purge')->assertSuccessful();

    expect($driver->fresh())->not->toBeNull();
    expect($driver->fresh()->deletion_scheduled_for)->not->toBeNull();
});
