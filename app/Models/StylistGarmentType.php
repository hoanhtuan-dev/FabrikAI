<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StylistGarmentType extends Model
{
    protected $fillable = ['slug', 'name', 'emoji', 'color', 'sort_order'];
}
