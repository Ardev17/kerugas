<?php

use App\Enums\OperationStatus;
use App\Enums\QueueLevel;
use App\Enums\SmsDispatchStatus;
use App\Enums\SmsTemplateType;
use App\Enums\StationStatus;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\Province;
use App\Models\SmsDispatch;
use App\Models\SmsTemplate;
use App\Models\Station;
use App\Models\User;
use App\Services\FreshnessService;
use App\Services\PhoneNumberService;
use App\Services\Sms\OperationService;
use App\Services\Sms\SmsDispatchService;
use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\SmsTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsProvider;

uses(RefreshDatabase::class);

/**
 * Testa o fluxo manual da Central de Operações de ponta a ponta,
 * confirmando a REGRA FUNDAMENTAL: nada aqui é acionado por um SMS
 * recebido — é sempre um admin a iniciar o atendimento.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'ADMIN']);

    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);

    $this->operationService = new OperationService(new PhoneNumberService(), new FreshnessService());
});

it('inicia um atendimento manual e normaliza o telefone do cliente', function () {
    $operation = $this->operationService->startDraft(
        $this->admin,
        '923123456',
        $this->province->id,
        $this->municipality->id,
        'Boa tarde, tem gás na Ganda?',
    );

    expect($operation->phone)->toBe('+244923123456');
    expect($operation->status)->toBe(OperationStatus::DRAFT);
    expect($operation->original_message)->toBe('Boa tarde, tem gás na Ganda?');
});

it('lista apenas postos ativos do município, com estado de gás e fila', function () {
    Station::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'status' => StationStatus::ACTIVE,
        'current_gas_available' => true,
        'current_queue_level' => QueueLevel::LOW,
    ]);

    Station::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
        'status' => StationStatus::INACTIVE, // não deve aparecer
    ]);

    $stations = $this->operationService->stationsForMunicipality($this->municipality);

    expect($stations)->toHaveCount(1);
    expect($stations->first()['gas_available'])->toBeTrue();
});

it('envia o SMS através do provider configurado e regista o histórico', function () {
    $fakeProvider = new FakeSmsProvider(shouldSucceed: true);
    $dispatchService = new SmsDispatchService($fakeProvider);

    $template = SmsTemplate::create([
        'name' => 'Disponibilidade (teste)',
        'code' => 'TEST_AVAILABILITY',
        'type' => SmsTemplateType::AVAILABILITY,
        'content' => 'Olá, {{quantidade_postos}} postos em {{municipio}}.',
        'active' => true,
    ]);

    $operation = $this->operationService->startDraft($this->admin, '923123456', $this->province->id, $this->municipality->id, null);

    $renderer = new SmsTemplateRenderer();
    $message = $renderer->render($template->content, ['quantidade_postos' => '2', 'municipio' => $this->municipality->name]);

    $dispatch = $dispatchService->dispatch($this->admin, $operation->phone, $message, $template, $operation);

    expect($dispatch->status)->toBe(SmsDispatchStatus::SENT);
    expect($fakeProvider->sent)->toHaveCount(1);
    expect($fakeProvider->sent[0]['to'])->toBe('+244923123456');
    expect(SmsDispatch::count())->toBe(1);
});

it('regista falha quando o provider recusa o envio, sem lançar exceção', function () {
    $fakeProvider = new FakeSmsProvider(shouldSucceed: false);
    $dispatchService = new SmsDispatchService($fakeProvider);

    $operation = $this->operationService->startDraft($this->admin, '923123456', null, null, null);

    $dispatch = $dispatchService->dispatch($this->admin, $operation->phone, 'Mensagem de teste', null, $operation);

    expect($dispatch->status)->toBe(SmsDispatchStatus::FAILED);
    expect($dispatch->error_message)->not->toBeNull();
});

it('é idempotente: não envia duas vezes para a mesma operação (proteção contra duplo clique)', function () {
    $fakeProvider = new FakeSmsProvider(shouldSucceed: true);
    $dispatchService = new SmsDispatchService($fakeProvider);

    $operation = $this->operationService->startDraft($this->admin, '923123456', null, null, null);

    $first = $dispatchService->dispatch($this->admin, $operation->phone, 'Mensagem', null, $operation);
    $second = $dispatchService->dispatch($this->admin, $operation->phone, 'Mensagem', null, $operation);

    expect($first->id)->toBe($second->id);
    expect($fakeProvider->sent)->toHaveCount(1);
    expect(SmsDispatch::count())->toBe(1);
});

it('nunca chama o provider real de produção nos testes (usa sempre o Fake)', function () {
    // Confirma que a app não está a resolver SmsProviderInterface para
    // ProductionSmsProvider durante os testes — reforça a secção 69.
    $resolved = app(SmsProviderInterface::class);

    expect($resolved)->not->toBeInstanceOf(\App\Services\Sms\ProductionSmsProvider::class);
});
