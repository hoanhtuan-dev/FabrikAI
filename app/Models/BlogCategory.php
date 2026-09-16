<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'image', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? (str_starts_with($this->image, 'http') ? $this->image : asset('storage/'.$this->image)) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
