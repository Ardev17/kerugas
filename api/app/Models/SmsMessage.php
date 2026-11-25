<?php

namespace App\Models;

use App\Enums\SmsMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $fillable = [
        'provider_message_id', 'sender', 'recipient', 'body', 'parsed_command',
        'status', 'station_id', 'user_id', 'received_at', 'processed_at',
        'error', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SmsMessageStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
