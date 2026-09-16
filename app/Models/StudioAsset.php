<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioAsset extends Model
{
    protected $fillable = ['type', 'name', 'path', 'sort'];
}
