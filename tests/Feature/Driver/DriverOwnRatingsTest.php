<?php

use App\Enums\GasOrderStatus;
use App\Enums\UserRole;
use App\Models\DriverRating;
use App\Models\GasOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * O condutor vê as avaliações que recebeu (Outubro 2026) — sem nunca
 * identificar quem avaliou.
 */
function ownRatingsTestRate(User $driver, int $stars, ?string $comment = null, $when = null): DriverRating
{
    $order = GasOrder::factory()->create([
        'assigned_to' => $driver->id, 'status' => GasOrderStatus::DELIVERED,
        'customer_name' => 'Cliente Secreto', 'customer_phone' => '+244923111222', 'address' => 'Rua Escondida 7',
    ]);
    $rating = DriverRating::create(['gas_order_id' => $order->id, 'driver_id' => $driver->id, 'rating' => $stars, 'comment' => $comment]);
    if ($when) {
        $rating->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
    }

    return $rating;
}

beforeEach(function () {
    $this->driver = User::factory()->create(['role' => UserRole::DRIVER]);
});

it('mostra as suas avaliações, da mais recente para a mais antiga, com resumo', function () {
    ownRatingsTestRate($this->driver, 5, 'Muito rápido, obrigado!', now()->subDays(40));
    ownRatingsTestRate($this->driver, 4, null, now()->subDays(2));
    ownRatingsTestRate($this->driver, 2, 'Chegou atrasado', now()->subDay());
    Sanctum::actingAs($this->driver);

    $res = $this->getJson('/api/v1/driver/ratings')->assertOk();

    expect(collect($res->json('data'))->pluck('rating')->all())->toBe([2, 4, 5]);
    expect($res->json('data.0'))->toMatchArray(['rating' => 2, 'comment' => 'Chegou atrasado', 'date' => now()->subDay()->toDateString()]);
    expect($res->json('summary'))->toMatchArray([
        'average' => 3.67, 'count' => 3, 'with_comment' => 2,
        'distribution' => ['5' => 1, '4' => 1, '3' => 0, '2' => 1, '1' => 0],
        'last_30_days' => ['average' => 3, 'count' => 2],
    ]);
    expect($res->json('meta.total'))->toBe(3);
});

it('nunca identifica quem avaliou (nome, telefone, morada, referência ou hora)', function () {
    $rating = ownRatingsTestRate($this->driver, 1, 'Mal-educado');
    Sanctum::actingAs($this->driver);

    $res = $this->getJson('/api/v1/driver/ratings')->assertOk();
    $json = $res->getContent();

    expect(array_keys($res->json('data.0')))->toBe(['id', 'rating', 'comment', 'date']);
    expect($json)->not->toContain('Cliente Secreto')->not->toContain('923111222')->not->toContain('Rua Escondida')
        ->not->toContain($rating->order->order_reference);
    expect($res->json('data.0.date'))->toMatch('/^\d{4}-\d{2}-\d{2}$/'); // só o dia
});

it('filtra por estrelas e só mostra as do próprio condutor', function () {
    ownRatingsTestRate($this->driver, 5);
    ownRatingsTestRate($this->driver, 1);
    ownRatingsTestRate(User::factory()->create(['role' => UserRole::DRIVER]), 5, 'De outro condutor');
    Sanctum::actingAs($this->driver);

    $this->getJson('/api/v1/driver/ratings?stars=5')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.rating', 5)
        ->assertJsonPath('summary.count', 2); // o resumo é sempre do total
    $this->getJson('/api/v1/driver/ratings?stars=9')->assertOk()->assertJsonCount(2, 'data'); // valor inválido = todas
    expect($this->getJson('/api/v1/driver/ratings')->getContent())->not->toContain('De outro condutor');
});

it('sem avaliações devolve um resumo vazio e é só para condutores', function () {
    Sanctum::actingAs($this->driver);
    $this->getJson('/api/v1/driver/ratings')->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('summary.average', null)
        ->assertJsonPath('summary.count', 0);

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::USER]));
    $this->getJson('/api/v1/driver/ratings')->assertForbidden();
});
