<?php

use App\Services\Push\FcmPushSender;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * FCM HTTP v1 sem contactar a Google: Http::fake para a troca de token
 * OAuth2 e para o envio. Gera uma chave RSA real por teste, porque o
 * JWT tem mesmo de ser assinado.
 */
beforeEach(function () {
    // Cache em memória: este teste não usa base de dados, e o store por
    // omissão do Laravel 11 (`database`) exigiria a tabela `cache`.
    config(['cache.default' => 'array']);
    Cache::flush();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);

    $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($this->credentialsPath, json_encode([
        'type' => 'service_account',
        'project_id' => 'kerugas-test',
        'client_email' => 'push@kerugas-test.iam.gserviceaccount.com',
        'private_key' => $privatePem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
});

afterEach(function () {
    @unlink($this->credentialsPath);
});

it('envia no formato HTTP v1 com prioridade alta, canal e o ecrã a abrir', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/kerugas-test/messages/1']),
    ]);

    $result = (new FcmPushSender($this->credentialsPath))->send('device-token', new PushMessage(
        title: 'Novo pedido atribuído',
        body: 'Pedido KG-1',
        path: '/driver',
        channel: PushMessage::CHANNEL_ORDERS,
        data: ['notification_id' => '7'],
    ));

    expect($result)->toBe(PushResult::SENT);

    Http::assertSent(function (Request $request) {
        if (!str_contains($request->url(), 'fcm.googleapis.com')) {
            return false;
        }
        $message = $request['message'];

        return $request->url() === 'https://fcm.googleapis.com/v1/projects/kerugas-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer ya29.test')
            && $message['token'] === 'device-token'
            && $message['notification']['title'] === 'Novo pedido atribuído'
            && $message['data'] === ['notification_id' => '7', 'path' => '/driver']
            && $message['android']['priority'] === 'HIGH'
            && $message['android']['notification']['channel_id'] === 'orders';
    });
});

it('troca a conta de serviço por um token OAuth2 com um JWT RS256 válido', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response([]),
    ]);

    (new FcmPushSender($this->credentialsPath))->send('t', new PushMessage('a', 'b'));

    Http::assertSent(function (Request $request) {
        if (!str_contains($request->url(), 'oauth2.googleapis.com')) {
            return false;
        }
        [$header, $claims] = array_map(
            fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true),
            array_slice(explode('.', $request['assertion']), 0, 2)
        );

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $header['alg'] === 'RS256'
            && $claims['iss'] === 'push@kerugas-test.iam.gserviceaccount.com'
            && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging';
    });
});

it('reutiliza o token OAuth2 em cache em vez de pedir um novo a cada push', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response([]),
    ]);

    $sender = new FcmPushSender($this->credentialsPath);
    foreach (range(1, 5) as $i) {
        $sender->send("t{$i}", new PushMessage('a', 'b'));
    }

    Http::assertSentCount(6); // 1 troca de token + 5 envios
});

it('reconhece um telemóvel onde o app já foi desinstalado (UNREGISTERED)', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
            ],
        ], 404),
    ]);

    expect((new FcmPushSender($this->credentialsPath))->send('gone', new PushMessage('a', 'b')))
        ->toBe(PushResult::INVALID_TOKEN);
});

it('um erro do lado da Google não apaga o token (falha temporária)', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
    ]);

    expect((new FcmPushSender($this->credentialsPath))->send('ok-token', new PushMessage('a', 'b')))
        ->toBe(PushResult::FAILED);
});

it('recusa um ficheiro de credenciais que não é de uma conta de serviço', function () {
    file_put_contents($this->credentialsPath, json_encode(['project_id' => 'x']));

    new FcmPushSender($this->credentialsPath);
})->throws(RuntimeException::class, 'client_email');
