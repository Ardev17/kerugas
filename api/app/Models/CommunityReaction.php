<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityReaction extends Model
{
    /** Reações permitidas (lista fechada). */
    public const EMOJIS = ['👍', '❤️', '😂', '🙏'];

    protected $fillable = ['message_id', 'user_id', 'emoji'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
