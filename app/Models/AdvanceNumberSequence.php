<?php

namespace App\Models;

use Database\Factories\AdvanceNumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdvanceNumberSequence extends Model
{
    /** @use HasFactory<AdvanceNumberSequenceFactory> */
    use HasFactory;

    protected $fillable = ['period', 'last_number'];
}
