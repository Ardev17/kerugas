<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Rede de segurança (Outubro 2026): os testes usam RefreshDatabase, que
     * APAGA todas as tabelas da base de dados configurada. Se o phpunit.xml
     * não apontar para uma base de testes, isso destrói a base de
     * desenvolvimento (e a cache partilhada fica com listas de províncias
     * de testes que já não existem). Aqui recusamos correr nesse caso, ANTES
     * de qualquer tabela ser tocada, e isolamos sempre a cache.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $driver = (string) $app['config']->get("database.connections.{$connection}.driver");
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        $isSafe = ($driver === 'sqlite' && ($database === ':memory:' || str_contains($database, 'test')))
            || str_ends_with($database, '_test');

        // Opção consciente de quem desenvolve: KERUGAS_ALLOW_TESTS_ON_DEV_DB=true no
        // .env deixa os testes correrem na base de desenvolvimento (que é
        // APAGADA e recriada a cada corrida — era assim que funcionava antes).
        $allowDevDb = filter_var(env('KERUGAS_ALLOW_TESTS_ON_DEV_DB', false), FILTER_VALIDATE_BOOLEAN);

        if (!$isSafe && !$allowDevDb) {
            throw new RuntimeException(
                "Os testes recusaram-se a correr: a base de dados '{$database}' ({$driver}) não é uma base de testes e seria APAGADA. "
                . 'No phpunit.xml defina <env name="DB_CONNECTION" value="sqlite"/> e <env name="DB_DATABASE" value=":memory:"/> '
                . '(ou use uma base MySQL cujo nome termine em _test), ou ponha KERUGAS_ALLOW_TESTS_ON_DEV_DB=true no .env para aceitar apagar esta base.'
            );
        }

        // Cache só em memória: sem isto, listas cacheadas por um teste (ex.: províncias
        // criadas por factories e depois revertidas) sobrevivem e aparecem na app real.
        $app['config']->set('cache.default', 'array');

        // Tempo real desligado nos testes (Outubro 2026): sem isto, com
        // BROADCAST_CONNECTION=reverb no .env e o Reverb fechado, cada evento
        // em tempo real esperava pelo timeout da ligação (~2 s) — os testes
        // ficavam lentos e os limites por minuto (rate limit) expiravam a meio.
        $app['config']->set('broadcasting.default', 'null');

        return $app;
    }

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
