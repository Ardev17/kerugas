<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia o acesso de contas suspensas/bloqueadas mesmo com token válido.
 * Registar como alias 'account.active' em bootstrap/app.php.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'A sua conta está suspensa. Contacte o suporte do KERUGAS.',
                'code' => 'ACCOUNT_SUSPENDED',
            ], 403);
        }

        return $next($request);
    }
}
