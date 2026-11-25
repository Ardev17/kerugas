<?php

use App\Enums\OperationStatus;
use App\Enums\QueueLevel;
use App\Enums\StationStatus;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\Province;
use App\Models\SmsTemplate;
use App\Models\Station;
use App\Models\User;
use App\Services\Sms\SmsProviderInterface;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSmsProvider;

uses(RefreshDatabase::class);

/**
 * Central de Operações (Novembro 2026): template "Informação de posto" —
 * município e depois UM posto por mensagem; os acentos ficam e o contador
 * mostra caracteres e SMS.
 */
beforeEach(function () {
    $this->seed(SmsTemplateSeeder::class);
    $this->benguela = Province::factory()->create(['name' => 'Benguela']);
    $this->lobito = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Lobito']);
    $this->catumbela = Municipality::factory()->create(['province_id' => $this->benguela->id, 'name' => 'Catumbela']);

    $this->posto = Station::factory()->create([
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
        'name' => 'Posto Restinga', 'address' => 'Rua da Praia', 'reference_point' => 'Junto ao mercado',
        'phone' => '+244923000999', 'current_gas_available' => true, 'current_queue_level' => QueueLevel::cases()[0],
        'status_updated_at' => now(),
    ]);
    $this->semGas = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id, 'name' => 'Posto Compão', 'current_gas_available' => false]);
    $this->outro = Station::factory()->create(['province_id' => $this->benguela->id, 'municipality_id' => $this->catumbela->id, 'name' => 'Posto Catumbela']);

    $this->super = User::factory()->create(['role' => \App\Enums\UserRole::SUPER_ADMIN]);
    $this->sms = new FakeSmsProvider();
    app()->instance(SmsProviderInterface::class, $this->sms);

    $this->op = Operation::create([
        'admin_id' => $this->super->id, 'phone' => '+244923000111', 'status' => OperationStatus::DRAFT,
        'province_id' => $this->benguela->id, 'municipality_id' => $this->lobito->id,
    ]);
    $this->info = SmsTemplate::where('code', 'STATION_INFO')->firstOrFail();
    Sanctum::actingAs($this->super);
});

it('lista todos os postos ativos do município (com e sem gás), com número e morada', function () {
    $r = $this->getJson("/api/v1/admin/operations/{$this->op->id}/stations")->assertOk();
    $names = collect($r->json('data'))->pluck('name')->all();

    expect($names)->toContain('Posto Restinga', 'Posto Compão')
        ->not->toContain('Posto Catumbela')
        ->and(collect($r->json('data'))->firstWhere('name', 'Posto Restinga'))->toHaveKeys(['code', 'address']);
});

it('o template de posto exige escolher um posto', function () {
    $this->postJson("/api/v1/admin/operations/{$this->op->id}/preview", ['sms_template_id' => $this->info->id])
        ->assertUnprocessable()->assertJsonValidationErrors('station_id');
});

it('pré-visualiza com os dados de um só posto e tira os acentos', function () {
    $r = $this->postJson("/api/v1/admin/operations/{$this->op->id}/preview", ['sms_template_id' => $this->info->id, 'station_id' => $this->posto->id])
        ->assertOk();

    $msg = $r->json('data.message');
    expect($msg)->toContain('KERUGAS')
        ->not->toMatch('/[áàâãçéêíóôõú]/iu')
        ->toContain('Posto Restinga')
        ->toContain('Lobito')
        ->toContain('com gas')
        ->toContain('Rua da Praia, Junto ao mercado')
        ->not->toContain('Posto Compão')
        ->and($r->json('data.encoding'))->toBe('GSM-7')
        ->and($r->json('data.characters'))->toBe(mb_strlen($msg))
        ->and($r->json('data.station.id'))->toBe($this->posto->id);
});

it('recusa um posto de outro município ou inativo', function () {
    $this->postJson("/api/v1/admin/operations/{$this->op->id}/preview", ['sms_template_id' => $this->info->id, 'station_id' => $this->outro->id])
        ->assertUnprocessable()->assertJsonValidationErrors('station_id');

    $this->semGas->update(['status' => StationStatus::INACTIVE]);
    $this->postJson("/api/v1/admin/operations/{$this->op->id}/preview", ['sms_template_id' => $this->info->id, 'station_id' => $this->semGas->id])
        ->assertUnprocessable()->assertJsonValidationErrors('station_id');
});

it('envia exatamente a mensagem do posto escolhido e regista o posto na auditoria', function () {
    $this->postJson("/api/v1/admin/operations/{$this->op->id}/send", ['sms_template_id' => $this->info->id, 'station_id' => $this->semGas->id])
        ->assertCreated();

    $sent = $this->sms->sent[0]['message'];
    expect($sent)->toContain('Posto Compao')->toContain('sem gas')
        ->and(\App\Models\AuditLog::where('action', 'sms_send')->latest('id')->first()->new_values['station_id'])->toBe($this->semGas->id);
});

it('os outros templates continuam a ignorar o posto e sem acentos', function () {
    $tpl = SmsTemplate::where('code', 'NO_GAS')->firstOrFail();
    $r = $this->postJson("/api/v1/admin/operations/{$this->op->id}/preview", ['sms_template_id' => $tpl->id])->assertOk();

    expect($r->json('data.message'))->toContain('KERUGAS')->not->toContain('KERUGÁS')->and($r->json('data.station'))->toBeNull();
});
