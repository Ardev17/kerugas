<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunityBannedWord extends Model
{
    protected $fillable = ['word', 'created_by'];
}
