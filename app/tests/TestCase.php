<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // O `.env` de quem desenvolve pode ter SMS_PROVIDER=kamba (e as
        // chaves reais). Sem isto, os testes leriam esse valor: o webhook
        // passava a exigir assinatura (401) e qualquer teste que não
        // substitua o provedor podia falar com a operadora a sério.
        // Os testes que precisam de outro provedor definem-no no próprio
        // teste, com config(), depois deste ponto.
        config(['kerugas.sms.provider' => 'mock']);
    }
}
