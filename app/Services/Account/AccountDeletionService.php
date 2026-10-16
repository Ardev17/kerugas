<?php

namespace App\Services\Account;

use App\Enums\GasOrderStatus;
use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Models\AnalyticsEvent;
use App\Models\Alert;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\ClientError;
use App\Models\ContactMessage;
use App\Models\DeviceToken;
use App\Models\DriverCashReceipt;
use App\Models\DriverRating;
use App\Models\Favorite;
use App\Models\GasOrder;
use App\Models\GasOrderEvent;
use App\Models\OrderPushSubscription;
use App\Models\SmsDispatch;
use App\Models\SmsMessage;
use App\Models\Station;
use App\Models\StationComment;
use App\Models\StationReport;
use App\Models\StationReview;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Media\AvatarService;
use App\Services\PhoneNumberService;
use App\Services\Stock\DriverStockService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Eliminação de conta pela própria pessoa (Outubro 2026).
 *
 * 1. Pedido (com a palavra-passe): fica agendada para daqui a GRACE_DAYS
 *    dias e TODAS as sessões terminam. Entrar de novo nesse prazo cancela.
 * 2. Passado o prazo, `kerugas:accounts-purge` (diário) anonimiza a conta:
 *    nome, telefone, email, palavra-passe, localização e foto saem; os
 *    conteúdos pessoais (favoritos, alertas, notificações, comentários,
 *    mensagens do chat, dispositivos) são apagados. Os pedidos de gás e os
 *    movimentos de dinheiro FICAM (são registos financeiros), mas sem nome,
 *    telefone nem morada. O número de telefone fica livre para uma conta nova.
 *
 * Não pode pedir (ver blockers()): quem ainda tem garrafas ou dinheiro por
 * acertar (condutor), um posto atribuído (operador), um pedido em curso, ou
 * o último Super Admin geral.
 */
class AccountDeletionService
{
    public const GRACE_DAYS = 7;

    private const ACTIVE = [
        GasOrderStatus::PENDING, GasOrderStatus::RECEIVED, GasOrderStatus::CONTACTING,
        GasOrderStatus::CONFIRMED, GasOrderStatus::ASSIGNED, GasOrderStatus::OUT_FOR_DELIVERY,
    ];

    private const TERMINAL = [GasOrderStatus::DELIVERED, GasOrderStatus::CANCELLED, GasOrderStatus::FAILED];

    public function __construct(
        private readonly AvatarService $avatars,
        private readonly DriverStockService $stock,
        private readonly PhoneNumberService $phones,
    ) {
    }

    /** @return list<string> motivos que impedem a eliminação agora */
    public function blockers(User $user): array
    {
        $reasons = [];

        if ($user->role === UserRole::SUPER_ADMIN
            && User::where('role', UserRole::SUPER_ADMIN)->where('status', 'ACTIVE')->whereKeyNot($user->id)->doesntExist()) {
            $reasons[] = 'É o único Super Admin geral. Nomeie outro Super Admin antes de eliminar a sua conta.';
        }

        if ($user->operated_station_id !== null && Station::whereKey($user->operated_station_id)->exists()) {
            $reasons[] = 'Ainda é operador de um posto. Peça ao Super Admin para passar o posto a outra pessoa.';
        }

        if (GasOrder::where('user_id', $user->id)->whereIn('status', self::ACTIVE)->exists()) {
            $reasons[] = 'Tem um pedido de gás em curso. Aguarde a entrega ou cancele o pedido.';
        }

        if ($user->role === UserRole::DRIVER) {
            if (GasOrder::where('assigned_to', $user->id)->whereIn('status', self::ACTIVE)->exists()) {
                $reasons[] = 'Tem entregas atribuídas por concluir.';
            }

            $onHand = $this->stock->forDriver($user->id)['on_hand'];
            if ($onHand > 0) {
                $reasons[] = "Ainda tem {$onHand} garrafa(s) consigo. Acerte o stock com a Kerugas antes de eliminar a conta.";
            }

            $collected = (float) GasOrder::where('status', GasOrderStatus::DELIVERED)->where('assigned_to', $user->id)->sum('delivery_price');
            $received = (float) DriverCashReceipt::where('driver_id', $user->id)->sum('amount');
            if (round($collected - $received, 2) > 0.009) {
                $reasons[] = 'Ainda tem dinheiro de entregas por entregar à Kerugas.';
            }
        }

        return $reasons;
    }

    /** Agenda a eliminação e termina todas as sessões. */
    public function request(User $user, ?string $reason, ?string $ip, ?string $userAgent): CarbonInterface
    {
        $when = now()->addDays(self::GRACE_DAYS);

        DB::transaction(function () use ($user, $reason, $ip, $userAgent, $when) {
            $user->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => $when])->save();
            $this->endAllSessions($user);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'account.deletion_requested',
                'entity_type' => User::class,
                'entity_id' => $user->id,
                'old_values' => null,
                'new_values' => ['scheduled_for' => $when->toIso8601String(), 'reason' => $reason !== null ? Str::limit($reason, 500, '') : null],
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ]);
        });

        return $when;
    }

    /** Entrar de novo durante o prazo cancela o pedido. */
    public function cancel(User $user, ?string $ip = null): bool
    {
        if ($user->deletion_scheduled_for === null) {
            return false;
        }

        // Com a linha bloqueada: nunca "cancelar" uma conta que a limpeza
        // diária está a eliminar neste preciso momento.
        $cancelled = DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$locked || $locked->deletion_scheduled_for === null || $locked->status !== 'ACTIVE') {
                return false;
            }
            $locked->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->save();

            return true;
        });

        if (!$cancelled) {
            return false;
        }
        $user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->syncOriginal();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'account.deletion_cancelled',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'ip_address' => $ip,
        ]);

        try {
            AppNotification::create([
                'user_id' => $user->id,
                'category' => NotificationCategory::SECURITY,
                'title' => 'Eliminação da conta cancelada',
                'body' => 'Voltou a entrar na sua conta, por isso o pedido de eliminação foi cancelado. Se não foi você, mude a palavra-passe.',
                'data' => ['type' => 'security', 'path' => '/perfil'],
            ]);
        } catch (Throwable) {
            // melhor esforço
        }

        return true;
    }

    /**
     * Anonimiza a conta (passado o prazo). Devolve false se algo, entretanto,
     * a impede (ex.: o condutor voltou a ter garrafas) — tenta-se no dia seguinte.
     */
    /**
     * Vizinhos KeruGás: as mensagens da pessoa, as suas reações, menções,
     * denúncias, bloqueios e silenciamentos saem de vez. Devolve as fotos
     * a apagar do disco depois de a transação confirmar.
     *
     * @return list<string>
     */
    private function eraseCommunity(User $user): array
    {
        $files = \App\Models\CommunityMessage::query()->where('user_id', $user->id)->whereNotNull('image_path')->pluck('image_path')->all();

        \App\Models\CommunityMessage::query()->where('user_id', $user->id)->delete();
        \App\Models\CommunityMention::query()->where('user_id', $user->id)->delete();
        \App\Models\CommunityReaction::query()->where('user_id', $user->id)->delete();
        \App\Models\CommunityReport::query()->where('user_id', $user->id)->delete();
        \App\Models\CommunityBlock::query()->where('user_id', $user->id)->orWhere('blocked_user_id', $user->id)->delete();
        \App\Models\CommunityMute::query()->where('user_id', $user->id)->delete();

        // Chat da Equipa (Outubro 2026): o mesmo para as mensagens de admin.
        $files = array_merge($files, \App\Models\StaffChatMessage::query()->where('user_id', $user->id)->whereNotNull('image_path')->pluck('image_path')->all());
        \App\Models\StaffChatMessage::query()->where('user_id', $user->id)->delete();
        \App\Models\StaffChatMention::query()->where('user_id', $user->id)->delete();
        \App\Models\StaffChatReaction::query()->where('user_id', $user->id)->delete();
        \App\Models\StaffChatMute::query()->where('user_id', $user->id)->delete();

        // Investidor (Outubro 2026): termina as associações em vigor (o histórico fica).
        \App\Models\MunicipalityInvestment::query()->active()->where('user_id', $user->id)->update(['ends_at' => now(), 'end_reason' => 'account_deleted', 'updated_at' => now()]);

        // Conversa dos condutores e Conversa com a Central (Outubro 2026): as
        // mensagens da pessoa e a sua própria conversa com a Central.
        $own = \App\Models\RoomMessage::query()->where(fn ($q) => $q->where('user_id', $user->id)
            ->orWhere(fn ($c) => $c->whereIn('room_type', [\App\Models\RoomMessage::CENTRAL, \App\Models\RoomMessage::INVESTOR])->where('room_id', $user->id)));
        $files = array_merge($files, (clone $own)->whereNotNull('image_path')->pluck('image_path')->all());
        $own->delete();
        \App\Models\RoomMessageMention::query()->where('user_id', $user->id)->delete();
        \App\Models\RoomMessageReaction::query()->where('user_id', $user->id)->delete();
        \Illuminate\Support\Facades\DB::table('room_reads')->where('user_id', $user->id)->delete();

        return $files;
    }

    public function purge(User $user): bool
    {
        $communityFiles = [];
        $avatarPath = DB::transaction(function () use ($user, &$communityFiles) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$locked || $locked->deletion_scheduled_for === null || $locked->deletion_scheduled_for->isFuture()) {
                return false; // cancelado entretanto
            }
            // Verificado COM a conta bloqueada: nada lhe pode ser atribuído a meio.
            if ($this->blockers($locked) !== []) {
                return false;
            }

            $avatar = $locked->avatar_path;
            $oldPhone = (string) $locked->phone;
            $oldEmail = $locked->email;
            $orderIds = GasOrder::where('user_id', $user->id)->pluck('id')
                ->merge($this->guestOrderIds($oldPhone))->unique()->values();

            GasOrder::whereIn('id', $orderIds)->update([
                'customer_name' => 'Cliente eliminado',
                'customer_phone' => 'eliminado',
                'address' => null,
                'reference_point' => null,
                'notes' => null,
                'latitude' => null,
                'longitude' => null,
            ]);
            OrderPushSubscription::whereIn('gas_order_id', $orderIds)->delete();

            $this->endAllSessions($locked);
            Favorite::where('user_id', $user->id)->delete();
            Alert::where('user_id', $user->id)->delete();
            AppNotification::where('user_id', $user->id)->delete();
            ChatMessage::where('user_id', $user->id)->delete(); // tabela antiga (histórico)
            StationComment::where('user_id', $user->id)->delete();
            $communityFiles = $this->eraseCommunity($locked);
            $this->eraseTraces($locked, $oldPhone, $oldEmail, $orderIds);

            $locked->forceFill([
                'name' => 'Conta eliminada',
                // O telefone é único e obrigatório: um valor que nunca é um
                // número válido liberta o número real para uma conta nova.
                'phone' => 'eliminado-' . $locked->id,
                'email' => null,
                'email_verified_at' => null,
                'phone_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'latitude' => null,
                'longitude' => null,
                'avatar_path' => null,
                'neighborhood_id' => null,
                'last_seen_at' => null,
                'community_accepted_at' => null,
                'notification_preferences' => null,
                'driver_available' => false,
                'can_promote_users' => false,
                'status' => 'DELETED',
                'deletion_scheduled_for' => null,
            ])->save();

            AuditLog::create([
                'user_id' => null,
                'action' => 'account.deleted',
                'entity_type' => User::class,
                'entity_id' => $locked->id,
                'new_values' => ['orders_anonymized' => $orderIds->count(), 'role' => $locked->role->value],
            ]);

            $locked->delete(); // eliminação "suave": os registos financeiros mantêm a referência

            return $avatar ?? '';
        });

        if ($avatarPath === false) {
            return false;
        }

        $this->avatars->deleteFile($avatarPath === '' ? null : $avatarPath);
        foreach ($communityFiles as $file) {
            app(\App\Services\Community\CommunityImageService::class)->deleteFile($file);
        }

        return true;
    }

    /** Pedidos feitos SEM conta com o mesmo número (já terminados). */
    private function guestOrderIds(string $phone): \Illuminate\Support\Collection
    {
        $normalized = $this->phones->normalize($phone);
        $digits = substr((string) preg_replace('/\D/', '', $phone), -9);
        if ($normalized === null || strlen($digits) < 9) {
            return collect();
        }

        return GasOrder::whereNull('user_id')->whereIn('status', self::TERMINAL)
            ->where('customer_phone', 'like', '%' . $digits)
            ->get(['id', 'customer_phone'])
            ->filter(fn (GasOrder $o) => $this->phones->normalize($o->customer_phone) === $normalized)
            ->pluck('id');
    }

    /**
     * Restos de dados pessoais noutras tabelas: auditoria (IP, navegador,
     * nomes/telefones antigos), SMS, textos escritos pela pessoa, mensagens
     * de contacto, recuperação de palavra-passe e ligações a estatísticas.
     */
    private function eraseTraces(User $user, string $phone, ?string $email, \Illuminate\Support\Collection $orderIds): void
    {
        $id = $user->id;

        AuditLog::where('entity_type', User::class)->where('entity_id', $id)
            ->update(['old_values' => null, 'new_values' => null, 'ip_address' => null, 'user_agent' => null]);
        AuditLog::where('user_id', $id)->update(['ip_address' => null, 'user_agent' => null]);
        AuditLog::where('new_values->driver_id', $id)->get()->each(function (AuditLog $log) {
            $values = (array) $log->new_values;
            unset($values['driver_name'], $values['phone']);
            $log->forceFill(['new_values' => $values])->save();
        });

        GasOrderEvent::whereIn('gas_order_id', $orderIds)->update(['notes' => null]);
        DriverRating::whereIn('gas_order_id', $orderIds)->orWhere('driver_id', $id)->update(['comment' => null]);
        StationReview::where('user_id', $id)->update(['comment' => null]);
        StationReport::where('user_id', $id)->update(['description' => null]);

        SmsMessage::where('user_id', $id)->orWhere('sender', $phone)->delete();
        SmsDispatch::where('recipient', $phone)->update(['recipient' => 'eliminado', 'message' => '[mensagem apagada — conta eliminada]']);
        Subscription::where('phone', $phone)->update(['phone' => null]);

        $digits = substr((string) preg_replace('/\D/', '', $phone), -9);
        if (strlen($digits) === 9 || $email) {
            // O contacto é texto livre ("923 555 111", "+244 923-555-111",
            // "Ana@Exemplo.ao"): compara-se normalizado, em PHP, para não
            // depender de funções SQL de cada base de dados.
            $emailLower = $email ? mb_strtolower(trim($email)) : null;
            $ids = [];
            ContactMessage::query()->select(['id', 'contact'])->chunkById(500, function ($rows) use (&$ids, $digits, $emailLower) {
                foreach ($rows as $row) {
                    $contact = (string) $row->contact;
                    $contactDigits = (string) preg_replace('/\D/', '', $contact);
                    if (strlen($digits) === 9 && str_contains($contactDigits, $digits)) {
                        $ids[] = $row->id;
                    } elseif ($emailLower && mb_strtolower(trim($contact)) === $emailLower) {
                        $ids[] = $row->id;
                    }
                }
            });
            foreach (array_chunk($ids, 500) as $chunk) {
                ContactMessage::query()->whereIn('id', $chunk)->delete();
            }
        }

        if ($email) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        }
        AnalyticsEvent::where('user_id', $id)->update(['user_id' => null]);
        ClientError::where('last_user_id', $id)->update(['last_user_id' => null]);
    }

    private function endAllSessions(User $user): void
    {
        $ids = $user->tokens()->pluck('id');
        DeviceToken::whereIn('personal_access_token_id', $ids)->delete();
        WebPushSubscription::whereIn('personal_access_token_id', $ids)->delete();
        DeviceToken::where('user_id', $user->id)->delete();
        WebPushSubscription::where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }
}
