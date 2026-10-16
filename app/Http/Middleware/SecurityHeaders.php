<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança da API (Outubro 2026).
 *
 * Em produção o nginx já os envia — mas a API também corre SEM o nginx à
 * frente (Laragon no PC, o túnel ngrok para o app, outro alojamento no
 * futuro). Este middleware garante o mínimo em qualquer caso:
 *
 * - nosniff: o browser nunca "adivinha" que um JSON é HTML/JS;
 * - CSP `default-src 'none'`: uma resposta da API aberta diretamente no
 *   browser não executa nada, nem carrega nada;
 * - X-Frame-Options / frame-ancestors: a API não pode ser embutida
 *   noutro site;
 * - sem `X-Powered-By`: não anuncia a versão do PHP;
 * - HSTS só em produção e por HTTPS (nunca no localhost nem no ngrok).
 *
 * Os valores coincidem com os do nginx de propósito: quando os dois
 * enviam o mesmo cabeçalho, ficam iguais e nunca se contradizem. Uma
 * resposta que já traga um cabeçalho próprio (ex.: a CSP com `sandbox`
 * das fotos de perfil) mantém o seu.
 *
 * NÃO se envia Cross-Origin-Resource-Policy: o app Android (origem
 * https://localhost) carrega as fotos de perfil de outro domínio, e
 * `same-origin` bloqueá-las-ia.
 */
class SecurityHeaders
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'",
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Só a API (e o health check). Páginas web, se alguma vez
        // existirem, terão a sua própria política.
        if (! $request->is('api', 'api/*', 'up')) {
            return $response;
        }

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if (app()->isProduction() && $request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->remove('X-Powered-By');
        if (! headers_sent() && function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
