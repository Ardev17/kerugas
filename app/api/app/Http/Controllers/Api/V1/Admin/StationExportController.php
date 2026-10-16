<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportStationStatusRequest;
use App\Services\StationStatusExportService;
use App\Support\Concerns\ScopesToAdminProvince;

/**
 * Exportação de dados para Admin/Super Admin (secção nova). Herda os
 * mesmos filtros de período do Operador (dia/semana/mês) e acrescenta
 * filtros combináveis de posto, município e província — nenhum deles
 * é obrigatório; sem filtros, exporta todos os postos no período.
 *
 * ÂMBITO POR PROVÍNCIA (pedido explícito do cliente): um ADMIN só pode
 * exportar (CSV/PDF) dados da SUA própria província — o filtro de
 * `province_id` é sempre FORÇADO ao valor da conta, substituindo
 * silenciosamente qualquer valor enviado no pedido. Este é o mesmo
 * padrão já documentado em `ExportStationStatusRequest` para o
 * Operador (que tem `station_id` sempre forçado ao seu próprio posto)
 * — aqui não há necessidade de validar `station_id`/`municipality_id`
 * contra a província: como os filtros combinam em AND sobre a mesma
 * tabela, um posto ou município de outra província nunca aparece
 * quando `province_id` já está forçado ao correto (o resultado fica
 * simplesmente vazio, nunca "vaza" dados de outra província).
 */
class StationExportController extends Controller
{
    use ScopesToAdminProvince;

    public function __construct(private readonly StationStatusExportService $exportService)
    {
    }

    public function export(ExportStationStatusRequest $request)
    {
        $filters = $request->validated();

        if ($this->isProvinceScopedAdmin($request)) {
            $provinceId = $this->adminProvinceId($request);

            // ARMADILHA EVITADA: `StationStatusExportService::query()`
            // usa `!empty($filters['province_id'])` para decidir se
            // aplica o filtro — e `empty(0)` é `true` em PHP. Se um
            // ADMIN sem província (province_id = 0, o "sem acesso" do
            // resto deste módulo) chegasse aqui, o filtro seria
            // SILENCIOSAMENTE ignorado e a exportação sairia com dados
            // de Angola inteira — o oposto exato do que este pedido
            // pede. Por isso aborta aqui, antes de sequer chamar o
            // serviço, em vez de confiar num `0` a passar por `!empty`.
            abort_if($provinceId === 0, 403, 'A sua conta de administrador não tem uma província atribuída — não pode exportar dados.');

            $filters['province_id'] = $provinceId;
        }

        return $this->exportService->export($filters);
    }
}
