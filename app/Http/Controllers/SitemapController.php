<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = [];

        $urls[] = ['loc' => url('/'), 'priority' => '1.0', 'freq' => 'daily'];
        $urls[] = ['loc' => route('shop.index'), 'priority' => '0.9', 'freq' => 'daily'];
        $urls[] = ['loc' => route('blog.index'), 'priority' => '0.8', 'freq' => 'daily'];

        foreach (['page.about', 'page.contact', 'page.faq', 'page.privacy', 'page.terms'] as $route) {
            $urls[] = ['loc' => route($route), 'priority' => '0.4', 'freq' => 'monthly'];
        }

        Category::active()->get()->each(function ($c) use (&$urls) {
            $urls[] = ['loc' => route('shop.category', $c->slug), 'priority' => '0.7', 'freq' => 'weekly'];
        });

        Product::active()->get()->each(function ($p) use (&$urls) {
            $urls[] = [
                'loc' => route('product.show', $p->slug),
                'priority' => '0.8',
                'freq' => 'weekly',
                'lastmod' => $p->updated_at?->toAtomString(),
            ];
        });

        Post::published()->get()->each(function ($post) use (&$urls) {
            $urls[] = [
                'loc' => route('blog.show', $post->slug),
                'priority' => '0.6',
                'freq' => 'monthly',
                'lastmod' => $post->updated_at?->toAtomString(),
            ];
        });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."
".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."
";
        foreach ($urls as $u) {
            $xml .= '  <url>'."
";
            $xml .= '    <loc>'.htmlspecialchars($u['loc']).'</loc>'."
";
            if (! empty($u['lastmod'])) {
                $xml .= '    <lastmod>'.$u['lastmod'].'</lastmod>'."
";
            }
            $xml .= '    <changefreq>'.$u['freq'].'</changefreq>'."
";
            $xml .= '    <priority>'.$u['priority'].'</priority>'."
";
            $xml .= '  </url>'."
";
        }
        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
