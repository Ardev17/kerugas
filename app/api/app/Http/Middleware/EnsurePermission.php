<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Support\RolePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso nas rotas: ->middleware('permission:stations.update')
 * Registar o alias em bootstrap/app.php (Laravel 11):
 *   $middleware->alias(['permission' => \App\Http\Middleware\EnsurePermission::class]);
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissionValue): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }

        $permission = Permission::tryFrom($permissionValue);

        if ($permission === null || !RolePermissions::has($user->role, $permission)) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para esta ação.'], 403);
        }

        return $next($request);
    }
}
