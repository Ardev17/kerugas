<?php

use App\Services\Sms\SmsTemplateRenderer;

beforeEach(function () {
    $this->renderer = new SmsTemplateRenderer();
});

it('substitui variáveis conhecidas pelo valor do contexto', function () {
    $content = 'Olá {{cliente}}, {{quantidade_postos}} postos em {{municipio}}:' . "\n" . '{{postos}}';

    $result = $this->renderer->render($content, [
        'cliente' => 'João',
        'quantidade_postos' => '2',
        'municipio' => 'Lubango',
        'postos' => '- Posto A' . "\n" . '- Posto B',
    ]);

    expect($result)->toBe("Olá João, 2 postos em Lubango:\n- Posto A\n- Posto B");
});

it('substitui por string vazia quando o contexto não fornece a variável', function () {
    expect($this->renderer->render('Olá {{cliente}}', []))->toBe('Olá ');
});

it('não substitui uma variável fora da whitelist (defesa em profundidade)', function () {
    expect($this->renderer->render('{{codigo_secreto}}', ['codigo_secreto' => 'X']))->toBe('{{codigo_secreto}}');
});

it('extractVariables devolve os nomes usados, sem duplicados', function () {
    expect($this->renderer->extractVariables('{{municipio}} tem {{quantidade_postos}} postos em {{municipio}}'))
        ->toBe(['municipio', 'quantidade_postos']);
});

it('validate rejeita conteúdo vazio', function () {
    expect($this->renderer->validate(''))->not->toBeEmpty();
});

it('validate rejeita variáveis desconhecidas', function () {
    $errors = $this->renderer->validate('Olá {{cliente}}, o seu código é {{codigo_secreto}}');

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('codigo_secreto');
});

it('validate aceita conteúdo apenas com variáveis da whitelist', function () {
    expect($this->renderer->validate('Olá {{cliente}}, município: {{municipio}}'))->toBeEmpty();
});
