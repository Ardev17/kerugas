<?php

use App\Services\Sms\SmsSegmentCounter;
use App\Services\Sms\SmsTextNormalizer;

/**
 * Custo real (Setembro 2026): um só "á" punha o SMS inteiro em UCS-2
 * (70 carateres por SMS em vez de 160) — cobrado a dobrar.
 */
it('um SMS com acentos portugueses passa de 2 para 1 SMS cobrado', function () {
    $counter = new SmsSegmentCounter();
    $original = 'KERUGÁS: Sem postos com gás em Lubango neste momento. Tente novamente mais tarde. Obrigado por usar o KeruGás.';

    expect($counter->count($original)['segments'])->toBe(2);

    $normalized = (new SmsTextNormalizer())->toGsm7($original);

    expect($normalized)->toBe('KERUGAS: Sem postos com gas em Lubango neste momento. Tente novamente mais tarde. Obrigado por usar o KeruGas.')
        ->and($counter->count($normalized))->toMatchArray(['encoding' => 'GSM-7', 'segments' => 1]);
});

it('remove emojis e mantém os acentos que o GSM-7 já tem (é, à)', function () {
    expect((new SmsTextNormalizer())->toGsm7('Olá ☺️ até à sua porta! 🔥'))->toBe('Ola até à sua porta!');
});

it('converte ç, õ, ê, í, ú e pontuação tipográfica', function () {
    expect((new SmsTextNormalizer())->toGsm7('Ação, Botões, Você, Março — “já”…'))
        ->toBe('Acao, Botoes, Voce, Marco - "ja"...');
});
