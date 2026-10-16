<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\StationComment;
use App\Models\StationReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Auditoria de segurança (Setembro 2026): moderação de comentários,
 * resolução de denúncias e atribuição de pedidos não deixavam rasto
 * nenhum em `AuditLog` — se uma conta de Admin fosse comprometida,
 * essas ações podiam ser feitas sem ninguém conseguir saber quem fez
 * o quê depois. Estes testes confirmam que o rasto agora existe.
 */
beforeEach(function () {
    $this->province = Province::factory()->create();
    $this->municipality = Municipality::factory()->create(['province_id' => $this->province->id]);
    $this->station = Station::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
});

it('regista em AuditLog a moderação de um comentário', function () {
    $comment = StationComment::factory()->create(['station_id' => $this->station->id]);

    Sanctum::actingAs($this->admin);

    $response = $this->patchJson("/api/v1/admin/comments/{$comment->id}/status", ['status' => 'VISIBLE']);

    $response->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'comment.status_updated',
        'entity_type' => StationComment::class,
        'entity_id' => $comment->id,
    ]);
});

it('regista em AuditLog a resolução de uma denúncia', function () {
    $report = StationReport::factory()->create(['station_id' => $this->station->id]);

    Sanctum::actingAs($this->admin);

    $response = $this->patchJson("/api/v1/admin/reports/{$report->id}/status", ['status' => 'RESOLVED']);

    $response->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'report.status_updated',
        'entity_type' => StationReport::class,
        'entity_id' => $report->id,
    ]);
});

it('o histórico de um pedido de gás regista a quem foi atribuído, não só a mudança de estado', function () {
    $order = GasOrder::factory()->create([
        'province_id' => $this->province->id,
        'municipality_id' => $this->municipality->id,
    ]);
    $assignee = User::factory()->create(['name' => 'Operador Teste']);

    Sanctum::actingAs($this->admin);

    $response = $this->postJson("/api/v1/admin/orders/{$order->id}/assign", ['assignee_id' => $assignee->id]);

    $response->assertOk();

    $this->assertDatabaseHas('gas_order_events', [
        'gas_order_id' => $order->id,
        'to_status' => 'ASSIGNED',
        'notes' => "Atribuído a {$assignee->name} (ID {$assignee->id}).",
    ]);
});
