<?php

namespace App\Services\StaffChat;

use App\Enums\NotificationCategory;
use App\Events\StaffChatMessagePosted;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\StaffChatMention;
use App\Models\StaffChatMessage;
use App\Models\StaffChatMute;
use App\Models\StaffChatReaction;
use App\Models\User;
use App\Services\Community\CommunityImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Chat da Equipa (Outubro 2026) — todas as ações sobre mensagens. Mesmas
 * características dos Vizinhos (respostas, @menções, reações, foto, editar
 * 15 min, fixar 3, pesquisa, presença, tempo real), numa sala só de admins.
 */
class StaffChatService
{
    public const MAX_BODY = 1000;

    public const MAX_MENTIONS = 5;

    public const EDIT_MINUTES = 15;

    public const MAX_PINNED = 3;

    public function __construct(private readonly CommunityImageService $images)
    {
    }

    // ── Publicar ────────────────────────────────────────────────────────

    public function post(User $author, array $data, ?UploadedFile $image = null): StaffChatMessage
    {
        $this->assertCanWrite($author);

        $body = $this->cleanBody((string) ($data['body'] ?? ''));
        if ($body === '' && $image === null) {
            throw ValidationException::withMessages(['body' => 'Escreva uma mensagem ou junte uma foto.']);
        }

        $this->throttle($author);

        $replyTo = null;
        if (!empty($data['reply_to_id'])) {
            $replyTo = StaffChatMessage::query()->whereKey($data['reply_to_id'])->first();
            if (!$replyTo) {
                throw ValidationException::withMessages(['reply_to_id' => 'A mensagem a que quer responder já não existe.']);
            }
        }

        [$body, $mentionIds] = $this->resolveMentions($body, $author);
        $this->assertNotDuplicate($author, $body);

        $path = $image ? $this->images->store($image) : null;

        try {
            $message = DB::transaction(function () use ($author, $body, $replyTo, $path, $mentionIds) {
                $message = StaffChatMessage::create([
                    'user_id' => $author->id, 'body' => $body, 'reply_to_id' => $replyTo?->id, 'image_path' => $path,
                ]);
                foreach ($mentionIds as $id) {
                    StaffChatMention::create(['message_id' => $message->id, 'user_id' => $id]);
                }

                return $message;
            });
        } catch (Throwable $e) {
            $this->images->deleteFile($path);
            throw $e;
        }

        $this->notifyAbout($message, $author, $mentionIds, $replyTo);
        StaffChatMessagePosted::dispatchSafely($message);

        return $message;
    }

    public function edit(User $actor, StaffChatMessage $message, string $body): StaffChatMessage
    {
        if ($message->user_id !== $actor->id || !$message->isVisible()) {
            throw new HttpException(403, 'Só pode editar as suas mensagens.');
        }
        if ($message->created_at->lt(now()->subMinutes(self::EDIT_MINUTES))) {
            throw new HttpException(403, 'Já passaram mais de ' . self::EDIT_MINUTES . ' minutos — já não pode editar.');
        }
        $this->assertCanWrite($actor);

        $body = $this->cleanBody($body);
        if ($body === '' && !$message->image_path) {
            throw ValidationException::withMessages(['body' => 'A mensagem não pode ficar vazia.']);
        }

        [$body, $mentionIds] = $this->resolveMentions($body, $actor);
        $previous = $message->mentions()->pluck('user_id')->all();

        DB::transaction(function () use ($message, $body, $mentionIds) {
            $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
            $message->mentions()->whereNotIn('user_id', $mentionIds)->delete();
            foreach ($mentionIds as $id) {
                StaffChatMention::query()->firstOrCreate(['message_id' => $message->id, 'user_id' => $id]);
            }
        });

        $this->notifyAbout($message, $actor, array_values(array_diff($mentionIds, $previous)), null);

        return $message;
    }

    /** O autor apaga: sai do chat; o Super Admin continua a ver o registo. */
    public function authorDelete(User $actor, StaffChatMessage $message): void
    {
        if ($message->user_id !== $actor->id) {
            throw new HttpException(403, 'Só pode apagar as suas mensagens.');
        }
        $message->forceFill(['author_deleted_at' => $message->author_deleted_at ?? now(), 'pinned_at' => null, 'pinned_by' => null])->save();
    }

    // ── Reações ─────────────────────────────────────────────────────────

    public function react(User $actor, StaffChatMessage $message, ?string $emoji): void
    {
        if (!$message->isVisible()) {
            throw new HttpException(404);
        }
        if ($emoji === null) {
            StaffChatReaction::query()->where(['message_id' => $message->id, 'user_id' => $actor->id])->delete();

            return;
        }
        StaffChatReaction::query()->updateOrCreate(['message_id' => $message->id, 'user_id' => $actor->id], ['emoji' => $emoji]);
    }

    // ── Moderação (Super Admin geral) ───────────────────────────────────

    public function pin(User $actor, StaffChatMessage $message, bool $pinned): void
    {
        $this->assertModerates($actor);
        if ($pinned && !$message->isVisible()) {
            throw new HttpException(422, 'Só se fixam mensagens visíveis.');
        }
        if ($pinned && StaffChatMessage::query()->whereNotNull('pinned_at')->count() >= self::MAX_PINNED) {
            throw new HttpException(422, 'Já existem ' . self::MAX_PINNED . ' avisos fixados. Retire um primeiro.');
        }
        $message->forceFill(['pinned_at' => $pinned ? now() : null, 'pinned_by' => $pinned ? $actor->id : null])->save();
        $this->audit($actor, $pinned ? 'staff_chat.pinned' : 'staff_chat.unpinned', $message);
    }

    public function remove(User $actor, StaffChatMessage $message, string $reason): void
    {
        $this->assertModerates($actor);
        $message->forceFill([
            'removed_at' => now(), 'removed_by' => $actor->id,
            'removal_reason' => Str::limit(trim($reason), 200, ''), 'pinned_at' => null, 'pinned_by' => null,
        ])->save();
        $this->audit($actor, 'staff_chat.removed', $message, ['reason' => $message->removal_reason]);

        if ($message->user_id !== $actor->id) {
            $this->notify($message->user_id, 'Uma mensagem sua foi removida do Chat da Equipa',
                'Motivo: ' . $message->removal_reason, ['type' => 'staff_chat_removed', 'path' => '/admin/chat-equipa']);
        }
    }

    public function restore(User $actor, StaffChatMessage $message): void
    {
        $this->assertModerates($actor);
        $message->forceFill(['removed_at' => null, 'removed_by' => null, 'removal_reason' => null])->save();
        $this->audit($actor, 'staff_chat.restored', $message);
    }

    public function hardDelete(User $actor, StaffChatMessage $message): void
    {
        $this->assertModerates($actor);
        $this->audit($actor, 'staff_chat.hard_deleted', $message, ['author_id' => $message->user_id]);
        $this->images->deleteFile($message->image_path);
        $message->delete();
    }

    public function mute(User $actor, User $target, ?int $hours, ?string $reason): StaffChatMute
    {
        $this->assertModerates($actor);
        if ($target->id === $actor->id) {
            throw new HttpException(422, 'Não pode silenciar-se a si próprio.');
        }
        if (!StaffChatAccess::allowed($target)) {
            throw new HttpException(422, 'Esta pessoa não faz parte do Chat da Equipa.');
        }
        if ($target->role === \App\Enums\UserRole::SUPER_ADMIN) {
            throw new HttpException(403, 'Não é possível silenciar outro Admin Geral.');
        }

        $mute = StaffChatMute::create([
            'user_id' => $target->id, 'muted_by' => $actor->id,
            'reason' => $reason ? Str::limit(trim($reason), 200, '') : null,
            'expires_at' => $hours === null ? null : now()->addHours($hours),
        ]);
        AuditLog::create([
            'user_id' => $actor->id, 'action' => 'staff_chat.muted', 'entity_type' => User::class, 'entity_id' => $target->id,
            'new_values' => ['hours' => $hours, 'reason' => $mute->reason],
        ]);
        $this->notify($target->id, 'Foi silenciado no Chat da Equipa',
            ($hours === null ? 'De forma permanente' : 'Durante ' . ($hours >= 48 ? intdiv($hours, 24) . ' dias' : $hours . ' horas'))
            . ($mute->reason ? '. Motivo: ' . $mute->reason : '.'),
            ['type' => 'staff_chat_muted', 'path' => '/admin/chat-equipa']);

        return $mute;
    }

    public function unmute(User $actor, User $target): void
    {
        $this->assertModerates($actor);
        StaffChatMute::query()->active()->where('user_id', $target->id)->update(['lifted_at' => now()]);
    }

    public function activeMute(User $user): ?StaffChatMute
    {
        return StaffChatMute::query()->active()->where('user_id', $user->id)->latest('id')->first();
    }

    // ── Verificações ────────────────────────────────────────────────────

    public function assertCanWrite(User $user): void
    {
        if (!StaffChatAccess::allowed($user)) {
            throw new HttpException(403, 'O Chat da Equipa é só para admins.');
        }
        if ($mute = $this->activeMute($user)) {
            throw new HttpException(403, $mute->expires_at
                ? 'Está silenciado até ' . $mute->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') . '.'
                : 'Está silenciado e não pode escrever.');
        }
    }

    private function assertModerates(User $actor): void
    {
        if (!StaffChatAccess::moderates($actor)) {
            throw new HttpException(403, 'Só o Admin Geral modera o Chat da Equipa.');
        }
    }

    private function throttle(User $author): void
    {
        if (!RateLimiter::attempt('staffchat:gap:' . $author->id, 1, fn () => true, 3)) {
            throw ValidationException::withMessages(['body' => 'Calma! Espere uns segundos antes de enviar outra mensagem.']);
        }
        $hourKey = 'staffchat:hour:' . $author->id;
        if (RateLimiter::tooManyAttempts($hourKey, 60)) {
            throw ValidationException::withMessages(['body' => 'Já enviou muitas mensagens esta hora. Volte daqui a pouco.']);
        }
        RateLimiter::hit($hourKey, 3600);
    }

    private function assertNotDuplicate(User $author, string $body): void
    {
        if ($body === '') {
            return;
        }
        if (StaffChatMessage::query()->where('user_id', $author->id)->where('body', $body)->where('created_at', '>=', now()->subMinutes(5))->exists()) {
            throw ValidationException::withMessages(['body' => 'Já enviou esta mesma mensagem há pouco.']);
        }
    }

    private function cleanBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", trim($body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? $body;

        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => 'A mensagem pode ter no máximo ' . self::MAX_BODY . ' caracteres.']);
        }

        return $body;
    }

    // ── Menções ─────────────────────────────────────────────────────────

    /**
     * Texto com as menções no formato `@[id]` (nunca o número) e os ids a
     * notificar. Só se mencionam admins ativos (a escolha vem da lista).
     *
     * @return array{0: string, 1: list<int>}
     */
    public function resolveMentions(string $body, User $author): array
    {
        $ids = [];
        $body = preg_replace_callback('/@\[(\d{1,12})\]/', function ($m) use (&$ids) {
            $id = (int) $m[1];
            if (!in_array($id, $ids, true)) {
                if (!$this->mentionable()->whereKey($id)->exists()) {
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

    public function mentionable()
    {
        return User::query()->where('status', 'ACTIVE')->whereNull('deletion_scheduled_for')
            ->whereIn('role', StaffChatAccess::roleValues());
    }

    public function plainText(string $body): string
    {
        preg_match_all('/@\[(\d+)\]/', $body, $m);
        $names = $m[1] ? User::query()->whereIn('id', array_unique($m[1]))->pluck('name', 'id') : collect();

        return trim((string) preg_replace_callback('/@\[(\d+)\]/', fn ($x) => '@' . ($names[(int) $x[1]] ?? 'colega'), $body));
    }

    // ── Notificações ────────────────────────────────────────────────────

    private function notifyAbout(StaffChatMessage $message, User $author, array $mentionIds, ?StaffChatMessage $replyTo): void
    {
        try {
            $excerpt = Str::limit($this->plainText((string) $message->body) ?: 'Enviou uma foto', 110);
            $path = '/admin/chat-equipa?mensagem=' . $message->id;

            $recipients = User::query()->whereIn('id', $mentionIds)->get()->keyBy('id');
            foreach ($recipients as $user) {
                if (!$user->wantsNotifications('community')) {
                    continue;
                }
                $this->notify($user->id, $author->name . ' mencionou-o no Chat da Equipa', $excerpt,
                    ['type' => 'staff_chat_mention', 'path' => $path, 'message_id' => $message->id]);
            }

            if ($replyTo && $replyTo->user_id !== $author->id && !isset($recipients[$replyTo->user_id])) {
                $target = User::query()->find($replyTo->user_id);
                if ($target && $target->wantsNotifications('community')) {
                    $this->notify($target->id, $author->name . ' respondeu-lhe no Chat da Equipa', $excerpt,
                        ['type' => 'staff_chat_reply', 'path' => $path, 'message_id' => $message->id]);
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

    private function audit(User $actor, string $action, StaffChatMessage $message, array $extra = []): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => $action, 'entity_type' => StaffChatMessage::class, 'entity_id' => $message->id,
            'new_values' => $extra,
        ]);
    }
}
