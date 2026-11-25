<?php

namespace App\Services\Stations;

use App\Enums\StationDataAccessStatus;
use App\Enums\StationStatus;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A porta única por onde passa tudo o que é público sobre UM posto
 * (auditoria de segurança, Novembro 2026).
 *
 * Antes, só a ficha do posto verificava a presença paga e a consulta paga.
 * Os favoritos, os comentários, as avaliações e as confirmações iam buscar o
 * mesmo posto por outro caminho, e um deles,adicionar aos favoritos —
 * devolvia o estado do gás inteiro a quem não o podia ver. Agora todos
 * perguntam aqui, pela mesma ordem:
 *
 *   1. O posto não está activo, ou não tem presença paga -> 404. Para o
 *      público, não existe (nem se revela que existe).
 *   2. Quem pergunta não pode consultar o município       -> 401/403, com o
 *      mesmo corpo que a ficha do posto, para a aplicação explicar porquê.
 */
class StationPublicGate
{
    public function __construct(
        private readonly StationDataAccessService $access,
        private readonly StationListingService $listing,
    ) {
    }

    /** Quem está do outro lado, nas rotas públicas que aceitam sessão opcional. */
    public function visitor(Request $request): ?User
    {
        return $request->user() ?? $request->user('sanctum');
    }

    /**
     * Null quando pode seguir; a resposta de recusa quando não pode.
     * O 404 sai por excepção, como qualquer posto que não existe.
     */
    public function check(Request $request, Station $station): ?JsonResponse
    {
        abort_unless($station->status === StationStatus::ACTIVE, 404, 'Este posto não está disponível na aplicação.');
        abort_unless($this->listing->isListed($station), 404, 'Este posto não está disponível na aplicação.');

        $decision = $this->access->decide($this->visitor($request), $station->loadMissing('municipality')->municipality);

        if ($decision->status !== StationDataAccessStatus::ALLOWED) {
            return response()->json($decision->payload(), $decision->httpStatus());
        }

        return null;
    }
}
