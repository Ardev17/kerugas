<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\Account\PasswordResetCodeService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    /**
     * Envia o link de recuperação. Por segurança, a resposta é sempre
     * de sucesso genérico — nunca revela se o email existe ou não
     * na base de dados (evita enumeração de contas).
     */
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        // Honeypot (Outubro 2026): um bot recebe exatamente a mesma resposta
        // que uma pessoa, mas nenhum email sai — não aprende que foi travado
        // e não usa o KeruGás para encher a caixa de correio de ninguém.
        if ($request->hitHoneypot()) {
            ForgotPasswordRequest::logHoneypotHit($request);
        } else {
            Password::sendResetLink($request->only('email'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Se existir uma conta com este email, enviámos um link de recuperação.',
        ]);
    }

    public function reset(ResetPasswordRequest $request, PasswordResetCodeService $codes): JsonResponse
    {
        // App (Outubro 2026): código de 6 dígitos do email.
        if ($request->filled('code')) {
            $result = $codes->verify((string) $request->input('email'), (string) $request->input('code'));

            if (is_string($result)) {
                return response()->json(['success' => false, 'message' => $result, 'errors' => ['code' => [$result]]], 422);
            }

            $codes->completeReset($result, (string) $request->input('password'), 'code', $request->ip());
            event(new PasswordReset($result));

            return response()->json(['success' => true, 'message' => 'Palavra-passe reposta. Já pode entrar com a nova palavra-passe.']);
        }

        // Site: link do email (broker do Laravel).
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($codes, $request) {
                $codes->completeReset($user, $password, 'link', $request->ip());
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
