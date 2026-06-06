<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'color', 'sort_order'])]
class DevelopmentArea extends Model
{
    use HasFactory;

    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }
}
