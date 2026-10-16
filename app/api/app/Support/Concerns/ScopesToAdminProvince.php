<?php

namespace App\Support\Concerns;

use App\Enums\UserRole;
use Illuminate\Http\Request;

/**
 * Lógica partilhada de "esta conta está limitada à sua própria
 * província?" (pedido explícito do cliente, Setembro 2026).
 *
 * Só o papel ADMIN é limitado — SUPER_ADMIN nunca, e continua com o
 * CRUD completo em tudo. Aplicado, no total, a: Pedidos de gás, Postos
 * (gestão), Comentários, Denúncias, Analytics, Exportação de dados e
 * gestão de Utilizadores (esta última adicionada numa revisão do
 * pedido original, que a tinha deixado de fora). Explicitamente DE
 * FORA (por pedido do cliente, mantido mesmo após a revisão): Central
 * de Operações — continua igual para ADMIN e SUPER_ADMIN.
 *
 * Esta trait existe para que "quem está limitado" e "por que
 * província" vivam num único sítio — antes de existir, cada
 * controller repetia `$user->role !== UserRole::ADMIN` e
 * `$user->province_id ?? 0` à mão, o que é fácil de escrever
 * ligeiramente diferente em cada sítio (e já aconteceu nesta base de
 * código, com o `SmsProcessingService`/`GasOrderService`, bugs de
 * inconsistência que só apareciam mais tarde).
 */
trait ScopesToAdminProvince
{
    protected function isProvinceScopedAdmin(Request $request): bool
    {
        return $request->user()->role === UserRole::ADMIN;
    }

    /**
     * 0 nunca corresponde a uma província real (os IDs de `provinces`
     * começam em 1) — devolver isto para um ADMIN sem `province_id`
     * atribuído garante "sem âmbito definido = sem acesso a nada",
     * nunca o inverso ("sem filtro = vê tudo").
     */
    protected function adminProvinceId(Request $request): int
    {
        return $request->user()->province_id ?? 0;
    }
}
