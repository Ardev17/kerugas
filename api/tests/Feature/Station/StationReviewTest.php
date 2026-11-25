<?php

use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $province = Province::factory()->create();
    $municipality = Municipality::factory()->create(['province_id' => $province->id]);

    $this->station = Station::factory()->create([
        'province_id' => $province->id,
        'municipality_id' => $municipality->id,
    ]);

    $this->user = User::factory()->create();
});

it('exige autenticação para avaliar um posto', function () {
    $response = $this->postJson("/api/v1/stations/{$this->station->id}/reviews", [
        'rating' => 5,
    ]);

    $response->assertStatus(401);
});

it('permite a um utilizador autenticado avaliar um posto', function () {
    Sanctum::actingAs($this->user);

    $response = $this->postJson("/api/v1/stations/{$this->station->id}/reviews", [
        'rating' => 4,
        'comment' => 'Bom atendimento.',
    ]);

    $response->assertCreated();
    expect(StationReview::count())->toBe(1);
});

it('uma segunda avaliação do mesmo utilizador atualiza a existente em vez de duplicar', function () {
    Sanctum::actingAs($this->user);

    $this->postJson("/api/v1/stations/{$this->station->id}/reviews", ['rating' => 3]);
    $this->postJson("/api/v1/stations/{$this->station->id}/reviews", ['rating' => 5, 'comment' => 'Melhorou muito.']);

    expect(StationReview::count())->toBe(1);
    expect(StationReview::first()->rating)->toBe(5);
});

it('rejeita rating fora do intervalo 1-5', function () {
    Sanctum::actingAs($this->user);

    $response = $this->postJson("/api/v1/stations/{$this->station->id}/reviews", ['rating' => 6]);

    $response->assertStatus(422)->assertJsonValidationErrors('rating');
});
