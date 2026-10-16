<?php

namespace App\Support\Concerns;

use Illuminate\Http\Request;

/**
 * Lógica partilhada de "esta conta está limitada à sua própria
 * província?" (pedido explícito do cliente, Setembro 2026).
 *
 * Limitados: ADMIN e PROVINCE_SUPER_ADMIN (Outubro 2026). SUPER_ADMIN nunca, e continua com o
 * CRUD completo em tudo. Aplicado, no total, a: Pedidos de gás, Postos
 * (gestão), Comentários, Denúncias, Analytics, Exportação de dados e
 * gestão de Utilizadores (esta última adicionada numa revisão do
 * pedido original, que a tinha deixado de fora). Central de Operações (Outubro 2026): o
 * Admin atende só na sua província — o ecrã preenche-a e só lista os
 * municípios dela, e `StoreOperationRequest` força o mesmo no servidor.
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
    /** ADMIN e Super Admin Provincial (Outubro 2026) ficam limitados à sua província. */
    protected function isProvinceScopedAdmin(Request $request): bool
    {
        return $request->user()->scopedProvinceId() !== null;
    }

    /** Poderes de Super Admin (geral ou provincial). */
    protected function hasSuperPowers(Request $request): bool
    {
        return $request->user()->hasSuperPowers();
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
