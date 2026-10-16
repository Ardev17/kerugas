<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma cópia de segurança pedida pelo Super Admin geral (ou agendada). */
class BackupRun extends Model
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'trigger', 'status', 'include_files',
        'db_file', 'db_bytes', 'db_sha256', 'files_file', 'files_bytes', 'files_count',
        'error', 'warning', 'started_at', 'finished_at', 'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'include_files' => 'boolean',
            'db_bytes' => 'integer',
            'files_bytes' => 'integer',
            'files_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true);
    }

    /** Ainda tem ficheiros que se podem descarregar. */
    public function isDownloadable(): bool
    {
        return $this->status === self::COMPLETED && $this->purged_at === null;
    }
}
