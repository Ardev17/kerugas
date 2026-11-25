<?php

namespace App\Services\Stations;

use App\Enums\StationDataAccessEffect;
use App\Enums\StationDataAccessStatus;
use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\StationDataGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quem pode consultar o estado dos postos (Novembro 2026).
 *
 * Durante o arranque a consulta é grátis para toda a gente. Quando um mercado
 * passa a pago, fecha-se o município,e abrem-se excepções à mão, pessoa a
 * pessoa, para quem pagou ou para parceiros.
 *
 * A ordem das regras é esta, e a primeira que decidir manda:
 *
 *   1. Excepção DENY válida  -> fechado, seja quem for. É um acto deliberado
 *                               de um administrador e vale sobre tudo o resto.
 *   2. Equipa da plataforma  -> aberto. O bloqueio é para monetizar o público;
 *                               admins, moderadores, condutores da KeruGás e
 *                               investidores precisam dos dados para trabalhar.
 *                               Só com a conta activa: um token antigo de uma
 *                               conta suspensa não leva o acesso livre.
 *   3. Excepção ALLOW válida -> aberto, mesmo com o município fechado (também
 *                               só com a conta activa).
 *   4. Município fechado     -> sem sessão, pede login (não sabemos a quem
 *                               aplicar a excepção); com sessão, fechado.
 *   5. Caso contrário        -> aberto.
 *
 * O município que conta é o DO POSTO consultado, não o do perfil de quem
 * consulta: senão bastava mudar o município no perfil para voltar a ver tudo.
 *
 * O pessoal de posto (gerente, operador, condutor do posto) conta como
 * cliente,auditoria de segurança, Novembro 2026. O gerente promove a
 * operador quem quiser e cria condutores sem limite; se esses papéis abrissem
 * a consulta, cada posto podia distribuir acesso grátis aos amigos (ou
 * vendê-lo). O estado do próprio posto, essa gente vê-o no seu painel.
 */
class StationDataAccessService
{
    /**
     * Papéis que vêem tudo para poderem trabalhar. Fora daqui,clientes e
     * pessoal de posto,aplica-se a regra do município.
     */
    public const PLATFORM_ROLES = [
        UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::ADMIN,
        UserRole::MODERATOR, UserRole::DRIVER, UserRole::INVESTOR,
    ];

    /** Papéis a quem um administrador pode abrir ou fechar a consulta. */
    public const GRANTABLE_ROLES = [
        UserRole::USER, UserRole::STATION_MANAGER, UserRole::STATION_OPERATOR, UserRole::STATION_DRIVER,
    ];

    public function decide(?User $user, ?Municipality $municipality): StationDataAccessDecision
    {
        $grant = $user !== null ? $this->activeGrant($user) : null;

        if ($grant?->effect === StationDataAccessEffect::DENY) {
            return new StationDataAccessDecision(
                StationDataAccessStatus::BLOCKED,
                'user_blocked',
                'A sua conta está sem acesso à consulta dos postos. Fale connosco para reactivar.',
                $municipality,
            );
        }

        if ($this->isPlatformStaff($user)) {
            return StationDataAccessDecision::allowed($municipality);
        }

        if ($grant?->effect === StationDataAccessEffect::ALLOW && $this->isActive($user)) {
            return StationDataAccessDecision::allowed($municipality);
        }

        if ($municipality === null || !$municipality->stationDataBlocked()) {
            return StationDataAccessDecision::allowed($municipality);
        }

        if ($user === null) {
            return new StationDataAccessDecision(
                StationDataAccessStatus::NEEDS_LOGIN,
                'login_required',
                'Inicie sessão para consultar os postos deste município.',
                $municipality,
            );
        }

        return new StationDataAccessDecision(
            StationDataAccessStatus::BLOCKED,
            'municipality_blocked',
            $municipality->station_data_blocked_reason
                ?: 'A consulta dos postos deste município está reservada a quem tem subscrição.',
            $municipality,
        );
    }

    public function allows(?User $user, ?Municipality $municipality): bool
    {
        return $this->decide($user, $municipality)->status === StationDataAccessStatus::ALLOWED;
    }

    /**
     * Ids dos municípios que este utilizador NÃO pode consultar. Serve para
     * limpar as listas (mapa e postos próximos) numa query só, em vez de
     * decidir posto a posto.
     *
     * @return array<int, int>
     */
    public function blockedMunicipalityIds(?User $user): array
    {
        $grant = $user !== null ? $this->activeGrant($user) : null;

        // Fechado a tudo: nem vale a pena olhar para os municípios.
        if ($grant?->effect === StationDataAccessEffect::DENY) {
            return Municipality::query()->pluck('id')->all();
        }

        if ($this->isPlatformStaff($user)) {
            return [];
        }

        if ($grant?->effect === StationDataAccessEffect::ALLOW && $this->isActive($user)) {
            return [];
        }

        return Municipality::query()
            ->whereNotNull('station_data_blocked_from')
            ->where('station_data_blocked_from', '<=', now())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Restringe uma consulta de utilizadores a quem pode ver os postos deste
     * município,para avisos e campanhas que levam o estado do gás no texto
     * ("o posto X voltou a ter gás"). Sem isto, um alerta grátis entregava
     * por SMS o que a aplicação cobra para mostrar.
     *
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public function restrictToViewers(Builder $users, Municipality $municipality): Builder
    {
        $grants = (new StationDataGrant())->getTable();
        $userTable = $users->getModel()->getTable();
        $valida = fn ($q, StationDataAccessEffect $efeito) => $q->selectRaw('1')->from($grants)
            ->whereColumn("{$grants}.user_id", "{$userTable}.id")
            ->where("{$grants}.effect", $efeito->value)
            ->where(fn ($w) => $w->whereNull("{$grants}.expires_at")->orWhere("{$grants}.expires_at", '>', now()));

        $users->whereNotExists(fn ($q) => $valida($q, StationDataAccessEffect::DENY));

        if (!$municipality->stationDataBlocked()) {
            return $users;
        }

        return $users->where(fn ($q) => $q
            ->where(fn ($p) => $p->where("{$userTable}.status", 'ACTIVE')
                ->whereIn("{$userTable}.role", array_map(fn (UserRole $r) => $r->value, self::PLATFORM_ROLES)))
            ->orWhere(fn ($p) => $p->where("{$userTable}.status", 'ACTIVE')
                ->whereExists(fn ($e) => $valida($e, StationDataAccessEffect::ALLOW))));
    }

    /**
     * Os municípios fechados, para a aplicação poder dizer porque é que
     * aquela zona não aparece no mapa em vez de a esconder em silêncio.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    public function describeBlocked(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Municipality::query()
            ->whereIn('id', $ids)
            ->whereNotNull('station_data_blocked_from')
            ->where('station_data_blocked_from', '<=', now())
            ->orderBy('name')
            ->get(['id', 'name', 'station_data_blocked_reason'])
            ->map(fn (Municipality $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'reason' => $m->station_data_blocked_reason
                    ?: 'Consulta reservada a quem tem subscrição.',
            ]);
    }

    /**
     * Porque é que esta LISTA veio curta por causa da pessoa, e não da zona.
     *
     * As listas não devolvem erro: filtram. Mas quando é a própria conta que
     * está bloqueada, ou quando é preciso saber quem está do outro lado, o
     * `blocked_municipalities` vem vazio,os municípios estão abertos, o
     * problema é outro,e a aplicação ficava sem nada para dizer, a mostrar
     * "não encontramos postos nesta área". Isto dá-lhe as palavras.
     *
     * @param  bool  $escondeuZona  Se a filtragem por município tirou algo
     *                              que esta procura teria apanhado.
     * @return array{reason: string, message: string}|null
     */
    public function listBlock(?User $user, bool $escondeuZona): ?array
    {
        if ($user !== null) {
            if ($this->activeGrant($user)?->effect === StationDataAccessEffect::DENY) {
                return [
                    'reason' => 'user_blocked',
                    'message' => 'A sua conta está sem acesso à consulta dos postos. Fale connosco para reactivar.',
                ];
            }

            return null;
        }

        return $escondeuZona ? [
            'reason' => 'login_required',
            'message' => 'Inicie sessão para consultar os postos desta zona.',
        ] : null;
    }

    public function activeGrant(User $user): ?StationDataGrant
    {
        // Sem memória entre chamadas, de propósito: o serviço vive dentro de
        // controllers que o router reaproveita, e uma excepção retirada por um
        // administrador tem de deixar de valer no pedido seguinte.
        $grant = StationDataGrant::query()->where('user_id', $user->id)->first();

        return $grant !== null && $grant->isActive() ? $grant : null;
    }

    public function isPlatformStaff(?User $user): bool
    {
        return $user !== null && $this->isActive($user) && in_array($user->role, self::PLATFORM_ROLES, true);
    }

    private function isActive(?User $user): bool
    {
        return $user !== null && $user->status === 'ACTIVE';
    }
}
