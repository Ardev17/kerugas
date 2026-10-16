<?php

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationComment extends Model
{
    use HasFactory;

    protected $fillable = ['station_id', 'user_id', 'content', 'status'];

    protected function casts(): array
    {
        return ['status' => CommentStatus::class];
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
