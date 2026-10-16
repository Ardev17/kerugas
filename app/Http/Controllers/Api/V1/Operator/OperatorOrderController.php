<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Http\Controllers\Controller;
use App\Http\Resources\GasOrderResource;
use App\Models\GasOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pedidos de gás visíveis ao Operador — nunca todos os pedidos do
 * sistema (a rota /admin/orders vê isso), só os do MESMO MUNICÍPIO do
 * posto que ele opera. Um operador em Benguela não precisa de ver um
 * pedido em Luanda; o âmbito é sempre imposto aqui, nunca escolhido
 * pelo pedido (mesmo padrão já usado em
 * OperatorStationController::export — o operador nunca alarga o seu
 * próprio âmbito, mesmo que tente enviar outros parâmetros).
 */
class OperatorOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if($user->role->value !== 'STATION_OPERATOR', 403, 'Apenas operadores de posto têm acesso a este painel.');

        $station = $user->operatedStation;

        abort_if(!$station, 404, 'Não existe posto atribuído à sua conta. Contacte o suporte do KERUGAS.');

        $query = GasOrder::query()
            ->with(['province', 'municipality', 'assignedUser'])
            ->where('municipality_id', $station->municipality_id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $orders = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => GasOrderResource::collection($orders),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()],
        ]);
    }
}
