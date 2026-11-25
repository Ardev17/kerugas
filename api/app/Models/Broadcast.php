<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envio manual do Super Admin (Setembro 2026). */
class Broadcast extends Model
{
    protected $fillable = [
        'created_by', 'audience', 'province_id', 'municipality_id', 'kind', 'title', 'body', 'path',
        'send_sms', 'sms_text', 'status', 'scheduled_at', 'started_at', 'finished_at',
        'recipients_count', 'sms_count',
    ];

    protected function casts(): array
    {
        return [
            'send_sms' => 'boolean',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
