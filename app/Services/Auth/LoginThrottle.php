<?php

namespace App\Services\Auth;

use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Proteção do login contra adivinhação de palavras-passe (Outubro 2026).
 *
 * PROBLEMA QUE RESOLVE (os dois mecanismos antigos falhavam em Angola):
 *  1. Bloqueio por IP com poucas tentativas: muitos telemóveis saem pelo MESMO
 *     IP da operadora (CGNAT) — os enganos de umas pessoas bloqueavam o login
 *     de todas as outras dessa rede.
 *  2. Bloqueio da CONTA após 5 erros: quem soubesse o telefone/email de outra
 *     pessoa (ex.: um operador) mantinha-a bloqueada para sempre, bastava
 *     errar 5 vezes a cada 15 minutos.
 *
 * A REGRA AGORA (uma só ideia: contar os ERROS por "conta + rede"):
 *  - 5 erros para a mesma conta a partir da mesma rede (IP) em 15 min →
 *    só ESSE par fica em pausa. O dono da conta, noutra rede (ou noutra
 *    pessoa na mesma operadora com outra conta), entra normalmente.
 *  - Logins CERTOS nunca contam; só os errados.
 *  - Teto por rede, muito alto (100 erros em 15 min, de várias contas): só
 *    apanha ataques em massa, nunca pessoas normais atrás do mesmo IP.
 *  - Muitos erros na mesma conta vindos de redes diferentes (ataque
 *    distribuído) NÃO bloqueiam a conta — avisam o dono uma vez por hora
 *    (notificação de segurança) e ficam no registo. Com palavras-passe de
 *    8+ caracteres e 5 tentativas por rede, adivinhar não é viável.
 */
class LoginThrottle
{
    public const PAIR_MAX = 5;
    public const PAIR_WINDOW = 900;      // 15 min
    public const IP_MAX = 100;
    public const IP_WINDOW = 900;        // 15 min
    public const ACCOUNT_ALERT = 20;     // erros/hora numa conta, de qualquer rede
    public const ACCOUNT_WINDOW = 3600;

    /** Segundos até poder tentar outra vez (0 = pode tentar). */
    public function retryAfter(string $identifier, string $ip): int
    {
        foreach ([[$this->pairKey($identifier, $ip), self::PAIR_MAX], [$this->ipKey($ip), self::IP_MAX]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return 0;
    }

    public function failed(string $identifier, string $ip, ?User $user): void
    {
        RateLimiter::hit($this->pairKey($identifier, $ip), self::PAIR_WINDOW);
        RateLimiter::hit($this->ipKey($ip), self::IP_WINDOW);

        if (!$user) {
            return;
        }

        $accountKey = 'login-fail-account:' . $user->id;
        RateLimiter::hit($accountKey, self::ACCOUNT_WINDOW);

        // Ataque distribuído (muitas redes): avisa o dono, uma vez por hora.
        if (RateLimiter::attempts($accountKey) >= self::ACCOUNT_ALERT
            && Cache::add('login-fail-alerted:' . $user->id, true, self::ACCOUNT_WINDOW)) {
            Log::warning('[KERUGAS] Muitas tentativas de login falhadas numa conta', ['user_id' => $user->id]);
            try {
                AppNotification::create([
                    'user_id' => $user->id,
                    'category' => NotificationCategory::SECURITY,
                    'title' => 'Tentativas de entrada na sua conta',
                    'body' => 'Houve várias tentativas de entrar na sua conta com a palavra-passe errada. Se não foi você, mude a palavra-passe em Perfil.',
                    'data' => ['type' => 'security', 'path' => '/perfil'],
                ]);
            } catch (Throwable) {
                // melhor esforço — nunca impede o login
            }
        }
    }

    /** Login certo: apaga os erros deste par (a rede continua com o seu teto). */
    public function succeeded(string $identifier, string $ip): void
    {
        RateLimiter::clear($this->pairKey($identifier, $ip));
    }

    private function pairKey(string $identifier, string $ip): string
    {
        return 'login-fail:' . sha1(mb_strtolower(trim($identifier)) . '|' . $ip);
    }

    private function ipKey(string $ip): string
    {
        return 'login-fail-ip:' . $ip;
    }
}
