<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Product;

class Seo
{
    public string $title = '';
    public ?string $description = null;
    public ?string $keywords = null;
    public ?string $canonical = null;
    public ?string $image = null;
    public string $type = 'website';
    public string $robots = 'index,follow';
    public array $jsonLd = [];

    public function title(?string $title): static
    {
        if ($title) {
            $this->title = $title;
        }

        return $this;
    }

    public function description(?string $description): static
    {
        if ($description) {
            $this->description = $description;
        }

        return $this;
    }

    public function keywords(?string $keywords): static
    {
        if ($keywords) {
            $this->keywords = $keywords;
        }

        return $this;
    }

    public function canonical(?string $url): static
    {
        if ($url) {
            $this->canonical = $url;
        }

        return $this;
    }

    public function image(?string $url): static
    {
        if ($url) {
            $this->image = $url;
        }

        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function robots(string $robots): static
    {
        $this->robots = $robots;

        return $this;
    }

    public function noindex(): static
    {
        $this->robots = 'noindex,nofollow';

        return $this;
    }

    public function addJsonLd(mixed $data): static
    {
        if (is_array($data) && count($data)) {
            $this->jsonLd[] = $data;
        }

        return $this;
    }

    /**
     * Fill only empty fields from defaults (run once per request via view composer).
     */
    public function applyDefaults(array $defaults): static
    {
        foreach ($defaults as $key => $value) {
            if (empty($this->{$key}) && $value !== null) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    // ---------- Structured data builders ----------

    public function organization(): static
    {
        $this->addJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => setting('site_name', 'Trillfa Fa'),
            'url' => url('/'),
            'logo' => asset('images/logo.png'),
            'image' => asset('images/logo.png'),
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => setting('site_phone', '1900 6363'),
                'contactType' => 'customer service',
                'areaServed' => 'VN',
                'availableLanguage' => 'Vietnamese',
            ],
        ]);

        return $this;
    }

    public function website(): static
    {
        $this->addJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => setting('site_name', 'Trillfa Fa'),
            'url' => url('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => route('shop.index').'?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ]);

        return $this;
    }

    public function breadcrumbs(array $items): static
    {
        $list = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [],
        ];

        foreach ($items as $i => $item) {
            $list['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['label'],
                'item' => $item['url'],
            ];
        }

        return $this->addJsonLd($list);
    }

    public function product(Product $product, ?string $url = null): static
    {
        $url = $url ?: route('product.show', $product->slug);

        $images = array_values(array_filter([$product->image_url, ...$product->gallery_urls]));

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $product->short_description ?: \Illuminate\Support\Str::limit($product->description, 200),
            'image' => $images ?: [asset('images/placeholder.svg')],
            'sku' => $product->sku,
            'brand' => ['@type' => 'Brand', 'name' => $product->brand ?: setting('site_name', 'Trillfa Fa')],
            'url' => $url,
            'offers' => [
                '@type' => 'Offer',
                'url' => $url,
                'priceCurrency' => 'VND',
                'price' => (float) $product->min_price,
                'availability' => $product->total_stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'priceValidUntil' => now()->addYear()->format('Y-m-d'),
            ],
        ];

        if ($product->rating_count > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (float) $product->rating_avg,
                'reviewCount' => (int) $product->rating_count,
            ];
        }

        $this->type('product');

        return $this->addJsonLd($data);
    }

    public function article(Post $post, ?string $url = null): static
    {
        $url = $url ?: route('blog.show', $post->slug);

        $this->addJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $post->title,
            'description' => $post->excerpt,
            'image' => [$post->image_url ?: asset('images/placeholder.svg')],
            'datePublished' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'dateModified' => ($post->updated_at ?? $post->created_at)?->toIso8601String(),
            'author' => ['@type' => 'Person', 'name' => $post->author?->name ?? 'Trillfa Fa'],
            'publisher' => [
                '@type' => 'Organization',
                'name' => setting('site_name', 'Trillfa Fa'),
                'logo' => ['@type' => 'ImageObject', 'url' => asset('images/logo.png')],
            ],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        ]);

        $this->type('article');

        return $this;
    }
}