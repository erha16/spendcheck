<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'description', 'starts_at', 'ends_at', 'is_active'])]
class Activity extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'is_active' => 'boolean'];
    }
}
