<?php

use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Autorização de canais de broadcast (Etapa 16 — Real-time)
|--------------------------------------------------------------------------
| O canal 'stations' (StationStatusUpdated) é PÚBLICO de propósito —
| disponibilidade/fila de postos já são informação pública em qualquer
| outra parte do site. O canal 'admin.orders' é PRIVADO porque carrega
| nome e telefone do cliente (GasOrderCreated) — só ADMIN/SUPER_ADMIN
| podem subscrever.
*/

Broadcast::channel('admin.orders', function (User $user) {
    return $user->isAdmin();
});

/**
 * Chat por município (Setembro 2026) — mesmo âmbito de visibilidade
 * aplicado em ChatController::index, agora para a subscrição em tempo
 * real: um Condutor só pode ouvir o canal do SEU município; um Admin
 * só o de um município DA SUA província (qualquer um dela, não só o
 * seu próprio — pode estar a coordenar vários condutores); o
 * SUPER_ADMIN sempre. `municipality_id` vem da própria rota do canal,
 * por isso a consulta a `Municipality` é necessária aqui para saber a
 * que província pertence antes de comparar com a do Admin.
 */
Broadcast::channel('municipality.{municipalityId}.chat', function (User $user, int $municipalityId) {
    if ($user->role === UserRole::SUPER_ADMIN) {
        return true;
    }

    if ($user->role === UserRole::DRIVER) {
        return (int) $user->municipality_id === $municipalityId;
    }

    if (in_array($user->role, [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN], true)) {
        $municipality = Municipality::find($municipalityId);

        return $municipality !== null && $user->canAccessProvince($municipality->province_id);
    }

    return false;
});

/**
 * Vizinhos KeruGás (Outubro 2026) — a sala de cada município. Mesma regra
 * de quem pode ver (CommunityAccess::canView); o canal só avisa que há
 * mensagens novas, o conteúdo vem sempre da API.
 */
Broadcast::channel('community.municipality.{municipalityId}', function (User $user, int $municipalityId) {
    return \App\Services\Community\CommunityAccess::canView($user, $municipalityId);
});

/**
 * Conversa com a Central (Outubro 2026) — o próprio utilizador e os admins
 * com acesso a ele (Admin/Provincial da sua província, Super Admin).
 */
Broadcast::channel('central.{userId}', function (User $user, int $userId) {
    return \App\Services\RoomChat\RoomAccess::canView($user, \App\Models\RoomMessage::CENTRAL, $userId);
});

/**
 * Conversa privada do investidor (Outubro 2026) — só ele e os Super Admins gerais.
 */
Broadcast::channel('investor.{userId}', function (User $user, int $userId) {
    return \App\Services\RoomChat\RoomAccess::canView($user, \App\Models\RoomMessage::INVESTOR, $userId);
});

/**
 * Chat da Equipa (Outubro 2026) — sala única dos admins de todo o país.
 * Só Super Admin, Super Admin Provincial e Admin (nem o Moderador).
 */
Broadcast::channel('staff.chat', function (User $user) {
    return \App\Services\StaffChat\StaffChatAccess::allowed($user);
});
