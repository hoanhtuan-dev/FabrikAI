<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = ['location', 'parent_id', 'label', 'url', 'type', 'category_id', 'custom_page_id', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function customPage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CustomPage::class, 'custom_page_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeLocation(Builder $q, string $location): Builder
    {
        return $q->where('location', $location);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function getUrl(): ?string
    {
        if ($this->type === 'category' && $this->category) {
            return route('shop.category', $this->category->slug);
        }

        if ($this->type === 'landing_page' && $this->customPage) {
            return $this->customPage->url;
        }

        return $this->url ? (str_starts_with($this->url, 'http') || str_starts_with($this->url, '/') ? $this->url : url($this->url)) : '#';
    }
}