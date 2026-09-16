<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Preset extends Model
{
    use HasFactory;

    protected $fillable = ['category', 'ui_label', 'prompt_injection', 'note', 'sort_order'];

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category)->orderBy('sort_order');
    }
}
