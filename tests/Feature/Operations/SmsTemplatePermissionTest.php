<?php

use App\Enums\UserRole;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function templatePayload(array $extra = []): array
{
    return array_merge(['name' => 'Teste', 'code' => 'TESTE', 'content' => 'KERUGÁS: olá.', 'type' => 'GENERAL', 'active' => true], $extra);
}

it('Admin vê os templates mas não os cria, edita nem desativa', function () {
    $template = SmsTemplate::create(templatePayload());
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

    $this->getJson('/api/v1/admin/sms-templates')->assertOk();
    $this->postJson('/api/v1/admin/sms-templates', templatePayload(['code' => 'OUTRO']))->assertForbidden();
    $this->putJson("/api/v1/admin/sms-templates/{$template->id}", templatePayload())->assertForbidden();
    $this->deleteJson("/api/v1/admin/sms-templates/{$template->id}")->assertForbidden();

    expect($template->fresh()->active)->toBeTrue();
});

it('Super Admin gere os templates', function () {
    $template = SmsTemplate::create(templatePayload());
    Sanctum::actingAs(User::factory()->create(['role' => UserRole::SUPER_ADMIN]));

    $this->putJson("/api/v1/admin/sms-templates/{$template->id}", templatePayload(['name' => 'Novo nome']))->assertOk();
    $this->deleteJson("/api/v1/admin/sms-templates/{$template->id}")->assertOk();

    expect($template->fresh()->active)->toBeFalse();
});
