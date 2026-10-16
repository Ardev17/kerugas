<?php

use App\Services\Sms\SmsSegmentCounter;

beforeEach(function () {
    $this->counter = new SmsSegmentCounter();
});

it('conta uma mensagem GSM-7 curta como 1 segmento', function () {
    $result = $this->counter->count('Ola, tem gas disponivel?');

    expect($result['encoding'])->toBe('GSM-7');
    expect($result['segments'])->toBe(1);
    expect($result['limit_per_segment'])->toBe(160);
});

it('divide uma mensagem GSM-7 longa em múltiplos segmentos de 153', function () {
    $result = $this->counter->count(str_repeat('a', 200));

    expect($result['encoding'])->toBe('GSM-7');
    expect($result['segments'])->toBe(2);
    expect($result['limit_per_segment'])->toBe(153);
});

it('deteta Unicode/UCS-2 quando a mensagem tem acentos fora do alfabeto GSM-7', function () {
    // "í", "ã", "õ" não pertencem ao alfabeto GSM-7 básico/estendido.
    $result = $this->counter->count('Município de Ganda, informação disponível');

    expect($result['encoding'])->toBe('UCS-2');
    expect($result['limit_per_segment'])->toBe(70);
});

it('mensagem vazia tem zero segmentos', function () {
    expect($this->counter->count('')['segments'])->toBe(0);
});

it('caracteres de extensão GSM-7 contam como 2 posições', function () {
    $withEuro = $this->counter->count('Preco: 100€');
    $withoutEuro = $this->counter->count('Preco: 100.');

    // "€" ocupa 2 posições (caracter de extensão) — a versão com "€"
    // deve ser mais longa mesmo tendo o mesmo número de caracteres visíveis.
    expect($withEuro['length'])->toBeGreaterThan($withoutEuro['length']);
});
