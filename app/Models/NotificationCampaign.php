<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Afinações de uma campanha feitas pelo Super Admin. A definição e os
 * textos por omissão vivem em App\Services\Campaigns\CampaignCatalog.
 */
class NotificationCampaign extends Model
{
    protected $fillable = ['key', 'enabled', 'sms_enabled', 'templates', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'templates' => 'array',
        ];
    }
}
