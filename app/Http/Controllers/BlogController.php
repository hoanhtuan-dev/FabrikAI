<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\Post;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index(Request $request)
    {
        $query = Post::published()->with('author', 'category')->latest('published_at');

        if ($request->filled('q')) {
            $q = $request->input('q');
            $norm = normalize_vn($q);
            $query->where(function ($qry) use ($q, $norm) {
                $qry->where('search_text', 'like', "%{$norm}%")
                    ->orWhere('title', 'like', "%{$q}%");
            });
        }

        seo()->title('Blog | '.setting('site_name'))
            ->description('Câu chuyện, mẹo phong cách và cảm hứng từ Trillfa Fa.')
            ->canonical(route('blog.index'));

        $posts = $query->paginate(9);
        $categories = BlogCategory::active()->orderBy('sort_order')->get();
        $featured = Post::published()->where('is_featured', true)->first();

        return view('blog.index', compact('posts', 'categories', 'featured'));
    }

    public function show(Request $request, string $slug)
    {
        $post = Post::published()->with('author', 'category')->where('slug', $slug)->firstOrFail();
        $post->increment('views_count');

        seo()->title($post->title)
            ->description($post->meta_description ?? $post->excerpt)
            ->canonical(route('blog.show', $post->slug))
            ->image($post->image_url)
            ->article($post)
            ->breadcrumbs([
                ['label' => 'Trang chủ', 'url' => route('home')],
                ['label' => 'Blog', 'url' => route('blog.index')],
                ['label' => $post->title, 'url' => route('blog.show', $post->slug)],
            ]);

        $related = Post::published()->where('id', '!=', $post->id)
            ->when($post->blog_category_id, fn ($q) => $q->where('blog_category_id', $post->blog_category_id))
            ->latest()->limit(3)->get();

        return view('blog.show', compact('post', 'related'));
    }

    public function category(string $slug)
    {
        $cat = BlogCategory::active()->where('slug', $slug)->firstOrFail();
        seo()->title($cat->name.' | Blog '.setting('site_name'))
            ->description($cat->description ?: 'Bài viết chủ đề '.$cat->name.' tại Trillfa Fa.')
            ->canonical(route('blog.category', $cat->slug))
            ->breadcrumbs([
                ['label' => 'Trang chủ', 'url' => route('home')],
                ['label' => 'Blog', 'url' => route('blog.index')],
                ['label' => $cat->name, 'url' => route('blog.category', $cat->slug)],
            ]);

        $posts = Post::published()->where('blog_category_id', $cat->id)->latest('published_at')->paginate(9);
        $categories = BlogCategory::active()->orderBy('sort_order')->get();

        return view('blog.index', compact('posts', 'categories', 'cat'));
    }
}