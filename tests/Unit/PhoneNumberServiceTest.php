<?php

use App\Services\PhoneNumberService;

beforeEach(function () {
    $this->service = new PhoneNumberService();
});

it('normaliza um número no formato internacional completo (+244)', function () {
    expect($this->service->normalize('+244923456789'))->toBe('+244923456789');
});

it('normaliza um número no formato 244 sem sinal de mais', function () {
    expect($this->service->normalize('244923456789'))->toBe('+244923456789');
});

it('normaliza um número local com zero inicial', function () {
    expect($this->service->normalize('0923456789'))->toBe('+244923456789');
});

it('normaliza um número local sem zero inicial (formato 9xx)', function () {
    expect($this->service->normalize('923456789'))->toBe('+244923456789');
});

it('normaliza corretamente independentemente de espaços/traços no input', function () {
    expect($this->service->normalize('+244 923 456 789'))->toBe('+244923456789');
    expect($this->service->normalize('923-456-789'))->toBe('+244923456789');
});

it('devolve null para um formato não reconhecido', function () {
    expect($this->service->normalize('123'))->toBeNull();
    expect($this->service->normalize('abc'))->toBeNull();
});

it('isValid reflete o resultado de normalize', function () {
    expect($this->service->isValid('923456789'))->toBeTrue();
    expect($this->service->isValid('123'))->toBeFalse();
});

it('duas variações do mesmo número normalizam para o mesmo valor canónico (evita duplicação de contas)', function () {
    $a = $this->service->normalize('0923456789');
    $b = $this->service->normalize('+244923456789');
    $c = $this->service->normalize('923456789');

    expect($a)->toBe($b)->toBe($c);
});
