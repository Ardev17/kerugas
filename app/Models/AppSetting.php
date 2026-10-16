<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Definições chave/valor editáveis pelo Super Admin (valor em JSON).
 * Leitura/escrita só através de App\Services\Subscriptions\SubscriptionSettings.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];
}
