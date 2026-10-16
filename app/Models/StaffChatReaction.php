<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffChatReaction extends Model
{
    protected $fillable = ['message_id', 'user_id', 'emoji'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
