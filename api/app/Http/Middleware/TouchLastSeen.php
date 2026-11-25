<?php

namespace App\Http\Middleware;

use App\Services\Community\CommunityPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Vizinhos KeruGás: regista que a pessoa esteve ativa (no máximo 1×/minuto). */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($request->user() && $response->isSuccessful()) {
                CommunityPresence::touch($request->user());
            }
        } catch (Throwable) {
            // nunca falha o pedido por causa disto
        }

        return $response;
    }
}
