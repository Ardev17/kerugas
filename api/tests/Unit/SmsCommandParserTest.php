<?php

use App\Enums\QueueLevel;
use App\Services\Sms\SmsCommandParser;

beforeEach(function () {
    $this->parser = new SmsCommandParser();
});

it('interpreta "10" como gás disponível + fila baixa', function () {
    $result = $this->parser->parseStationCode('10');

    expect($result['gas_available'])->toBeTrue();
    expect($result['queue_level'])->toBe(QueueLevel::LOW);
});

it('interpreta "02" como sem gás + fila alta', function () {
    $result = $this->parser->parseStationCode('02');

    expect($result['gas_available'])->toBeFalse();
    expect($result['queue_level'])->toBe(QueueLevel::HIGH);
});

it('interpreta "11" como gás disponível + fila média', function () {
    $result = $this->parser->parseStationCode('11');

    expect($result['gas_available'])->toBeTrue();
    expect($result['queue_level'])->toBe(QueueLevel::MEDIUM);
});

it('devolve null para códigos fora do formato esperado', function () {
    expect($this->parser->parseStationCode('99'))->toBeNull();
    expect($this->parser->parseStationCode('abc'))->toBeNull();
    expect($this->parser->parseStationCode('1'))->toBeNull();
    expect($this->parser->parseStationCode('100'))->toBeNull();
});

it('aceita espaços em volta do código', function () {
    $result = $this->parser->parseStationCode('  10  ');
    expect($result['gas_available'])->toBeTrue();
});

it('reconhece "GAS" (maiúsculas/minúsculas) como consulta de consumidor', function () {
    expect($this->parser->isConsumerQuery('GAS'))->toBeTrue();
    expect($this->parser->isConsumerQuery('gas'))->toBeTrue();
    expect($this->parser->isConsumerQuery(' Gas '))->toBeTrue();
    expect($this->parser->isConsumerQuery('10'))->toBeFalse();
});
