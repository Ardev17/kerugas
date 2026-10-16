<?php

namespace App\Models;

use App\Enums\OperationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um atendimento manual iniciado por um admin/super admin na Central
 * de Operações. NÃO representa a receção automática de um SMS — ver
 * nota em database/migrations/..._create_operations_table.php.
 */
class Operation extends Model
{
    protected $fillable = [
        'admin_id', 'phone', 'province_id', 'municipality_id',
        'original_message', 'sms_template_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationStatus::class,
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(SmsDispatch::class);
    }
}
