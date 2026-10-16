<?php

namespace App\Models;

use App\Enums\SmsDispatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS enviado pela Central de Operações KeruGás (direção outbound).
 * Distinta de SmsMessage, que regista SMS recebidos dos postos.
 */
class SmsDispatch extends Model
{
    protected $fillable = [
        'provider', 'provider_message_id', 'recipient', 'sender', 'message',
        'sms_template_id', 'operation_id', 'status', 'error_code', 'error_message',
        'sent_at', 'delivered_at', 'failed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SmsDispatchStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
