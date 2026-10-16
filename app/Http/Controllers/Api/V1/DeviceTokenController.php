<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registo de telemóveis para notificações push (app Android e iPhone,
 * Setembro/Outubro 2026). O app chama `store` sempre que o Firebase (Android)
 * ou a Apple (iPhone) lhe dá, ou renova, um token, e `destroy` quando a pessoa desativa as notificações.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        // `updateOrCreate` pelo token: se este telemóvel já estava ligado
        // a OUTRA conta (telemóvel partilhado, troca de turno no posto),
        // passa para a conta atual — a anterior deixa de receber push aqui.
        $accessToken = $request->user()->currentAccessToken();

        // `upsert` (INSERT ... ON DUPLICATE KEY UPDATE) em vez de
        // updateOrCreate: é atómico. O Firebase pode entregar o mesmo token
        // duas vezes quase em simultâneo (arranque + renovação) e, com
        // "procura e depois insere", o segundo pedido batia no índice
        // único e respondia 500.
        DeviceToken::upsert(
            [[
                'token' => $data['token'],
                'user_id' => $request->user()->id,
                // TransientToken (sessão web/testes) não tem id — fica null.
                'personal_access_token_id' => $accessToken->id ?? null,
                'platform' => $data['platform'] ?? 'android',
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => now(),
            ]],
            ['token'],
            ['user_id', 'personal_access_token_id', 'platform', 'app_version', 'last_seen_at']
        );

        return response()->json(['success' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        // Só apaga se for da própria conta — ninguém consegue desligar as
        // notificações de outra pessoa adivinhando um token.
        DeviceToken::where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }
}
