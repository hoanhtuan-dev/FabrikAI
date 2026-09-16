<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->with('parent')->orderBy('sort_order')->get();

        return view('admin.categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:4096'],
            'icon' => ['nullable', 'string', 'max:50'],
            'icon_image' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? null, $data['name'], null);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('categories', 'public');
        }
        if ($request->hasFile('icon_image')) {
            $data['icon_image'] = $request->file('icon_image')->store('categories', 'public');
        }

        Category::create($data);

        return back()->with('success', 'Đã thêm danh mục.');
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:4096'],
            'icon' => ['nullable', 'string', 'max:50'],
            'icon_image' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? null, $data['name'], $category->id);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('categories', 'public');
        }
        if ($request->hasFile('icon_image')) {
            $data['icon_image'] = $request->file('icon_image')->store('categories', 'public');
        } elseif ($request->boolean('icon_image_remove')) {
            $data['icon_image'] = null;
        }

        $category->update($data);

        return back()->with('success', 'Đã cập nhật danh mục.');
    }

    protected function uniqueSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = $slug ?: Str::slug($name) ?: Str::slug('danh-muc-'.Str::random(5));
        $candidate = $base;
        $i = 1;
        while (Category::where('slug', $candidate)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $candidate = $base.'-'.(++$i);
        }

        return $candidate;
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return back()->with('success', 'Đã xóa danh mục.');
    }
}