<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }
}
