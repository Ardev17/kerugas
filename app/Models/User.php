<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'password',
        'province_id', 'municipality_id', 'neighborhood_id', 'operated_station_id', 'investor_deactivated_at', 'latitude', 'longitude', 'role', 'status',
        // REGRESSÃO CORRIGIDA (de novo): sem estes 3, o bloqueio de
        // conta após tentativas de login falhadas nunca persiste (ver
        // comentário equivalente em Station::$fillable).
        'failed_login_attempts', 'locked_until', 'last_login_at',
        // Condutor (Setembro 2026) — só tem efeito prático quando role === DRIVER.
        'driver_available',
        // Limite diário de SMS por Admin (Setembro 2026) — só tem efeito
        // prático quando role === ADMIN, ver SmsDispatchService::dispatch().
        'sms_daily_limit',
        // Permissão do Admin para mudar papéis (Outubro 2026) — concedida pelo Super Admin.
        'can_promote_users',
        // Preferências de notificações (Setembro 2026) — ver
        // wantsNotifications() e NotificationPreferenceController.
        'notification_preferences',
        // Vizinhos KeruGás (Outubro 2026).
        'last_seen_at', 'community_accepted_at',
    ];

    /**
     * O que cada pessoa aceita receber. `null` na base de dados = tudo
     * ligado (os valores por omissão abaixo). Notificações de pedidos,
     * segurança da conta e avisos do sistema NÃO dependem disto — são
     * sempre entregues.
     */
    public const NOTIFICATION_PREFERENCE_DEFAULTS = [
        'reminders' => true, // lembrete de gás, 2.º lembrete
        'alerts' => true,    // gás perto de si, fila curta
        'tips' => true,      // dicas de segurança
        'promos' => true,    // fim de semana, datas especiais, aniversário...
        'sms' => true,       // aceitar estes avisos também por SMS
        'community' => true, // Vizinhos KeruGás: menções e respostas
    ];

    /**
     * Nunca vão para uma resposta JSON quando o utilizador é serializado
     * "em bruto" (ex.: dentro de uma relação carregada). Os ecrãs recebem
     * o utilizador pelo UserResource, que escolhe os campos um a um — e
     * esse continua a ver tudo o que precisa (acesso explícito não é
     * afetado por esta lista).
     *
     * Outubro 2026 (dados mínimos): além da palavra-passe, os campos de
     * segurança da conta, a localização de casa, as preferências e as
     * permissões internas.
     */
    protected $hidden = [
        'password', 'remember_token',
        'failed_login_attempts', 'locked_until', 'last_login_at',
        'email_verified_at', 'phone_verified_at',
        'latitude', 'longitude',
        'notification_preferences',
        'deletion_requested_at', 'deletion_scheduled_for',
        'avatar_path',
        'sms_daily_limit', 'can_promote_users',
        'last_seen_at', 'community_accepted_at',
        'sms_pin_hash', 'sms_pin_encrypted', 'sms_pin_temporary', 'sms_pin_failures', 'sms_pin_locked_until',
        'central_pickup_enabled_at', 'central_pickup_expires_at', 'central_pickup_enabled_by', 'central_pickup_reason',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'community_accepted_at' => 'datetime',
            'central_pickup_enabled_at' => 'datetime',
            'central_pickup_expires_at' => 'datetime',
            'sms_pin_locked_until' => 'datetime',
            'sms_pin_temporary' => 'boolean',
            'sms_pin_encrypted' => 'encrypted',
            'investor_deactivated_at' => 'datetime',
            'locked_until' => 'datetime',
            // Eliminação de conta (Outubro 2026).
            'deletion_requested_at' => 'datetime',
            'deletion_scheduled_for' => 'datetime',
            'role' => UserRole::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'driver_available' => 'boolean',
            'can_promote_users' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /** @return array<string, bool> */
    /**
     * Foto de perfil (Outubro 2026): ao apagar a conta DEFINITIVAMENTE, o
     * ficheiro sai também. Numa eliminação "suave" fica (a conta pode ser
     * restaurada), mas deixa de ser servida — ver AvatarController.
     */
    protected static function booted(): void
    {
        static::forceDeleted(function (User $user) {
            app(\App\Services\Media\AvatarService::class)->deleteFile($user->avatar_path);
        });
    }

    public function notificationPreferences(): array
    {
        return array_merge(self::NOTIFICATION_PREFERENCE_DEFAULTS, array_intersect_key(
            (array) ($this->notification_preferences ?? []),
            self::NOTIFICATION_PREFERENCE_DEFAULTS
        ));
    }

    public function wantsNotifications(string $preference): bool
    {
        // Com a eliminação da conta pedida, deixa de receber lembretes,
        // alertas e promoções (os avisos de segurança continuam).
        if ($this->deletion_scheduled_for !== null) {
            return false;
        }

        return (bool) ($this->notificationPreferences()[$preference] ?? true);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function gasOrders(): HasMany
    {
        return $this->hasMany(GasOrder::class);
    }

    /**
     * Pedidos atribuídos a este utilizador como condutor (ou, no
     * esquema antigo de `assigned_to`, qualquer atribuição). Nome
     * distinto de `gasOrders()` (que é `user_id`, o cliente que criou o
     * pedido) — ver comentário equivalente em GasOrder::assignedUser().
     */
    public function assignedOrders(): HasMany
    {
        return $this->hasMany(GasOrder::class, 'assigned_to');
    }

    public function isDriver(): bool
    {
        return $this->role === UserRole::DRIVER;
    }

    /** Avaliações recebidas enquanto condutor (Setembro 2026). */
    public function driverRatings(): HasMany
    {
        return $this->hasMany(DriverRating::class, 'driver_id');
    }

    /**
     * Posto que este utilizador opera, quando role === STATION_OPERATOR.
     * Um operador pertence a um só posto; um posto pode ter vários
     * operadores (Outubro 2026).
     */
    public function operatedStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'operated_station_id');
    }

    /**
     * Nome distinto de `notifications()` propositadamente: o trait
     * Notifiable já define `notifications()`/`unreadNotifications()`
     * apontando para o sistema nativo de notificações do Laravel
     * (tabela com esquema diferente: notifiable_type/notifiable_id).
     * A nossa tabela `notifications` (AppNotification) tem esquema
     * próprio (user_id, category, title, body) — usar sempre
     * `appNotifications()` para não colidir com o método do trait.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    /**
     * Interruptor "registo pela central" (Outubro 2026): ligado pelo Super
     * Admin / Provincial quando o condutor não tem telemóvel. Caduca sozinho.
     */
    public function centralPickupActive(): bool
    {
        return $this->central_pickup_enabled_at !== null
            && ($this->central_pickup_expires_at === null || $this->central_pickup_expires_at->isFuture());
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, UserRole::managementRoles(), true);
    }

    /** Super Admin GERAL (todo o país, configurações gerais). */
    public function isGlobalSuperAdmin(): bool
    {
        return $this->role === UserRole::SUPER_ADMIN;
    }

    /** Super Admin Provincial (Outubro 2026). */
    public function isProvinceSuperAdmin(): bool
    {
        return $this->role === UserRole::PROVINCE_SUPER_ADMIN;
    }

    /** Poderes de Super Admin — geral, ou provincial dentro da sua província. */
    public function hasSuperPowers(): bool
    {
        return in_array($this->role, UserRole::superRoles(), true);
    }

    /**
     * Província a que esta conta está limitada: `null` = sem limite (Super
     * Admin geral). ADMIN e Super Admin Provincial ficam limitados à sua;
     * sem província atribuída devolve 0 — "sem âmbito = sem acesso", nunca
     * o contrário.
     */
    public function scopedProvinceId(): ?int
    {
        if (in_array($this->role, [UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN], true)) {
            return (int) ($this->province_id ?? 0);
        }

        return null;
    }

    public function canAccessProvince(?int $provinceId): bool
    {
        $scope = $this->scopedProvinceId();

        return $scope === null || ($scope !== 0 && (int) $provinceId === $scope);
    }

    /**
     * Sobrepõe o envio padrão do Laravel (em inglês) pelo template
     * institucional em português (secção 59).
     */
    public function sendPasswordResetNotification($token): void
    {
        // Outubro 2026: além do link (site), um código de 6 dígitos para a app.
        $code = app(\App\Services\Account\PasswordResetCodeService::class)->issue($this);
        $this->notify(new \App\Notifications\ResetPasswordNotification($token, $code));
    }
}
