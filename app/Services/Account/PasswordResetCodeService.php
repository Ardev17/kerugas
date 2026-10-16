<?php

namespace App\Services\Account;

use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recuperação da palavra-passe por código (Outubro 2026).
 *
 * - O email de recuperação traz um código de 6 dígitos (para a app) e o link
 *   (para o site). O código vale CODE_MINUTES minutos e aceita no máximo
 *   MAX_ATTEMPTS tentativas erradas — depois é preciso pedir outro.
 * - Só o hash do código fica na base de dados; pedir um código novo anula o
 *   anterior. O limite de 1 pedido por minuto por email é o do Laravel.
 * - Depois de repor a palavra-passe (por código ou por link), TODAS as
 *   sessões terminam e a pessoa recebe um aviso.
 */
class PasswordResetCodeService
{
    public const CODE_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function issue(User $user): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_codes')->updateOrInsert(
            ['email' => mb_strtolower((string) $user->email)],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'created_at' => now(),
            ],
        );

        return $code;
    }

    /**
     * Confere o código. Devolve a conta, ou uma mensagem de erro. Uma
     * tentativa errada conta; ao chegar ao limite o código é anulado.
     *
     * @return User|string
     */
    public function verify(string $email, string $code): User|string
    {
        $email = mb_strtolower(trim($email));
        $invalid = 'Código errado ou expirado. Confirme o código do email ou peça outro.';

        return DB::transaction(function () use ($email, $code, $invalid) {
            $row = DB::table('password_reset_codes')->where('email', $email)->lockForUpdate()->first();

            if (!$row || now()->greaterThan($row->expires_at) || $row->attempts >= self::MAX_ATTEMPTS) {
                return $invalid;
            }

            if (!Hash::check($code, $row->code_hash)) {
                $attempts = $row->attempts + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    DB::table('password_reset_codes')->where('email', $email)->delete();

                    return 'Demasiadas tentativas erradas. Peça um código novo.';
                }
                DB::table('password_reset_codes')->where('email', $email)->update(['attempts' => $attempts]);

                return $invalid;
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if (!$user) {
                return $invalid;
            }

            $this->forget($email);

            return $user;
        });
    }

    /** Anula o código e o link de recuperação desse email. */
    public function forget(string $email): void
    {
        $email = mb_strtolower(trim($email));
        DB::table('password_reset_codes')->where('email', $email)->delete();
        DB::table('password_reset_tokens')->whereRaw('LOWER(email) = ?', [$email])->delete();
    }

    /** Aplica a nova palavra-passe e termina todas as sessões. */
    public function completeReset(User $user, string $password, string $via, ?string $ip): void
    {
        DB::transaction(function () use ($user, $password, $via, $ip) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $ids = $user->tokens()->pluck('id');
            DeviceToken::whereIn('personal_access_token_id', $ids)->delete();
            WebPushSubscription::whereIn('personal_access_token_id', $ids)->delete();
            $user->tokens()->delete();

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'auth.password_reset',
                'entity_type' => User::class,
                'entity_id' => $user->id,
                'new_values' => ['via' => $via],
                'ip_address' => $ip,
            ]);
        });

        $this->forget((string) $user->email);

        try {
            AppNotification::create([
                'user_id' => $user->id,
                'category' => NotificationCategory::SECURITY,
                'title' => 'Palavra-passe reposta',
                'body' => 'A palavra-passe da sua conta foi reposta pelo email de recuperação e as outras sessões foram terminadas. Se não foi você, contacte o suporte.',
                'data' => ['type' => 'security', 'path' => '/perfil'],
            ]);
        } catch (Throwable) {
            // melhor esforço
        }
    }
}
