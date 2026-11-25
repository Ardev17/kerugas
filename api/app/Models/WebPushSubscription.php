<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Navegador inscrito para Web Push (VAPID), Setembro 2026. */
class WebPushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'personal_access_token_id', 'endpoint', 'endpoint_hash',
        'public_key', 'auth_token', 'content_encoding', 'user_agent',
    ];
}
