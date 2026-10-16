<?php

use App\Enums\SmsMessageStatus;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\SmsMessage;
use App\Models\Station;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\Sms\SmsCommandParser;
use App\Services\Sms\SmsProcessingService;
use App\Services\StationStatusService;
use App\Services\TrustScoreService;
use App\Services\Sms\MockSmsProvider;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ligação explícita para o teste não depender do binding feito em
    // produção no AppServiceProvider (ver apps/api/README.md).
    $this->app->bind(SmsProviderInterface::class, fn () => new MockSmsProvider());

    $this->service = new SmsProcessingService(
        new PhoneNumberService(),
        new SmsCommandParser(),
        new StationStatusService(new TrustScoreService()),
    );

    $province = Province::factory()->create();
    $municipality = Municipality::factory()->create(['province_id' => $province->id]);

    $this->station = Station::factory()->create([
        'province_id' => $province->id,
        'municipality_id' => $municipality->id,
        'sms_phone' => '+244911000099',
    ]);
});

it('atualiza o estado do posto quando recebe um código válido do número autorizado', function () {
    $this->service->process([
        'provider_message_id' => 'msg-1',
        'sender' => '+244911000099',
        'recipient' => null,
        'body' => '10',
        'received_at' => now(),
    ]);

    $this->station->refresh();

    expect($this->station->current_gas_available)->toBeTrue();
    expect($this->station->current_queue_level->value)->toBe('LOW');
});

it('REGRA 1: ignora comando de um número NÃO autorizado (não é o sms_phone de nenhum posto)', function () {
    $result = $this->service->process([
        'provider_message_id' => 'msg-2',
        'sender' => '+244999999999',
        'recipient' => null,
        'body' => '10',
        'received_at' => now(),
    ]);

    $this->station->refresh();

    // O posto não deve ter sido alterado.
    expect($this->station->current_gas_available)->toBeFalse();
    expect($result->status)->toBe(SmsMessageStatus::INVALID);
});

it('REGRA 2: SMS com código inválido não altera o estado', function () {
    $result = $this->service->process([
        'provider_message_id' => 'msg-3',
        'sender' => '+244911000099',
        'recipient' => null,
        'body' => '99',
        'received_at' => now(),
    ]);

    $this->station->refresh();

    expect($this->station->current_gas_available)->toBeFalse();
    expect($result->status)->toBe(SmsMessageStatus::INVALID);
});

it('REGRA 3: SMS duplicado (mesmo provider_message_id) não gera atualização duplicada', function () {
    $payload = [
        'provider_message_id' => 'msg-duplicado',
        'sender' => '+244911000099',
        'recipient' => null,
        'body' => '10',
        'received_at' => now(),
    ];

    $this->service->process($payload);
    $this->service->process($payload);
    $this->service->process($payload);

    expect(SmsMessage::where('provider_message_id', 'msg-duplicado')->count())->toBe(1);
    expect($this->station->statuses()->count())->toBe(1);
});

it('REGRA FUNDAMENTAL: NÃO responde automaticamente a um cliente que envia "GAS", mesmo sendo um utilizador registado', function () {
    // Revisão da Central de Operações KeruGás (2026): a consulta de
    // disponibilidade por SMS deixou de ser automática. Um cliente que
    // envia "GAS" para o número oficial chega ao telefone físico da
    // Central — o KeruGás não deve reagir a isso sozinho. O atendimento
    // é sempre iniciado manualmente por um admin (ver OperationService).
    $province = Province::first();
    $municipality = Municipality::first();

    $user = User::factory()->create([
        'phone' => '+244922000001',
        'municipality_id' => $municipality->id,
        'province_id' => $province->id,
    ]);

    $this->station->update(['current_gas_available' => true, 'status_updated_at' => now()]);

    $result = $this->service->process([
        'provider_message_id' => 'msg-consulta',
        'sender' => '+244922000001',
        'recipient' => null,
        'body' => 'GAS',
        'received_at' => now(),
    ]);

    expect($result->status)->toBe(SmsMessageStatus::INVALID);
    expect($result->user_id)->toBeNull();
});
