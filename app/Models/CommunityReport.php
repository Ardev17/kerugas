<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityReport extends Model
{
    /** Motivos de denúncia (chave => texto). */
    public const REASONS = [
        'spam' => 'Spam ou publicidade',
        'abuse' => 'Insulto ou assédio',
        'scam' => 'Burla ou engano',
        'illegal' => 'Conteúdo ilegal',
        'personal' => 'Dados pessoais de terceiros',
    ];

    /** Denúncias de pessoas diferentes que escondem a mensagem até revisão. */
    public const AUTO_HIDE_AT = 3;

    protected $fillable = ['message_id', 'user_id', 'reason', 'details', 'resolved_at', 'resolved_by'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunityMessage::class, 'message_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
