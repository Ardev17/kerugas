<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso nas rotas: ->middleware('role:DRIVER') (aceita vários,
 * separados por vírgula: 'role:ADMIN,SUPER_ADMIN'). Diferente de
 * `permission:...` — este middleware existe para papéis puramente
 * operacionais como o Condutor, cujas rotas (`/driver/*`) não fazem
 * sentido escoparem por uma permissão granular no mapa de
 * RolePermissions (o mesmo padrão de "papel restrito ao seu próprio
 * âmbito" já usado, sem middleware dedicado, em
 * OperatorStationController — aqui formalizado porque `/driver/*` tem
 * várias rotas a repetir a mesma verificação).
 */
class EnsureRole
{
    // BUG CORRIGIDO (Outubro 2026): o Laravel passa cada papel de
    // 'role:A,B' como um argumento à parte — com `string $roles` só o
    // primeiro contava e 'role:ADMIN,SUPER_ADMIN' recusava o SUPER_ADMIN.
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }

        $allowed = array_merge(...array_map(fn ($r) => explode(',', $r), $roles));

        if (!in_array($user->role->value, $allowed, true)) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para esta ação.'], 403);
        }

        return $next($request);
    }
}
