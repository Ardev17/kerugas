<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Account\AccountDeletionService;
use App\Services\Media\AvatarService;
use App\Support\PasswordConfirmation;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * "Meu Perfil" (Outubro 2026) — cada utilizador, de qualquer papel, vê e
 * edita os SEUS dados pessoais, muda a palavra-passe e termina sessões
 * noutros dispositivos. Nunca recebe um id: atua sempre sobre a conta da
 * sessão (sem forma de editar outra pessoa).
 */
class ProfileController extends Controller
{
    /** Campos que ficam no registo de auditoria (nunca a palavra-passe). */
    private const AUDITED = ['name', 'email', 'phone', 'province_id', 'municipality_id', 'neighborhood_id', 'latitude', 'longitude'];

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->profileData();

        $old = $user->only(self::AUDITED);
        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        if ($user->isDirty('phone')) {
            $user->phone_verified_at = null;
        }

        $changed = array_keys(array_intersect_key($user->getDirty(), array_flip(self::AUDITED)));

        if ($changed !== []) {
            DB::transaction(function () use ($user, $request, $old, $changed) {
                $user->save();
                $this->audit($request, 'profile.updated', $user, array_intersect_key($old, array_flip($changed)), $user->only($changed));
            });

            if (array_intersect($changed, ['phone', 'email'])) {
                $this->notifySecurity($user, 'Dados de acesso alterados',
                    'O telemóvel ou o email da sua conta foi alterado. Se não foi você, mude já a palavra-passe e contacte o suporte.');
            }
        }

        return response()->json([
            'success' => true,
            'data' => new UserResource($user->fresh(['province', 'municipality', 'neighborhood'])),
            'meta' => ['changed' => $changed],
        ]);
    }

    /**
     * Nova palavra-passe. As OUTRAS sessões (outros telemóveis/navegadores)
     * terminam — quem tinha a palavra-passe antiga deixa de entrar; esta
     * sessão continua aberta.
     */
    public function password(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = DB::transaction(function () use ($user, $request) {
            $user->forceFill([
                'password' => Hash::make($request->input('password')),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $revoked = $this->revokeOtherTokens($user, $request);
            $this->audit($request, 'profile.password_changed', $user, null, ['other_sessions_ended' => $revoked]);

            return $revoked;
        });

        $this->notifySecurity($user, 'Palavra-passe alterada',
            'A palavra-passe da sua conta foi alterada' . ($revoked > 0 ? " e {$revoked} outra(s) sessão(ões) foram terminadas" : '')
            . '. Se não foi você, contacte o suporte.');

        return response()->json(['success' => true, 'data' => ['other_sessions_ended' => $revoked]]);
    }

    /** Foto de perfil (Outubro 2026) — ver AvatarService para as medidas de segurança. */
    public function avatar(UpdateAvatarRequest $request, AvatarService $avatars): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hadAvatar = $user->avatar_path !== null;

        $avatars->store($user, $request->file('avatar'));
        $this->audit($request, 'profile.avatar_updated', $user, ['had_avatar' => $hadAvatar], ['avatar_path' => $user->avatar_path]);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user->fresh(['province', 'municipality', 'neighborhood'])),
        ]);
    }

    public function removeAvatar(Request $request, AvatarService $avatars): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $old = $avatars->remove($user);

        if ($old !== null) {
            $this->audit($request, 'profile.avatar_removed', $user, ['avatar_path' => $old], null);
        }

        return response()->json([
            'success' => true,
            'data' => new UserResource($user->fresh(['province', 'municipality', 'neighborhood'])),
        ]);
    }

    /**
     * Eliminação da conta (Outubro 2026 — exigência da Google Play): o que a
     * impede agora (se algo) e o prazo de arrependimento.
     */
    public function deletionStatus(Request $request, AccountDeletionService $deletion): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'blockers' => $deletion->blockers($request->user()),
            'grace_days' => AccountDeletionService::GRACE_DAYS,
        ]]);
    }

    /**
     * Pede a eliminação: exige a palavra-passe e a palavra ELIMINAR. Todas as
     * sessões terminam; entrar de novo nos próximos dias cancela o pedido.
     */
    public function requestDeletion(Request $request, AccountDeletionService $deletion): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'string', 'in:ELIMINAR'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'current_password.required' => 'Confirme com a sua palavra-passe atual.',
            'confirmation.required' => 'Escreva ELIMINAR para confirmar.',
            'confirmation.in' => 'Escreva ELIMINAR (em maiúsculas) para confirmar.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $blockers = $deletion->blockers($user);
        if ($blockers !== []) {
            return response()->json([
                'success' => false,
                'message' => $blockers[0],
                'code' => 'ACCOUNT_DELETION_BLOCKED',
                'data' => ['blockers' => $blockers],
            ], 422);
        }

        if ($error = PasswordConfirmation::check($user, $data['current_password'])) {
            throw ValidationException::withMessages(['current_password' => $error]);
        }

        $when = $deletion->request($user, $data['reason'] ?? null, $request->ip(), $request->userAgent());

        return response()->json(['success' => true, 'data' => [
            'scheduled_for' => $when->toIso8601String(),
            'grace_days' => AccountDeletionService::GRACE_DAYS,
        ]]);
    }

    /** Sessões abertas da conta (sem nunca expor o token). */
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken && $current->exists ? $current->getKey() : null;

        $sessions = $request->user()->tokens()->latest('last_used_at')->latest('id')->limit(50)->get()
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'current' => $t->id === $currentId,
                'created_at' => $t->created_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(),
            ])->values();

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $revoked = DB::transaction(fn () => $this->revokeOtherTokens($request->user(), $request));
        $this->audit($request, 'profile.sessions_ended', $request->user(), null, ['other_sessions_ended' => $revoked]);

        return response()->json(['success' => true, 'data' => ['other_sessions_ended' => $revoked]]);
    }

    private function revokeOtherTokens(User $user, Request $request): int
    {
        $current = $user->currentAccessToken();
        // `exists`: no Sanctum::actingAs dos testes o token é falso e sem id —
        // sem isto, `id != null` apagaria TODAS as sessões.
        $currentId = $current instanceof PersonalAccessToken && $current->exists ? $current->getKey() : 0;

        $ids = $user->tokens()->where('id', '!=', $currentId)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        // Os avisos push desses dispositivos saem com a sessão.
        DeviceToken::whereIn('personal_access_token_id', $ids)->delete();
        WebPushSubscription::whereIn('personal_access_token_id', $ids)->delete();

        return $user->tokens()->whereIn('id', $ids)->delete();
    }

    private function audit(Request $request, string $action, User $user, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    private function notifySecurity(User $user, string $title, string $body): void
    {
        try {
            AppNotification::create([
                'user_id' => $user->id,
                'category' => NotificationCategory::SECURITY,
                'title' => $title,
                'body' => $body,
                'data' => ['type' => 'security', 'path' => '/perfil'],
            ]);
        } catch (Throwable) {
            // melhor esforço
        }
    }
}
