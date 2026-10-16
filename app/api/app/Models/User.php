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
        'province_id', 'municipality_id', 'latitude', 'longitude', 'role', 'status',
        // REGRESSÃO CORRIGIDA (de novo): sem estes 3, o bloqueio de
        // conta após tentativas de login falhadas nunca persiste (ver
        // comentário equivalente em Station::$fillable).
        'failed_login_attempts', 'locked_until', 'last_login_at',
        // Condutor (Setembro 2026) — só tem efeito prático quando role === DRIVER.
        'driver_available',
        // Limite diário de SMS por Admin (Setembro 2026) — só tem efeito
        // prático quando role === ADMIN, ver SmsDispatchService::dispatch().
        'sms_daily_limit',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'role' => UserRole::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'driver_available' => 'boolean',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
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
     * Um utilizador opera no máximo um posto na Fase 1 (secção 94).
     */
    public function operatedStation(): HasOne
    {
        return $this->hasOne(Station::class, 'operator_id');
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

    public function isAdmin(): bool
    {
        return in_array($this->role, [UserRole::ADMIN, UserRole::SUPER_ADMIN], true);
    }

    /**
     * Sobrepõe o envio padrão do Laravel (em inglês) pelo template
     * institucional em português (secção 59).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }
}
