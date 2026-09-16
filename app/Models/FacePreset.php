<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacePreset extends Model
{
    protected $fillable = ['name', 'description', 'ethnicity', 'image', 'sort', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
