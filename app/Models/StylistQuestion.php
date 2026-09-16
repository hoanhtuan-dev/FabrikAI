<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StylistQuestion extends Model
{
    protected $fillable = ['key', 'question', 'options', 'sort_order'];

    protected $casts = ['options' => 'array'];
}
