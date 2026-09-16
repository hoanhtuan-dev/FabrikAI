<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StylistPreset extends Model
{
    protected $fillable = ['name', 'prompt', 'type', 'sort_order'];
}
