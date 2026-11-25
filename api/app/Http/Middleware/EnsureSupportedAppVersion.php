<?php

namespace App\Http\Middleware;

use App\Support\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Atualização obrigatória do app Android e iPhone (Outubro 2026). O app envia
 * `X-App-Platform: android|ios` e `X-App-Version: 1.2.3` em cada pedido; abaixo
 * da versão mínima responde 426 com o link da loja, e o app mostra o ecrã
 * "Atualize a KeruGás". O site (sem estes cabeçalhos) nunca é afetado, e a
 * rota de configuração e o relatório de erros continuam sempre abertos.
 */
class EnsureSupportedAppVersion
{
    private const ALWAYS_OPEN = ['app.config', 'client-errors.store'];

    public function handle(Request $request, Closure $next): Response
    {
        $platform = strtolower((string) $request->header('X-App-Platform'));

        if (!in_array($platform, ['android', 'ios'], true)
            || $request->routeIs(...self::ALWAYS_OPEN)
            || !AppVersion::belowMinimum($request->header('X-App-Version'), $platform)) {
            return $next($request);
        }

        $cfg = $platform === 'ios' ? AppVersion::ios() : AppVersion::android();

        return response()->json([
            'success' => false,
            'code' => 'APP_UPDATE_REQUIRED',
            'message' => 'Esta versão da KeruGás já não é suportada. Atualize a app para continuar.',
            'data' => ['min_version' => $cfg['min_version'], 'store_url' => $cfg['update_url'] ?? $cfg['store_url'], 'update_url' => $cfg['update_url'] ?? $cfg['store_url']],
        ], 426);
    }
}
