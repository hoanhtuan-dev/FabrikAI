<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioOutfitSetting extends Model
{
    protected $fillable = ['user_id', 'style', 'ornament_level', 'creative_level', 'presets'];

    protected $casts = [
        'presets' => 'array',
        'ornament_level' => 'integer',
        'creative_level' => 'integer',
    ];
}
