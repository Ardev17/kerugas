<?php

use App\Services\Push\ApnsPushSender;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Push do iPhone (APNs) sem contactar a Apple: Http::fake. Gera uma chave
 * EC P-256 real por teste, porque o JWT ES256 tem mesmo de ser assinado
 * (e a assinatura é verificada com a chave pública).
 */
const APNS_TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();

    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $privatePem);
    $this->publicPem = openssl_pkey_get_details($key)['key'];

    $this->keyPath = tempnam(sys_get_temp_dir(), 'apns');
    file_put_contents($this->keyPath, $privatePem);

    config(['kerugas.push.apns' => [
        'key_path' => $this->keyPath,
        'key_id' => 'ABC123DEFG',
        'team_id' => 'TEAM123456',
        'bundle_id' => 'ao.kerugas.app',
        'sandbox' => false,
    ]]);
});

afterEach(function () {
    @unlink($this->keyPath);
});

function apnsMessage(string $channel = PushMessage::CHANNEL_ORDERS): PushMessage
{
    return new PushMessage('Novo pedido atribuído', 'Pedido KG-1', '/driver', $channel, ['notification_id' => '7']);
}

it('fica desligado sem chave, sem identificadores ou com a chave ilegível', function () {
    config(['kerugas.push.apns.key_path' => null]);
    expect((new ApnsPushSender())->isEnabled())->toBeFalse();

    config(['kerugas.push.apns.key_path' => '/nao/existe.p8']);
    expect((new ApnsPushSender())->isEnabled())->toBeFalse();

    config(['kerugas.push.apns.key_path' => $this->keyPath, 'kerugas.push.apns.team_id' => '']);
    expect((new ApnsPushSender())->isEnabled())->toBeFalse();
});

it('envia para a Apple com o tópico, a prioridade alta e o ecrã a abrir', function () {
    Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

    $result = (new ApnsPushSender())->send(APNS_TOKEN, apnsMessage());

    expect($result)->toBe(PushResult::SENT);

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://api.push.apple.com/3/device/' . APNS_TOKEN
            && $request->header('apns-topic') === ['ao.kerugas.app']
            && $request->header('apns-push-type') === ['alert']
            && $request->header('apns-priority') === ['10']
            && $body['aps']['alert']['title'] === 'Novo pedido atribuído'
            && $body['aps']['alert']['body'] === 'Pedido KG-1'
            && $body['aps']['sound'] === 'default'
            && $body['aps']['thread-id'] === 'orders'
            && $body['path'] === '/driver'
            && $body['notification_id'] === '7';
    });
});

it('assina o JWT com ES256 e a assinatura verifica com a chave pública', function () {
    Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

    (new ApnsPushSender())->send(APNS_TOKEN, apnsMessage());

    Http::assertSent(function (Request $request) {
        $auth = $request->header('authorization')[0] ?? '';
        expect($auth)->toStartWith('bearer ');
        [$h, $c, $s] = explode('.', substr($auth, 7));
        $b64 = fn (string $v) => base64_decode(strtr($v, '-_', '+/'));

        $header = json_decode($b64($h), true);
        $claims = json_decode($b64($c), true);
        expect($header)->toMatchArray(['alg' => 'ES256', 'kid' => 'ABC123DEFG'])
            ->and($claims['iss'])->toBe('TEAM123456');

        $raw = $b64($s);
        expect(strlen($raw))->toBe(64);
        // R||S → DER, para o OpenSSL poder verificar.
        $int = function (string $v): string {
            $v = ltrim($v, "\x00");
            $v = (ord($v[0]) > 127 ? "\x00" : '') . $v;

            return "\x02" . chr(strlen($v)) . $v;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($body)) . $body;

        expect(openssl_verify("$h.$c", $der, $this->publicPem, OPENSSL_ALGO_SHA256))->toBe(1);

        return true;
    });
});

it('usa o servidor de testes da Apple só quando APNS_SANDBOX está ligado', function () {
    config(['kerugas.push.apns.sandbox' => true]);
    Http::fake(['api.sandbox.push.apple.com/*' => Http::response('', 200)]);

    expect((new ApnsPushSender())->send(APNS_TOKEN, apnsMessage()))->toBe(PushResult::SENT);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.sandbox.push.apple.com/3/device/'));
});

it('só alertas de pedidos levam prioridade 10', function () {
    Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);

    (new ApnsPushSender())->send(APNS_TOKEN, apnsMessage(PushMessage::CHANNEL_PROMOS));

    Http::assertSent(fn (Request $r) => $r->header('apns-priority') === ['5']);
});

it('token desinstalado ou inválido é INVALID_TOKEN; falhas temporárias mantêm o token', function () {
    Http::fake(['api.push.apple.com/*' => Http::sequence()
        ->push(['reason' => 'Unregistered'], 410)
        ->push(['reason' => 'BadDeviceToken'], 400)
        ->push(['reason' => 'TooManyRequests'], 429)
        ->push(['reason' => 'InternalServerError'], 500)]);

    $sender = new ApnsPushSender();

    expect($sender->send(APNS_TOKEN, apnsMessage()))->toBe(PushResult::INVALID_TOKEN)
        ->and($sender->send(APNS_TOKEN, apnsMessage()))->toBe(PushResult::INVALID_TOKEN)
        ->and($sender->send(APNS_TOKEN, apnsMessage()))->toBe(PushResult::FAILED)
        ->and($sender->send(APNS_TOKEN, apnsMessage()))->toBe(PushResult::FAILED);
});

it('um token que não é do APNs (ex.: do Firebase) nem chega à rede', function () {
    Http::fake();

    expect((new ApnsPushSender())->send('fcm:APA91bH-token-do-firebase', apnsMessage()))->toBe(PushResult::INVALID_TOKEN);
    Http::assertNothingSent();
});

it('reutiliza o JWT durante 40 minutos e renova-o se a Apple o recusar', function () {
    Http::fake(['api.push.apple.com/*' => Http::sequence()
        ->push('', 200)
        ->push('', 200)
        ->push(['reason' => 'ExpiredProviderToken'], 403)
        ->push('', 200)]);

    $sender = new ApnsPushSender();
    $sender->send(APNS_TOKEN, apnsMessage());
    $sender->send(APNS_TOKEN, apnsMessage());
    $sender->send(APNS_TOKEN, apnsMessage()); // recusado → limpa a cache
    $sender->send(APNS_TOKEN, apnsMessage());

    $tokens = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('authorization')[0]);
    expect($tokens[0])->toBe($tokens[1])->toBe($tokens[2])
        ->and($tokens[3])->not->toBeNull();
});
