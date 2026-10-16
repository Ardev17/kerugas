<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Media\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Remoção de fotos de perfil impróprias (Outubro 2026). Podem remover:
 * Admin, Moderador, Super Admin Provincial e Super Admin geral (a rota
 * já filtra pelo papel).
 *
 * Regras:
 *  - Admin e Super Admin Provincial só na sua província.
 *  - Só se remove a foto de quem tem MENOS poderes: Admin/Moderador → contas
 *    comuns (clientes, condutores, operadores); Super Admin Provincial →
 *    também Admins e Moderadores; o Super Admin geral → todos.
 *  - A pessoa é avisada e fica registo na auditoria.
 */
class UserAvatarModerationController extends Controller
{
    private const RANK = [
        'SUPER_ADMIN' => 3,
        'PROVINCE_SUPER_ADMIN' => 2,
        'ADMIN' => 1,
        'MODERATOR' => 1,
    ];

    public function destroy(Request $request, User $user, AvatarService $avatars): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (!$actor->isGlobalSuperAdmin()) {
            abort_unless($this->rank($actor) > $this->rank($user), 403, 'Não pode remover a foto desta conta.');
            abort_unless($actor->canAccessProvince($user->province_id), 403, 'Este utilizador pertence a outra província — fora do âmbito da sua conta.');
        }

        $old = $avatars->remove($user);
        abort_if($old === null, 422, 'Este utilizador não tem foto de perfil.');

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'user.avatar_removed_by_moderator',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'old_values' => ['avatar_path' => $old],
            'new_values' => ['province_id' => $user->province_id],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        if ($user->id !== $actor->id) {
            try {
                AppNotification::create([
                    'user_id' => $user->id,
                    'category' => NotificationCategory::SYSTEM,
                    'title' => 'Foto de perfil removida',
                    'body' => 'A sua foto de perfil foi removida pela moderação por não respeitar as regras da Kerugas. Pode escolher outra em "Meu perfil".',
                    'data' => ['type' => 'avatar_removed', 'path' => '/perfil'],
                ]);
            } catch (Throwable) {
                // melhor esforço
            }
        }

        return response()->json(['success' => true, 'data' => ['user_id' => $user->id, 'avatar_url' => null]]);
    }

    private function rank(User $user): int
    {
        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        return self::RANK[$role] ?? 0;
    }
}
