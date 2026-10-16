<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Envia o link de recuperação. Por segurança, a resposta é sempre
     * de sucesso genérico — nunca revela se o email existe ou não
     * na base de dados (evita enumeração de contas).
     */
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'success' => true,
            'message' => 'Se existir uma conta com este email, enviámos um link de recuperação.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'Não foi possível repor a password. O link pode ter expirado.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Password redefinida com sucesso.']);
    }
}
