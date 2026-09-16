<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosePreset extends Model
{
    protected $fillable = ['name', 'description', 'image', 'sort', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
