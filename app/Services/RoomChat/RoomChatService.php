<?php

namespace App\Services\RoomChat;

use App\Enums\NotificationCategory;
use App\Events\RoomMessagePosted;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\RoomMessage;
use App\Models\RoomMessageMention;
use App\Models\RoomMessageReaction;
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
 * Conversa dos condutores e Conversa com a Central (Outubro 2026): as mesmas
 * características dos Vizinhos — respostas, @menções, reações, fotos,
 * partilha de localização, editar durante 15 min e apagar.
 */
class RoomChatService
{
    public const MAX_BODY = 1000;

    public const MAX_MENTIONS = 5;

    public const EDIT_MINUTES = 15;

    /** Durações da partilha de localização (minutos). */
    public const LOCATION_MINUTES = [15, 30, 60, 120];

    public function __construct(private readonly CommunityImageService $images)
    {
    }

    // ── Publicar ────────────────────────────────────────────────────────

    /**
     * @param  array{body?: ?string, reply_to_id?: ?int, latitude?: ?float, longitude?: ?float, accuracy?: ?int, minutes?: ?int}  $data
     */
    public function post(User $author, string $type, int $roomId, array $data, ?UploadedFile $image = null): RoomMessage
    {
        $this->assertCanWrite($author, $type, $roomId);

        $body = $this->cleanBody((string) ($data['body'] ?? ''));
        $hasLocation = isset($data['latitude'], $data['longitude']);
        if ($body === '' && $image === null && !$hasLocation) {
            throw ValidationException::withMessages(['body' => 'Escreva uma mensagem, junte uma foto ou partilhe a localização.']);
        }

        $this->throttle($author);

        $replyTo = null;
        if (!empty($data['reply_to_id'])) {
            $replyTo = RoomMessage::query()->room($type, $roomId)->whereKey($data['reply_to_id'])->first();
            if (!$replyTo) {
                throw ValidationException::withMessages(['reply_to_id' => 'A mensagem a que quer responder já não existe.']);
            }
        }

        [$body, $mentionIds] = $this->resolveMentions($body, $author, $type, $roomId);
        if ($body !== '' && !$hasLocation && $image === null) {
            $this->assertNotDuplicate($author, $type, $roomId, $body);
        }

        $location = [];
        if ($hasLocation) {
            $minutes = (int) ($data['minutes'] ?? 60);
            if (!in_array($minutes, self::LOCATION_MINUTES, true)) {
                throw ValidationException::withMessages(['minutes' => 'Escolha 15 min, 30 min, 1 h ou 2 h.']);
            }
            $location = [
                'location_lat' => round((float) $data['latitude'], 7),
                'location_lng' => round((float) $data['longitude'], 7),
                'location_accuracy' => isset($data['accuracy']) ? (int) $data['accuracy'] : null,
                'location_expires_at' => now()->addMinutes($minutes),
            ];
        }

        $path = $image ? $this->images->store($image) : null;

        try {
            $message = DB::transaction(function () use ($author, $type, $roomId, $body, $replyTo, $path, $mentionIds, $location) {
                $message = RoomMessage::create([
                    'room_type' => $type, 'room_id' => $roomId, 'user_id' => $author->id,
                    'body' => $body, 'reply_to_id' => $replyTo?->id, 'image_path' => $path,
                ] + $location);
                foreach ($mentionIds as $id) {
                    RoomMessageMention::create(['message_id' => $message->id, 'user_id' => $id]);
                }

                return $message;
            });
        } catch (Throwable $e) {
            $this->images->deleteFile($path);
            throw $e;
        }

        $this->notifyAbout($message, $author, $mentionIds, $replyTo);
        RoomMessagePosted::dispatchSafely($message);

        return $message;
    }

    public function edit(User $actor, RoomMessage $message, string $body): RoomMessage
    {
        if ($message->user_id !== $actor->id || !$message->isVisible()) {
            throw new HttpException(403, 'Só pode editar as suas mensagens.');
        }
        if ($message->created_at->lt(now()->subMinutes(self::EDIT_MINUTES))) {
            throw new HttpException(403, 'Já passaram mais de ' . self::EDIT_MINUTES . ' minutos — já não pode editar.');
        }
        $this->assertCanWrite($actor, $message->room_type, $message->room_id);

        $body = $this->cleanBody($body);
        if ($body === '' && !$message->image_path && !$message->hasLocation()) {
            throw ValidationException::withMessages(['body' => 'A mensagem não pode ficar vazia.']);
        }

        [$body, $mentionIds] = $this->resolveMentions($body, $actor, $message->room_type, $message->room_id);
        $previous = $message->mentions()->pluck('user_id')->all();

        DB::transaction(function () use ($message, $body, $mentionIds) {
            $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
            $message->mentions()->whereNotIn('user_id', $mentionIds)->delete();
            foreach ($mentionIds as $id) {
                RoomMessageMention::query()->firstOrCreate(['message_id' => $message->id, 'user_id' => $id]);
            }
        });

        $this->notifyAbout($message, $actor, array_values(array_diff($mentionIds, $previous)), null, false);

        return $message;
    }

    /** O autor apaga a sua; um admin da sala remove a de outra pessoa (com motivo). */
    public function delete(User $actor, RoomMessage $message, ?string $reason = null): void
    {
        if ($message->user_id === $actor->id) {
            $message->forceFill([
                'author_deleted_at' => $message->author_deleted_at ?? now(),
                'location_ended_at' => $message->location_ended_at ?? ($message->locationActive() ? now() : null),
            ])->save();

            return;
        }

        if (!RoomAccess::moderates($actor, $message->room_type, $message->room_id)) {
            throw new HttpException(403, 'Só pode apagar as suas mensagens.');
        }

        $message->forceFill([
            'removed_at' => now(), 'removed_by' => $actor->id,
            'removal_reason' => $reason ? Str::limit(trim($reason), 200, '') : null,
            'location_ended_at' => $message->location_ended_at ?? ($message->locationActive() ? now() : null),
        ])->save();

        AuditLog::create([
            'user_id' => $actor->id, 'action' => "room_chat.{$message->room_type}.removed",
            'entity_type' => RoomMessage::class, 'entity_id' => $message->id,
            'new_values' => ['reason' => $message->removal_reason, 'author_id' => $message->user_id],
        ]);
    }

    public function stopLocation(User $actor, RoomMessage $message): void
    {
        if ($message->user_id !== $actor->id) {
            throw new HttpException(403, 'Só quem partilhou pode parar a partilha.');
        }
        if ($message->locationActive()) {
            $message->forceFill(['location_ended_at' => now()])->save();
            RoomMessagePosted::dispatchSafely($message);
        }
    }

    public function react(User $actor, RoomMessage $message, ?string $emoji): void
    {
        if (!$message->isVisible()) {
            throw new HttpException(404);
        }
        if ($emoji === null) {
            RoomMessageReaction::query()->where(['message_id' => $message->id, 'user_id' => $actor->id])->delete();

            return;
        }
        RoomMessageReaction::query()->updateOrCreate(['message_id' => $message->id, 'user_id' => $actor->id], ['emoji' => $emoji]);
    }

    // ── Verificações ────────────────────────────────────────────────────

    public function assertCanView(User $user, string $type, int $roomId): void
    {
        if (!RoomAccess::canView($user, $type, $roomId)) {
            throw new HttpException(403, $type === RoomMessage::DRIVERS
                ? 'Não tem acesso à conversa deste município.'
                : 'Não tem acesso a esta conversa.');
        }
    }

    public function assertCanWrite(User $user, string $type, int $roomId): void
    {
        $this->assertCanView($user, $type, $roomId);
        if (!RoomAccess::canWrite($user, $type, $roomId)) {
            throw new HttpException(403, 'Só pode responder depois de a Central lhe escrever.');
        }
    }

    private function throttle(User $author): void
    {
        if (!RateLimiter::attempt('roomchat:gap:' . $author->id, 1, fn () => true, 3)) {
            throw ValidationException::withMessages(['body' => 'Calma! Espere uns segundos antes de enviar outra mensagem.']);
        }
        $hourKey = 'roomchat:hour:' . $author->id;
        if (RateLimiter::tooManyAttempts($hourKey, 60)) {
            throw ValidationException::withMessages(['body' => 'Já enviou muitas mensagens esta hora. Volte daqui a pouco.']);
        }
        RateLimiter::hit($hourKey, 3600);
    }

    private function assertNotDuplicate(User $author, string $type, int $roomId, string $body): void
    {
        if (RoomMessage::query()->room($type, $roomId)->where('user_id', $author->id)->where('body', $body)->where('created_at', '>=', now()->subMinutes(5))->exists()) {
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

    /** @return array{0: string, 1: list<int>} */
    public function resolveMentions(string $body, User $author, string $type, int $roomId): array
    {
        $ids = [];
        $body = preg_replace_callback('/@\[(\d{1,12})\]/', function ($m) use (&$ids, $type, $roomId) {
            $id = (int) $m[1];
            if (!in_array($id, $ids, true)) {
                if (!RoomAccess::participants($type, $roomId)->whereKey($id)->exists()) {
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

    public function plainText(string $body): string
    {
        preg_match_all('/@\[(\d+)\]/', $body, $m);
        $names = $m[1] ? User::query()->whereIn('id', array_unique($m[1]))->pluck('name', 'id') : collect();

        return trim((string) preg_replace_callback('/@\[(\d+)\]/', fn ($x) => '@' . ($names[(int) $x[1]] ?? 'colega'), $body));
    }

    // ── Notificações ────────────────────────────────────────────────────

    public static function path(RoomMessage $message, User $recipient): string
    {
        if ($message->room_type === RoomMessage::DRIVERS) {
            return RoomAccess::isStaff($recipient)
                ? '/admin/chat?municipio=' . $message->room_id . '&mensagem=' . $message->id
                : '/driver?chat=1&mensagem=' . $message->id;
        }

        if ($message->room_type === RoomMessage::INVESTOR) {
            return RoomAccess::isStaff($recipient)
                ? '/admin/investidores?investidor=' . $message->room_id . '&mensagem=' . $message->id
                : '/investidor?conversa=1&mensagem=' . $message->id;
        }

        return RoomAccess::isStaff($recipient)
            ? '/admin/conversas-central?utilizador=' . $message->room_id . '&mensagem=' . $message->id
            : '/conversa-central?mensagem=' . $message->id;
    }

    private function notifyAbout(RoomMessage $message, User $author, array $mentionIds, ?RoomMessage $replyTo, bool $isNew = true): void
    {
        try {
            $excerpt = Str::limit($this->plainText((string) $message->body) ?: ($message->image_path ? 'Enviou uma foto' : 'Partilhou a localização'), 110);
            $where = match ($message->room_type) {
                RoomMessage::DRIVERS => 'na conversa dos condutores' . (($m = Municipality::query()->find($message->room_id)) ? " ({$m->name})" : ''),
                RoomMessage::INVESTOR => 'na conversa de investidor',
                default => 'na Conversa com a Central',
            };
            $notified = [];

            foreach (User::query()->whereIn('id', $mentionIds)->get() as $user) {
                if (!$user->wantsNotifications('community')) {
                    continue;
                }
                $this->notify($user->id, "{$author->name} mencionou-o {$where}", $excerpt, $message, $user, 'mention');
                $notified[] = $user->id;
            }

            if ($replyTo && $replyTo->user_id !== $author->id && !in_array($replyTo->user_id, $notified, true)) {
                $target = User::query()->find($replyTo->user_id);
                if ($target && $target->wantsNotifications('community')) {
                    $this->notify($target->id, "{$author->name} respondeu-lhe {$where}", $excerpt, $message, $target, 'reply');
                    $notified[] = $target->id;
                }
            }

            if ($isNew && $message->room_type === RoomMessage::INVESTOR) {
                // Super Admin escreveu → avisa o investidor; o investidor escreveu → avisa os Super Admins gerais.
                $targets = $author->id === $message->room_id
                    ? User::query()->where('role', \App\Enums\UserRole::SUPER_ADMIN)->where('status', 'ACTIVE')->get()
                    : User::query()->whereKey($message->room_id)->get();
                foreach ($targets as $target) {
                    if (!in_array($target->id, $notified, true)) {
                        $title = $author->id === $message->room_id ? "{$author->name} (investidor) escreveu-lhe" : 'Nova mensagem do Super Admin';
                        $this->notify($target->id, $title, $excerpt, $message, $target, 'investor');
                    }
                }

                return;
            }

            if (!$isNew || $message->room_type !== RoomMessage::CENTRAL) {
                return;
            }

            // Central: a direção escreveu → avisa o utilizador (sempre — é um aviso direto).
            if (RoomAccess::isStaff($author)) {
                $target = User::query()->find($message->room_id);
                if ($target && !in_array($target->id, $notified, true)) {
                    $this->notify($target->id, 'Nova mensagem da Central KeruGás', $excerpt, $message, $target, 'central');
                }

                return;
            }

            // O utilizador respondeu → avisa os admins que já escreveram na conversa.
            $writers = RoomMessage::query()->room(RoomMessage::CENTRAL, $message->room_id)
                ->where('user_id', '!=', $author->id)->distinct()->pluck('user_id');
            foreach (User::query()->whereIn('id', $writers)->get() as $admin) {
                if (RoomAccess::isStaff($admin) && !in_array($admin->id, $notified, true)) {
                    $this->notify($admin->id, "{$author->name} respondeu na Conversa com a Central", $excerpt, $message, $admin, 'central_reply');
                }
            }
        } catch (Throwable) {
            // melhor esforço: a mensagem já foi publicada
        }
    }

    private function notify(int $userId, string $title, string $body, RoomMessage $message, User $recipient, string $kind): void
    {
        try {
            AppNotification::create([
                'user_id' => $userId,
                'category' => NotificationCategory::COMMUNITY,
                'title' => $title,
                'body' => $body,
                'data' => [
                    'type' => "room_chat_{$kind}",
                    'room_type' => $message->room_type,
                    'room_id' => $message->room_id,
                    'message_id' => $message->id,
                    'path' => self::path($message, $recipient),
                ],
            ]);
        } catch (Throwable) {
            // melhor esforço
        }
    }
}
