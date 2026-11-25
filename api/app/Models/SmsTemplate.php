<?php

namespace App\Models;

use App\Enums\SmsTemplateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsTemplate extends Model
{
    protected $fillable = [
        'name', 'code', 'description', 'content', 'type', 'active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => SmsTemplateType::class,
            'active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
