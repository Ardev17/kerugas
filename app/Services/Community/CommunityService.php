<?php

namespace App\Services\Community;

use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Events\CommunityMessagePosted;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CommunityBlock;
use App\Models\CommunityMention;
use App\Models\CommunityMessage;
use App\Models\CommunityMute;
use App\Models\CommunityReaction;
use App\Models\CommunityReport;
use App\Models\Municipality;
use App\Models\Neighborhood;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Vizinhos KeruGás (Outubro 2026) — todas as ações sobre mensagens.
 * Os controladores só validam o formato do pedido; as regras vivem aqui.
 */
class CommunityService
{
    public const TOPICS = [
        'gas' => 'Gás e postos',
        'entregas' => 'Entregas',
        'avisos' => 'Avisos do bairro',
        'geral' => 'Conversa geral',
    ];

    public const MAX_BODY = 1000;

    public const MAX_MENTIONS = 5;

    public const EDIT_MINUTES = 15;

    public function __construct(
        private readonly CommunityContentFilter $filter,
        private readonly CommunityImageService $images,
    ) {
    }

    // ── Publicar ────────────────────────────────────────────────────────

    public function post(User $author, int $municipalityId, array $data, ?UploadedFile $image = null): CommunityMessage
    {
        $this->assertCanWrite($author, $municipalityId);

        $body = $this->cleanBody((string) ($data['body'] ?? ''));
        if ($body === '' && $image === null) {
            throw ValidationException::withMessages(['body' => 'Escreva uma mensagem ou junte uma foto.']);
        }

        $this->throttle($author);

        $neighborhoodId = $data['neighborhood_id'] ?? null;
        if ($neighborhoodId !== null
            && !Neighborhood::query()->whereKey($neighborhoodId)->where('municipality_id', $municipalityId)->exists()) {
            throw ValidationException::withMessages(['neighborhood_id' => 'Esse bairro não é deste município.']);
        }

        $replyTo = null;
        if (!empty($data['reply_to_id'])) {
            $replyTo = CommunityMessage::query()->whereKey($data['reply_to_id'])->where('municipality_id', $municipalityId)->first();
            if (!$replyTo) {
                throw ValidationException::withMessages(['reply_to_id' => 'A mensagem a que quer responder já não existe.']);
            }
        }

        [$body, $mentionIds] = $this->resolveMentions($body, $author, $municipalityId);

        if ($body !== '' && ($error = $this->filter->check($author, $body))) {
            throw ValidationException::withMessages(['body' => $error]);
        }

        $this->assertNotDuplicate($author, $municipalityId, $body);

        $path = $image ? $this->images->store($image) : null;

        try {
            $message = DB::transaction(function () use ($author, $municipalityId, $data, $body, $neighborhoodId, $replyTo, $path, $mentionIds) {
                $message = CommunityMessage::create([
                    'municipality_id' => $municipalityId,
                    'neighborhood_id' => $neighborhoodId,
                    'user_id' => $author->id,
                    'body' => $body,
                    'topic' => $data['topic'] ?? null,
                    'reply_to_id' => $replyTo?->id,
                    'image_path' => $path,
                    'official' => CommunityAccess::writesAsTeam($author, $municipalityId),
                ]);

                foreach ($mentionIds as $id) {
                    CommunityMention::create(['message_id' => $message->id, 'user_id' => $id]);
                }

                return $message;
            });
        } catch (Throwable $e) {
            $this->images->deleteFile($path);
            throw $e;
        }

        $this->notifyAbout($message, $author, $mentionIds, $replyTo);
        CommunityMessagePosted::dispatchSafely($message);

        return $message;
    }

    public function edit(User $actor, CommunityMessage $message, string $body): CommunityMessage
    {
        if ($message->user_id !== $actor->id || !$message->isVisible()) {
            throw new HttpException(403, 'Só pode editar as suas mensagens.');
        }
        if ($message->location_kind !== null) {
            throw new HttpException(422, 'Uma localização partilhada não se edita — pode parar a partilha e partilhar de novo.');
        }
        if ($message->created_at->lt(now()->subMinutes(self::EDIT_MINUTES))) {
            throw new HttpException(403, 'Já passaram mais de ' . self::EDIT_MINUTES . ' minutos — já não pode editar.');
        }
        $this->assertCanWrite($actor, (int) $message->municipality_id);

        $body = $this->cleanBody($body);
        if ($body === '' && !$message->image_path) {
            throw ValidationException::withMessages(['body' => 'A mensagem não pode ficar vazia.']);
        }

        [$body, $mentionIds] = $this->resolveMentions($body, $actor, (int) $message->municipality_id);
        if ($body !== '' && ($error = $this->filter->check($actor, $body))) {
            throw ValidationException::withMessages(['body' => $error]);
        }

        $previous = $message->mentions()->pluck('user_id')->all();

        DB::transaction(function () use ($message, $body, $mentionIds) {
            $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
            $message->mentions()->whereNotIn('user_id', $mentionIds)->delete();
            foreach ($mentionIds as $id) {
                CommunityMention::query()->firstOrCreate(['message_id' => $message->id, 'user_id' => $id]);
            }
        });

        $this->notifyAbout($message, $actor, array_values(array_diff($mentionIds, $previous)), null);

        return $message;
    }

    /** O autor apaga: sai do chat, a moderação continua a ver o registo. */
    public function authorDelete(User $actor, CommunityMessage $message): void
    {
        if ($message->user_id !== $actor->id) {
            throw new HttpException(403, 'Só pode apagar as suas mensagens.');
        }

        $message->forceFill(['author_deleted_at' => $message->author_deleted_at ?? now(), 'pinned_at' => null, 'pinned_by' => null]
            + self::LOCATION_WIPE + ($message->location_kind ? ['location_ended_at' => $message->location_ended_at ?? now()] : []))->save();
    }

    /** Colunas apagadas quando uma partilha de localização acaba (ver CommunityLocationService). */
    public const LOCATION_WIPE = ['location_lat' => null, 'location_lng' => null, 'location_accuracy' => null, 'location_station_id' => null];

    // ── Reações, denúncias, bloqueios ───────────────────────────────────

    public function react(User $actor, CommunityMessage $message, ?string $emoji): void
    {
        $this->assertCanView($actor, (int) $message->municipality_id);
        if (!$message->isVisible()) {
            throw new HttpException(404);
        }

        if ($emoji === null) {
            CommunityReaction::query()->where(['message_id' => $message->id, 'user_id' => $actor->id])->delete();

            return;
        }

        CommunityReaction::query()->updateOrCreate(['message_id' => $message->id, 'user_id' => $actor->id], ['emoji' => $emoji]);
    }

    public function report(User $actor, CommunityMessage $message, string $reason, ?string $details): void
    {
        $this->assertCanView($actor, (int) $message->municipality_id);
        if (!$message->isVisible() || $message->user_id === $actor->id) {
            throw new HttpException(422, 'Não pode denunciar esta mensagem.');
        }

        CommunityReport::query()->firstOrCreate(
            ['message_id' => $message->id, 'user_id' => $actor->id],
            ['reason' => $reason, 'details' => $details ? Str::limit(trim($details), 300, '') : null],
        );

        // 3 pessoas diferentes: fica escondida até a moderação rever.
        $open = $message->reports()->whereNull('resolved_at')->distinct()->count('user_id');
        if ($open >= CommunityReport::AUTO_HIDE_AT && $message->hidden_at === null) {
            $message->forceFill(['hidden_at' => now()])->save();
        }
    }

    public function block(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new HttpException(422, 'Não pode esconder-se a si próprio.');
        }
        CommunityBlock::query()->firstOrCreate(['user_id' => $actor->id, 'blocked_user_id' => $target->id]);
    }

    public function unblock(User $actor, int $targetId): void
    {
        CommunityBlock::query()->where(['user_id' => $actor->id, 'blocked_user_id' => $targetId])->delete();
    }

    // ── Moderação ───────────────────────────────────────────────────────

    public function pin(User $actor, CommunityMessage $message, bool $pinned): void
    {
        $this->assertModerates($actor, $message);
        if ($pinned && !$message->isVisible()) {
            throw new HttpException(422, 'Só se fixam mensagens visíveis.');
        }
        if ($pinned && CommunityMessage::query()->where('municipality_id', $message->municipality_id)->whereNotNull('pinned_at')->count() >= 3) {
            throw new HttpException(422, 'Já existem 3 avisos fixados neste município. Retire um primeiro.');
        }

        $message->forceFill(['pinned_at' => $pinned ? now() : null, 'pinned_by' => $pinned ? $actor->id : null])->save();
        $this->audit($actor, $pinned ? 'community.pinned' : 'community.unpinned', $message);
    }

    public function remove(User $actor, CommunityMessage $message, string $reason): void
    {
        $this->assertModerates($actor, $message);

        $message->forceFill([
            'removed_at' => now(), 'removed_by' => $actor->id,
            'removal_reason' => Str::limit(trim($reason), 200, ''), 'pinned_at' => null, 'pinned_by' => null,
        ] + self::LOCATION_WIPE + ($message->location_kind ? ['location_ended_at' => $message->location_ended_at ?? now()] : []))->save();
        $message->reports()->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => $actor->id]);
        $this->audit($actor, 'community.removed', $message, ['reason' => $message->removal_reason]);

        if ($message->user_id !== $actor->id) {
            $this->notify(
                $message->user_id, 'Uma mensagem sua foi removida',
                'A moderação dos Vizinhos KeruGás removeu uma mensagem sua. Motivo: ' . $message->removal_reason,
                ['type' => 'community_removed', 'path' => '/comunidade'],
            );
        }
    }

    public function restore(User $actor, CommunityMessage $message): void
    {
        $this->assertModerates($actor, $message);
        $message->forceFill(['removed_at' => null, 'removed_by' => null, 'removal_reason' => null, 'hidden_at' => null])->save();
        $message->reports()->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => $actor->id]);
        $this->audit($actor, 'community.restored', $message);
    }

    /** "As denúncias não procedem": a mensagem volta ao chat. */
    public function dismissReports(User $actor, CommunityMessage $message): void
    {
        $this->assertModerates($actor, $message);
        $message->reports()->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => $actor->id]);
        if ($message->hidden_at !== null) {
            $message->forceFill(['hidden_at' => null])->save();
        }
        $this->audit($actor, 'community.reports_dismissed', $message);
    }

    /** Eliminação total (Admin para cima): apaga o registo e a foto. */
    public function hardDelete(User $actor, CommunityMessage $message): void
    {
        if (!CommunityAccess::canAdminister($actor)) {
            throw new HttpException(403, 'Só um administrador pode apagar de vez.');
        }
        $this->assertModerates($actor, $message);

        $this->audit($actor, 'community.hard_deleted', $message, ['author_id' => $message->user_id]);
        $this->images->deleteFile($message->image_path);
        $message->delete();
    }

    public function mute(User $actor, User $target, ?int $hours, ?string $reason): CommunityMute
    {
        $max = CommunityAccess::maxMuteHours($actor);
        if ($max === 0) {
            throw new HttpException(403, 'Não tem permissão para silenciar.');
        }
        if ($target->id === $actor->id) {
            throw new HttpException(422, 'Não pode silenciar-se a si próprio.');
        }
        if (CommunityAccess::isStaff($target) && $actor->role !== UserRole::SUPER_ADMIN) {
            throw new HttpException(403, 'Só o Super Admin silencia outra pessoa da equipa.');
        }
        if (!$target->municipality_id || !CommunityAccess::canModerate($actor, (int) $target->municipality_id)) {
            throw new HttpException(403, 'Esta pessoa está fora da sua área.');
        }
        if ($hours === null && $max !== null) {
            throw new HttpException(403, 'O seu perfil só pode silenciar até ' . ($max >= 48 ? intdiv($max, 24) . ' dias' : $max . ' horas') . '.');
        }
        if ($hours !== null && $max !== null && $hours > $max) {
            throw new HttpException(403, 'O seu perfil só pode silenciar até ' . ($max >= 48 ? intdiv($max, 24) . ' dias' : $max . ' horas') . '.');
        }

        $mute = CommunityMute::create([
            'user_id' => $target->id, 'muted_by' => $actor->id,
            'reason' => $reason ? Str::limit(trim($reason), 200, '') : null,
            'expires_at' => $hours === null ? null : now()->addHours($hours),
        ]);
        AuditLog::create([
            'user_id' => $actor->id, 'action' => 'community.muted', 'entity_type' => User::class, 'entity_id' => $target->id,
            'new_values' => ['hours' => $hours, 'reason' => $mute->reason],
        ]);
        $this->notify($target->id, 'Foi silenciado nos Vizinhos KeruGás',
            ($hours === null ? 'De forma permanente' : 'Durante ' . ($hours >= 48 ? intdiv($hours, 24) . ' dias' : $hours . ' horas'))
            . ($mute->reason ? '. Motivo: ' . $mute->reason : '.'),
            ['type' => 'community_muted', 'path' => '/comunidade']);

        return $mute;
    }

    public function unmute(User $actor, User $target): void
    {
        if (CommunityAccess::maxMuteHours($actor) === 0
            || !$target->municipality_id || !CommunityAccess::canModerate($actor, (int) $target->municipality_id)) {
            throw new HttpException(403, 'Esta pessoa está fora da sua área.');
        }
        CommunityMute::query()->active()->where('user_id', $target->id)->update(['lifted_at' => now()]);
    }

    public function activeMute(User $user): ?CommunityMute
    {
        return CommunityMute::query()->active()->where('user_id', $user->id)->latest('id')->first();
    }

    // ── Verificações ────────────────────────────────────────────────────

    public function assertCanView(User $user, int $municipalityId): void
    {
        if (!CommunityAccess::canView($user, $municipalityId)) {
            throw new HttpException(403, 'Só pode ver as conversas do seu município.');
        }
    }

    public function assertCanWrite(User $user, int $municipalityId): void
    {
        if (!CommunityAccess::canWrite($user, $municipalityId)) {
            throw new HttpException(403, 'Só pode escrever no seu município.');
        }
        if (!Municipality::query()->whereKey($municipalityId)->value('community_enabled')) {
            throw new HttpException(403, 'Os Vizinhos KeruGás estão desligados neste município.');
        }
        if (!CommunityAccess::writesAsTeam($user, $municipalityId) && $user->community_accepted_at === null) {
            throw new HttpException(403, 'Aceite primeiro as regras da comunidade.');
        }
        if ($mute = $this->activeMute($user)) {
            throw new HttpException(403, $mute->expires_at
                ? 'Está silenciado até ' . $mute->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') . '.'
                : 'Está silenciado e não pode escrever.');
        }
    }

    private function assertModerates(User $actor, CommunityMessage $message): void
    {
        if (!CommunityAccess::canModerate($actor, (int) $message->municipality_id)) {
            throw new HttpException(403, 'Esta mensagem está fora da sua área.');
        }
    }

    private function throttle(User $author): void
    {
        if (!RateLimiter::attempt('community:gap:' . $author->id, 1, fn () => true, 5)) {
            throw ValidationException::withMessages(['body' => 'Calma, vizinho! Espere uns segundos antes de enviar outra mensagem.']);
        }
        $hourKey = 'community:hour:' . $author->id;
        if (RateLimiter::tooManyAttempts($hourKey, 30)) {
            throw ValidationException::withMessages(['body' => 'Já enviou muitas mensagens esta hora. Volte daqui a pouco.']);
        }
        RateLimiter::hit($hourKey, 3600);
    }

    private function assertNotDuplicate(User $author, int $municipalityId, string $body): void
    {
        if ($body === '') {
            return;
        }
        $exists = CommunityMessage::query()
            ->where('user_id', $author->id)->where('municipality_id', $municipalityId)
            ->where('body', $body)->where('created_at', '>=', now()->subMinutes(10))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['body' => 'Já enviou esta mesma mensagem há pouco.']);
        }
    }

    private function cleanBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", trim($body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        // sem caracteres de controlo (mantém quebras de linha)
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? $body;

        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => 'A mensagem pode ter no máximo ' . self::MAX_BODY . ' caracteres.']);
        }

        return $body;
    }

    // ── Menções ─────────────────────────────────────────────────────────

    /**
     * Devolve o texto com as menções no formato `@[id]` (nunca o número) e
     * os ids a notificar. `@923456789` resolve para o nome; só vale para quem
     * vive no mesmo município (ou é da equipa).
     *
     * @return array{0: string, 1: list<int>}
     */
    public function resolveMentions(string $body, User $author, int $municipalityId): array
    {
        // 1) @923456789 / @+244923456789 → @[id]. O CONDUTOR nunca resolve números: seria
        // uma forma de descobrir quem é o dono de um telemóvel (e de contactar clientes
        // por fora da central). Para ele, o número fica como texto e o filtro de
        // conteúdo recusa a mensagem.
        if ($author->role !== UserRole::DRIVER) {
            $body = preg_replace_callback('/@\+?(?:244)?(9\d{8})(?!\d)/', function ($m) use ($municipalityId) {
                $user = $this->mentionable($municipalityId)->where('phone', 'like', '%' . $m[1])->first();

                return $user ? '@[' . $user->id . ']' : $m[0];
            }, $body) ?? $body;
        }

        // 2) validar cada @[id]
        $ids = [];
        $body = preg_replace_callback('/@\[(\d{1,12})\]/', function ($m) use (&$ids, $municipalityId) {
            $id = (int) $m[1];
            if (!in_array($id, $ids, true)) {
                if (!$this->mentionable($municipalityId)->whereKey($id)->exists()) {
                    return '';
                }
                $ids[] = $id;
            }

            return $m[0];
        }, $body) ?? $body;

        if (count($ids) > self::MAX_MENTIONS) {
            throw ValidationException::withMessages(['body' => 'Pode mencionar no máximo ' . self::MAX_MENTIONS . ' pessoas por mensagem.']);
        }

        return [trim($body), array_values(array_diff($ids, [$author->id]))];
    }

    /** Quem se pode mencionar numa sala: quem vive no município + a equipa. */
    public function mentionable(int $municipalityId)
    {
        return User::query()->where('status', 'ACTIVE')->whereNull('deletion_scheduled_for')
            ->where(fn ($q) => $q->where('municipality_id', $municipalityId)
                ->orWhereIn('role', array_map(fn ($r) => $r->value, [UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::ADMIN, UserRole::MODERATOR])));
    }

    /** Texto legível (para notificações): @[id] vira @Nome. */
    public function plainText(string $body): string
    {
        preg_match_all('/@\[(\d+)\]/', $body, $m);
        $names = $m[1] ? User::query()->whereIn('id', array_unique($m[1]))->pluck('name', 'id') : collect();

        return trim((string) preg_replace_callback('/@\[(\d+)\]/', fn ($x) => '@' . ($names[(int) $x[1]] ?? 'vizinho'), $body));
    }

    // ── Notificações ────────────────────────────────────────────────────

    private function notifyAbout(CommunityMessage $message, User $author, array $mentionIds, ?CommunityMessage $replyTo): void
    {
        try {
            $excerpt = Str::limit($this->plainText((string) $message->body) ?: 'Enviou uma foto', 110);
            $path = '/comunidade?municipio=' . $message->municipality_id . '&mensagem=' . $message->id;
            $skip = CommunityBlock::query()->where('blocked_user_id', $author->id)->pluck('user_id')->all();

            $recipients = User::query()->whereIn('id', $mentionIds)->get()->keyBy('id');
            foreach ($recipients as $user) {
                if (in_array($user->id, $skip, true) || !$user->wantsNotifications('community')) {
                    continue;
                }
                $this->notify($user->id, $author->name . ' mencionou-o nos Vizinhos', $excerpt,
                    ['type' => 'community_mention', 'path' => $path, 'message_id' => $message->id]);
            }

            if ($replyTo && $replyTo->user_id !== $author->id && !isset($recipients[$replyTo->user_id]) && !in_array($replyTo->user_id, $skip, true)) {
                $target = User::query()->find($replyTo->user_id);
                if ($target && $target->wantsNotifications('community')) {
                    $this->notify($target->id, $author->name . ' respondeu-lhe nos Vizinhos', $excerpt,
                        ['type' => 'community_reply', 'path' => $path, 'message_id' => $message->id]);
                }
            }
        } catch (Throwable) {
            // melhor esforço: a mensagem já foi publicada
        }
    }

    private function notify(int $userId, string $title, string $body, array $data): void
    {
        try {
            AppNotification::create([
                'user_id' => $userId, 'category' => NotificationCategory::COMMUNITY,
                'title' => $title, 'body' => $body, 'data' => $data,
            ]);
        } catch (Throwable) {
            // melhor esforço
        }
    }

    private function audit(User $actor, string $action, CommunityMessage $message, array $extra = []): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => $action, 'entity_type' => CommunityMessage::class, 'entity_id' => $message->id,
            'new_values' => $extra + ['municipality_id' => $message->municipality_id],
        ]);
    }
}
