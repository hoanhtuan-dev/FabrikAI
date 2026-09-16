<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'blog_category_id', 'author_id', 'title', 'slug', 'excerpt', 'body', 'image',
        'status', 'published_at', 'views_count', 'is_featured', 'tags',
        'meta_title', 'meta_description', 'search_text',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'views_count' => 'integer',
            'is_featured' => 'boolean',
            'tags' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? (str_starts_with($this->image, 'http') ? $this->image : asset('storage/'.$this->image)) : null;
    }

    protected static function booted(): void
    {
        static::saving(function (Post $post) {
            $post->search_text = normalize_vn(implode(' ', [
                $post->title,
                $post->excerpt,
                strip_tags((string) $post->body),
            ]));
        });
    }

    /**
     * Ensure tags is always an array (handles legacy comma-separated strings).
     */
    protected function tags(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->normalizeTags($value),
        );
    }

    protected function normalizeTags($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== ''));
        }

        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('trim', $decoded), fn ($v) => $v !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', trim((string) $value, '" []'))), fn ($v) => $v !== ''));
    }

    public function getReadingTimeAttribute(): int
    {
        $words = str_word_count(strip_tags((string) $this->body));
        return max(1, (int) ceil($words / 200));
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }
}