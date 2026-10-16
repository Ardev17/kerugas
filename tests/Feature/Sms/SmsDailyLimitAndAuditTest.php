<?php

use App\Enums\SmsDispatchStatus;
use App\Enums\SmsTemplateType;
use App\Enums\UserRole;
use App\Exceptions\SmsDailyLimitExceededException;
use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\Province;
use App\Models\SmsDispatch;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\FreshnessService;
use App\Services\Sms\OperationService;
use App\Services\Sms\SmsDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSmsProvider;

uses(RefreshDatabase::class);

/**
 * Monitorização/auditoria de SMS por Admin (pedido explícito do
 * cliente, Setembro 2026): limite diário configurável por Admin,
 * histórico escopado (só o Super Admin vê o de todos) e a nova vista
 * agregada `usage-by-admin`, estritamente Super Admin.
 */
beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
});

/**
 * SmsDispatch não tem factory própria (nunca precisou até agora) — um
 * helper local simples é suficiente para os testes de contagem/escopo
 * abaixo, sem introduzir uma Factory nova só para isto.
 */
function makeSmsDispatch(int $createdBy, SmsDispatchStatus $status = SmsDispatchStatus::SENT): SmsDispatch
{
    return SmsDispatch::create([
        'provider' => 'mock',
        'recipient' => '+244923000000',
        'message' => 'Mensagem de teste',
        'status' => $status,
        'created_by' => $createdBy,
    ]);
}

it('bloqueia dispatch() quando o Admin já atingiu o seu limite diário', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id, 'sms_daily_limit' => 2]);
    $dispatchService = new SmsDispatchService(new FakeSmsProvider(shouldSucceed: true));

    $operationService = new OperationService(new PhoneNumberService(), new FreshnessService());

    // Dois envios "gastam" a quota (para operações diferentes — a
    // idempotência é por operation_id, não deve interferir aqui).
    foreach ([1, 2] as $_) {
        $operation = $operationService->startDraft($admin, '923123456', null, null, null);
        $dispatchService->dispatch($admin, $operation->phone, 'Mensagem de teste', null, $operation);
    }

    expect($dispatchService->sentTodayCountFor($admin))->toBe(2);

    $thirdOperation = $operationService->startDraft($admin, '923123456', null, null, null);

    expect(fn () => $dispatchService->dispatch($admin, $thirdOperation->phone, 'Mensagem de teste', null, $thirdOperation))
        ->toThrow(SmsDailyLimitExceededException::class);

    // O envio bloqueado não deve ter criado um novo SmsDispatch.
    expect(SmsDispatch::count())->toBe(2);
});

it('não bloqueia quando sms_daily_limit é null (sem limite, comportamento padrão)', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id, 'sms_daily_limit' => null]);
    $dispatchService = new SmsDispatchService(new FakeSmsProvider(shouldSucceed: true));
    $operationService = new OperationService(new PhoneNumberService(), new FreshnessService());

    foreach (range(1, 5) as $_) {
        $operation = $operationService->startDraft($admin, '923123456', null, null, null);
        $dispatch = $dispatchService->dispatch($admin, $operation->phone, 'Mensagem', null, $operation);
        expect($dispatch->status)->toBe(SmsDispatchStatus::SENT);
    }

    expect(SmsDispatch::count())->toBe(5);
});

it('um envio FAILED não conta para a quota diária', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id, 'sms_daily_limit' => 1]);
    $dispatchService = new SmsDispatchService(new FakeSmsProvider(shouldSucceed: false));
    $operationService = new OperationService(new PhoneNumberService(), new FreshnessService());

    $operation = $operationService->startDraft($admin, '923123456', null, null, null);
    $dispatch = $dispatchService->dispatch($admin, $operation->phone, 'Mensagem', null, $operation);

    expect($dispatch->status)->toBe(SmsDispatchStatus::FAILED);
    expect($dispatchService->sentTodayCountFor($admin))->toBe(0);
});

it('a rota /operations/{id}/send devolve 429 com mensagem clara quando o limite é atingido', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id, 'sms_daily_limit' => 0]);
    $template = SmsTemplate::create([
        'name' => 'Geral (teste)', 'code' => 'TEST_GENERAL', 'type' => SmsTemplateType::GENERAL,
        'content' => 'Olá.', 'active' => true,
    ]);

    $operationService = new OperationService(new PhoneNumberService(), new FreshnessService());
    $operation = $operationService->startDraft($admin, '923123456', null, null, null);

    Sanctum::actingAs($admin);

    $response = $this->postJson("/api/v1/admin/operations/{$operation->id}/send", ['sms_template_id' => $template->id]);

    $response->assertStatus(429);
    $response->assertJsonPath('success', false);
    expect($response->json('message'))->toContain('limite diário');

    expect(SmsDispatch::count())->toBe(0);
    expect(AuditLog::where('action', 'sms_daily_limit_blocked')->count())->toBe(1);
});

it('um ADMIN só vê no histórico os SMS que ele próprio enviou', function () {
    $adminA = User::factory()->admin()->create(['name' => 'Admin da Ganda', 'province_id' => $this->province->id]);
    $adminB = User::factory()->admin()->create(['name' => 'Admin de Ondjiva', 'province_id' => $this->province->id]);

    collect(range(1, 2))->each(fn () => makeSmsDispatch($adminA->id));
    collect(range(1, 3))->each(fn () => makeSmsDispatch($adminB->id));

    Sanctum::actingAs($adminA);

    $response = $this->getJson('/api/v1/admin/sms/history');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);

    // Tentar espreitar o histórico do outro admin via query string não
    // deve funcionar para um Admin comum (só o Super Admin filtra por
    // created_by).
    $response = $this->getJson("/api/v1/admin/sms/history?created_by={$adminB->id}");
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2); // continua só os seus, o filtro foi ignorado
});

it('o Super Admin vê o histórico de todos os admins e pode filtrar por created_by', function () {
    $adminA = User::factory()->admin()->create(['province_id' => $this->province->id]);
    $adminB = User::factory()->admin()->create(['province_id' => $this->province->id]);

    collect(range(1, 2))->each(fn () => makeSmsDispatch($adminA->id));
    collect(range(1, 3))->each(fn () => makeSmsDispatch($adminB->id));

    Sanctum::actingAs($this->superAdmin);

    $response = $this->getJson('/api/v1/admin/sms/history');
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(5);

    $response = $this->getJson("/api/v1/admin/sms/history?created_by={$adminA->id}");
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('usage-by-admin é estritamente Super Admin e devolve contagens corretas', function () {
    $admin = User::factory()->admin()->create(['name' => 'Admin da Ganda', 'province_id' => $this->province->id, 'sms_daily_limit' => 10]);

    collect(range(1, 3))->each(fn () => makeSmsDispatch($admin->id, SmsDispatchStatus::SENT));
    makeSmsDispatch($admin->id, SmsDispatchStatus::FAILED);

    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/admin/sms/usage-by-admin')->assertStatus(403);

    Sanctum::actingAs($this->superAdmin);
    $response = $this->getJson('/api/v1/admin/sms/usage-by-admin');

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $admin->id);

    expect($row)->not->toBeNull();
    expect($row['sent_today'])->toBe(3); // FAILED não conta
    expect($row['daily_limit'])->toBe(10);
    expect($row['remaining_today'])->toBe(7);
});

it('um ADMIN comum não pode alterar sms_daily_limit de ninguém, nem de um utilizador comum do seu âmbito', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id]);
    // Alvo deliberadamente não-privilegiado (USER, mesma província) —
    // isola a regra de sms_daily_limit da regra, já testada noutro
    // ficheiro, de que um Admin não pode tocar em contas privilegiadas.
    $targetUser = User::factory()->create(['role' => UserRole::USER, 'province_id' => $this->province->id]);

    Sanctum::actingAs($admin);

    $response = $this->putJson("/api/v1/admin/users/{$targetUser->id}", ['sms_daily_limit' => 999]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('sms_daily_limit');
    expect($targetUser->fresh()->sms_daily_limit)->toBeNull();
});

it('o Super Admin consegue definir o limite diário de SMS de um Admin', function () {
    $admin = User::factory()->admin()->create(['province_id' => $this->province->id]);

    Sanctum::actingAs($this->superAdmin);

    $response = $this->putJson("/api/v1/admin/users/{$admin->id}", ['sms_daily_limit' => 25]);

    $response->assertOk();
    expect($admin->fresh()->sms_daily_limit)->toBe(25);
});
