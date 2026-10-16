<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\ClientErrorService;
use App\Support\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Recebe relatórios de erros do site e do app (Outubro 2026). Aberto a
 * visitantes (um erro antes do login também conta), com limite por IP e
 * campos curtos. Responde sempre 204 — nunca dá informação de volta.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request, ClientErrorService $errors): Response
    {
        $data = $request->validate([
            'source' => ['required', 'in:web,android,ios'],
            'name' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'url' => ['nullable', 'string', 'max:500'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        if (isset($data['app_version']) && !AppVersion::valid($data['app_version'])) {
            $data['app_version'] = null;
        }

        // Além dos 20/minuto da rota: no máximo 300 por dia por IP (abuso).
        $key = 'client-errors-day:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 300)) {
            return response()->noContent();
        }
        RateLimiter::hit($key, 86400);

        $errors->record($data, $request->user('sanctum')?->id, $request->userAgent());

        return response()->noContent();
    }
}
