<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = [
        'name', 'contact', 'message', 'status', 'ip_address', 'user_agent',
    ];
}
