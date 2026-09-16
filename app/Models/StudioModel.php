<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioModel extends Model
{
    protected $table = 'studio_models';
    protected $fillable = ['group', 'name', 'provider', 'model_id', 'api_key_ref', 'priority', 'enabled', 'note'];
    protected $casts = ['enabled' => 'boolean'];
}
