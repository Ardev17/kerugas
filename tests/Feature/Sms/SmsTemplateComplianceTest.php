<?php

use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Services\FreshnessService;
use App\Services\PhoneNumberService;
use App\Services\Sms\OperationService;
use App\Services\Sms\SmsTemplateRenderer;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regras de conteúdo da KambaSMS (e de SMS em geral, Setembro 2026),
 * confirmadas no guia oficial deles ("Boas Práticas para o Sender
 * ID"): nunca incluir link/URL (bloqueado automaticamente, erro 400)
 * e nunca ultrapassar 160 caracteres. Os templates da Central de
 * Operações tinham AMBOS os problemas antes desta correção — 193 a
 * 234 caracteres e todos com "www.kerugas.com". Este teste evita que
 * a regressão volte a acontecer sem ser notada.
 */
it('nenhum template da Central de Operações contém um link/URL', function () {
    $seeder = new SmsTemplateSeeder();
    $seeder->setContainer(app())->run();

    $templates = \App\Models\SmsTemplate::all();

    expect($templates)->not->toBeEmpty();

    foreach ($templates as $template) {
        expect($template->content)
            ->not->toContain('http://')
            ->not->toContain('https://')
            ->not->toContain('www.')
            ->not->toContain('.com')
            ->not->toContain('.ao');
    }
});

it('nenhum template da Central de Operações ultrapassa 160 caracteres com texto fixo apenas (sem variáveis)', function () {
    $seeder = new SmsTemplateSeeder();
    $seeder->setContainer(app())->run();

    $renderer = new SmsTemplateRenderer();
    $templates = \App\Models\SmsTemplate::all();

    foreach ($templates as $template) {
        // Renderiza com todas as variáveis vazias — o "pior caso" de
        // texto fixo. As variáveis dinâmicas (posto, município, lista
        // de postos) são limitadas separadamente (ver o teste do
        // OperationService::formatStationsList abaixo).
        $context = array_fill_keys(SmsTemplateRenderer::ALLOWED_VARIABLES, '');
        $rendered = $renderer->render($template->content, $context);

        expect(mb_strlen($rendered))->toBeLessThanOrEqual(160);
    }
});

it('a lista de postos disponíveis no SMS nunca inclui mais de 2 postos (protege o limite de 160 caracteres)', function () {
    $province = Province::factory()->create();
    $municipality = Municipality::factory()->create(['province_id' => $province->id]);
    $admin = \App\Models\User::factory()->create(['role' => 'ADMIN']);

    Station::factory()->count(5)->create([
        'province_id' => $province->id,
        'municipality_id' => $municipality->id,
        'status' => \App\Enums\StationStatus::ACTIVE,
        'current_gas_available' => true,
        'current_queue_level' => \App\Enums\QueueLevel::LOW,
    ]);

    $service = new OperationService(new PhoneNumberService(), new FreshnessService());

    $operation = $service->startDraft($admin, '923123456', $province->id, $municipality->id, null);
    $stations = $service->stationsForMunicipality($municipality);

    $context = $service->buildTemplateContext($operation, $stations);

    // No máximo 2 linhas "- Nome (...)" — o resto vira "+N noutros postos."
    $lines = array_values(array_filter(explode("\n", $context['postos']), fn ($l) => str_starts_with(trim($l), '-')));
    expect($lines)->toHaveCount(2)
        ->and($context['postos'])->toContain('+3 noutros postos.');
});
