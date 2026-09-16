<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id', 'name', 'slug', 'sku', 'brand', 'short_description', 'description',
        'price', 'compare_price', 'cost_price', 'stock', 'weight', 'featured', 'is_active',
        'sales_count', 'rating_avg', 'rating_count', 'image', 'gallery', 'attributes',
        'tags', 'meta_title', 'meta_description', 'views_count', 'search_text',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'weight' => 'decimal:2',
            'rating_avg' => 'decimal:2',
            'featured' => 'boolean',
            'is_active' => 'boolean',
            'sales_count' => 'integer',
            'rating_count' => 'integer',
            'views_count' => 'integer',
            'gallery' => 'array',
            'attributes' => 'array',
            'tags' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true)->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('is_active', true);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->search_text = normalize_vn(implode(' ', [
                $product->name,
                $product->short_description,
                strip_tags((string) $product->description),
                $product->brand,
                $product->sku,
            ]));
        });
    }

    // ---------- Accessors ----------

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return asset_image($this->image);
    }

    public function getGalleryUrlsAttribute(): array
    {
        $urls = [];
        foreach ((array) $this->gallery as $img) {
            $urls[] = asset_image($img);
        }

        return $urls;
    }

    public function getTotalStockAttribute(): int
    {
        if ($this->variants->isNotEmpty()) {
            return (int) $this->variants->sum('stock');
        }

        return (int) $this->stock;
    }

    public function getHasVariantsAttribute(): bool
    {
        return $this->variants->isNotEmpty();
    }

    public function getFirstVariantAttribute(): ?ProductVariant
    {
        return $this->variants->first();
    }

    public function getMinPriceAttribute(): float
    {
        if ($this->variants->isNotEmpty()) {
            return (float) $this->variants->min('price');
        }

        return (float) $this->price;
    }

    public function getMaxPriceAttribute(): float
    {
        if ($this->variants->isNotEmpty()) {
            return (float) $this->variants->max('price');
        }

        return (float) $this->price;
    }

    public function getInSaleAttribute(): bool
    {
        $ref = $this->variants->isNotEmpty() ? $this->variants->min('compare_price') ?? $this->variants->min('price') : $this->compare_price;
        $price = $this->variants->isNotEmpty() ? $this->variants->min('price') : $this->price;

        return $ref && $ref > $price;
    }

    public function getDiscountPercentAttribute(): int
    {
        $price = (float) $this->min_price;
        $ref = $this->variants->isNotEmpty()
            ? (float) ($this->variants->where('compare_price', '>', 0)->min('compare_price') ?: $this->variants->min('price'))
            : (float) $this->compare_price;

        if (! $ref || $ref <= $price) {
            return 0;
        }

        return (int) round((1 - $price / $ref) * 100);
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

    // ---------- Scopes ----------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('stock', '>', 0)
                ->orWhereHas('variants', fn ($v) => $v->where('stock', '>', 0));
        });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $term = trim($term);
        $norm = normalize_vn($term);

        return $query->where(function ($q) use ($term, $norm) {
            $q->where('search_text', 'like', "%{$norm}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('brand', 'like', "%{$term}%");
        });
    }
}