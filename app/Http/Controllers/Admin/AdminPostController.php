<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPostController extends Controller
{
    public function index(Request $request)
    {
        $query = Post::with('author', 'category')->latest();

        if ($request->filled('q')) {
            $query->where('title', 'like', '%'.$request->input('q').'%');
        }

        $posts = $query->paginate(15)->withQueryString();

        return view('admin.posts.index', compact('posts'));
    }

    public function create()
    {
        $categories = BlogCategory::active()->orderBy('sort_order')->get();

        return view('admin.posts.form', compact('categories'))->with('post', new Post());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $data['slug'] = Str::slug($data['title']).'-'.substr(uniqid(), -4);
        $data['author_id'] = auth()->id();
        $data['published_at'] = $data['published_at'] ?? now();
        $data['tags'] = $this->parseTags($data['tags'] ?? null);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('posts', 'public');
        }

        Post::create($data);

        return redirect()->route('admin.posts.index')->with('success', 'Đã tạo bài viết.');
    }

    public function preview(Post $post)
    {
        $post->load('author', 'category');

        $related = Post::published()->where('id', '!=', $post->id)
            ->when($post->blog_category_id, fn ($q) => $q->where('blog_category_id', $post->blog_category_id))
            ->latest()->limit(3)->get();

        return view('blog.show', compact('post', 'related'));
    }

    public function edit(Post $post)
    {
        $categories = BlogCategory::active()->orderBy('sort_order')->get();

        return view('admin.posts.form', compact('categories', 'post'));
    }

    public function update(Request $request, Post $post)
    {
        $data = $this->validated($request);

        $data['tags'] = $this->parseTags($data['tags'] ?? null);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('posts', 'public');
        }

        $post->update($data);

        return redirect()->route('admin.posts.index')->with('success', 'Đã cập nhật bài viết.');
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return back()->with('success', 'Đã xóa bài viết.');
    }

    protected function parseTags($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'blog_category_id' => ['nullable', 'exists:blog_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:4096'],
            'status' => ['required', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            'is_featured' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ]);
    }
}