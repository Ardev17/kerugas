<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDelivery extends Model
{
    protected $fillable = [
        'campaign_key', 'broadcast_id', 'user_id', 'dedupe_key', 'variant', 'marketing',
        'app_notification_id', 'sms_status', 'converted_order_id', 'converted_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'marketing' => 'boolean',
            'converted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
