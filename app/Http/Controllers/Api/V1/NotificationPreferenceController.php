<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O que cada pessoa aceita receber (Setembro 2026). Pedidos, segurança
 * da conta e avisos do sistema chegam sempre — só as categorias abaixo
 * podem ser desligadas.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $request->user()->notificationPreferences()]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (array_keys(User::NOTIFICATION_PREFERENCE_DEFAULTS) as $key) {
            $rules[$key] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);

        $user = $request->user();
        $user->update([
            'notification_preferences' => array_map('boolval', array_merge($user->notificationPreferences(), $data)),
        ]);

        return response()->json(['success' => true, 'data' => $user->fresh()->notificationPreferences()]);
    }
}
