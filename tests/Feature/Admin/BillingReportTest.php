<?php

use App\Enums\UserRole;
use App\Models\DriverCashReceipt;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Relatórios da faturação (Outubro 2026): fecho do dia, dívida aos postos
 * e dinheiro dos condutores — Excel (CSV) e PDF, com o âmbito da Faturação.
 */
beforeEach(function () {
    $this->province = Province::factory()->create(['name' => 'Huíla']);
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->other = Province::factory()->create(['name' => 'Luanda']);
    $this->otherMun = Municipality::factory()->create(['province_id' => $this->other->id]);

    $this->admin = User::factory()->admin()->create(['province_id' => $this->province->id, 'name' => 'Admin Huíla']);
    $this->otherAdmin = User::factory()->admin()->create(['province_id' => $this->province->id, 'name' => 'Outro Admin']);
    $this->super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->driver = User::factory()->create([
        'role' => UserRole::DRIVER, 'province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'name' => 'Carlos Condutor',
    ]);
    $this->station = Station::factory()->create(['province_id' => $this->province->id, 'municipality_id' => $this->municipality->id, 'name' => 'Posto Lubango']);
});

function reportOrder(array $o = []): GasOrder
{
    return GasOrder::factory()->create(array_merge([
        'province_id' => test()->province->id, 'municipality_id' => test()->municipality->id,
        'status' => 'DELIVERED', 'quantity' => 2, 'bottle_price' => 2400, 'commission' => 600, 'delivery_price' => 3000,
        'price_set_by' => test()->admin->id, 'price_set_at' => now(), 'completed_at' => now(),
        'assigned_to' => test()->driver->id, 'order_reference' => 'QG-' . fake()->unique()->numerify('#####'),
    ], $o));
}

function csvOf($response): string
{
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    return $response->streamedContent();
}

it('fecho do dia em Excel: entregas, totais, carregamentos e condutores', function () {
    reportOrder(['order_reference' => 'QG-HOJE1']);
    reportOrder(['order_reference' => 'QG-ONTEM', 'completed_at' => now()->subDays(2)]);
    reportOrder(['order_reference' => 'QG-OUTRO', 'price_set_by' => $this->otherAdmin->id]); // não é dele
    giveDriverStock($this->driver, 10, $this->station, 12000);
    Sanctum::actingAs($this->admin);

    $csv = csvOf($this->get('/api/v1/admin/billing/export?type=day&format=csv'));

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Fecho do dia')
        ->toContain('QG-HOJE1')
        ->not->toContain('QG-ONTEM')
        ->not->toContain('QG-OUTRO')
        ->toContain('Carlos Condutor')
        ->toContain('Posto Lubango')
        ->toContain('600,00 Kz')
        ->toContain('12.000,00 Kz');
});

it('fecho do dia de outra data e pedidos antigos assinalados', function () {
    $day = now('Africa/Luanda')->subDays(2);
    reportOrder(['order_reference' => 'QG-ANTIGO', 'bottle_price' => null, 'commission' => null, 'completed_at' => $day->copy()->setTime(12, 0)]);
    Sanctum::actingAs($this->admin);

    $csv = csvOf($this->get('/api/v1/admin/billing/export?type=day&format=csv&date=' . $day->format('Y-m-d')));

    expect($csv)->toContain('QG-ANTIGO')->toContain('por completar')->toContain('sem divisão');
});

it('dívida aos postos: só carregamentos por pagar e da província de quem pede', function () {
    giveDriverStock($this->driver, 10, $this->station, 12000);
    giveDriverStock($this->driver, 3, $this->station, 3600)->update(['station_paid_at' => now()]);
    $otherDriver = User::factory()->create(['role' => UserRole::DRIVER, 'province_id' => $this->other->id]);
    giveDriverStock($otherDriver, 5, Station::factory()->create(['name' => 'Posto Viana']), 6000);
    Sanctum::actingAs($this->admin);

    $csv = csvOf($this->get('/api/v1/admin/billing/export?type=stations&format=csv'));

    expect($csv)->toContain('Dívida aos postos')->toContain('Posto Lubango')->toContain('12.000,00 Kz')
        ->not->toContain('3.600,00 Kz')
        ->not->toContain('Posto Viana');

    Sanctum::actingAs($this->super);
    expect(csvOf($this->get('/api/v1/admin/billing/export?type=stations&format=csv')))->toContain('Posto Viana');
});

it('dinheiro dos condutores: cobrado e recebido no período, em falta hoje', function () {
    reportOrder();
    reportOrder(['completed_at' => now()->subMonths(2)]); // fora do período, mas conta no em falta
    DriverCashReceipt::create(['driver_id' => $this->driver->id, 'received_by' => $this->admin->id, 'amount' => 1000, 'note' => 'manhã', 'received_at' => now()]);
    Sanctum::actingAs($this->admin);

    $from = now('Africa/Luanda')->subDays(5)->format('Y-m-d');
    $to = now('Africa/Luanda')->format('Y-m-d');
    $csv = csvOf($this->get("/api/v1/admin/billing/export?type=drivers&format=csv&from={$from}&to={$to}"));

    expect($csv)->toContain('Carlos Condutor')
        ->toContain('3.000,00 Kz')   // cobrado no período
        ->toContain('1.000,00 Kz')   // recebido
        ->toContain('5.000,00 Kz')   // em falta hoje: 6000 - 1000
        ->toContain('manhã');
});

it('valida o pedido e respeita a permissão de faturação', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/admin/billing/export?type=outro&format=csv')->assertStatus(422);
    $this->getJson('/api/v1/admin/billing/export?type=day&format=docx')->assertStatus(422);
    $this->getJson('/api/v1/admin/billing/export?type=drivers&format=csv&from=2026-10-05&to=2026-10-01')->assertStatus(422);

    Sanctum::actingAs($this->driver);
    $this->getJson('/api/v1/admin/billing/export?type=day&format=csv')->assertForbidden();
});

it('PDF: gera o ficheiro quando o pacote existe, ou explica o que falta', function () {
    reportOrder();
    Sanctum::actingAs($this->admin);

    $response = $this->get('/api/v1/admin/billing/export?type=day&format=pdf');

    if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('pdf');
    } else {
        $response->assertStatus(503)->assertJsonPath('success', false);
    }
});
