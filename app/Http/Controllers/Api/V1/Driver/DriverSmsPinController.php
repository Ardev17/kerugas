<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\SmsPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** PIN pessoal para registar carregamentos por SMS (Outubro 2026). Só o próprio condutor o define. */
class DriverSmsPinController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'pin' => ['required', 'string'],
        ]);

        $user = $request->user();
        abort_unless(Hash::check($data['password'], $user->password), 422, 'A palavra-passe está errada.');
        if ($problem = SmsPin::problem($data['pin'], $data['password'])) {
            abort(422, $problem);
        }

        $user->forceFill(['sms_pin_hash' => Hash::make($data['pin']), 'sms_pin_encrypted' => $data['pin'], 'sms_pin_temporary' => false, 'sms_pin_failures' => 0, 'sms_pin_locked_until' => null])->save();

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'driver.sms_pin_set', 'entity_type' => $user::class, 'entity_id' => $user->id,
            'old_values' => null, 'new_values' => null, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['success' => true, 'data' => ['pin_set' => true]]);
    }
}
