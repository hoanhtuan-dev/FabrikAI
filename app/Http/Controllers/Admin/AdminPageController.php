<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomPage;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPageController extends Controller
{
    public function index()
    {
        $pages = CustomPage::orderByDesc('id')->get();

        return view('admin.custom-pages.index', compact('pages'));
    }

    public function create()
    {
        $page = new CustomPage(['is_active' => true, 'template' => 'landing']);
        $products = Product::active()->orderBy('name')->get();
        $categories = Category::active()->orderBy('name')->get();

        return view('admin.custom-pages.form', compact('page', 'products', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title']);
        $data['is_active'] = $request->boolean('is_active');
        $data['product_ids'] = $request->input('product_ids', []);
        $data['published_at'] = $data['published_at'] ?? now();

        if ($request->hasFile('hero_image')) {
            $data['hero_image'] = $request->file('hero_image')->store('pages', 'public');
        }

        CustomPage::create($data);

        return redirect()->route('admin.pages.index')->with('success', 'Đã tạo trang đích.');
    }

    public function edit(CustomPage $page)
    {
        $products = Product::active()->orderBy('name')->get();
        $categories = Category::active()->orderBy('name')->get();

        return view('admin.custom-pages.form', compact('page', 'products', 'categories'));
    }

    public function update(Request $request, CustomPage $page)
    {
        $data = $this->validateData($request, $page->id);

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title'], $page->id);
        $data['is_active'] = $request->boolean('is_active');
        $data['product_ids'] = $request->input('product_ids', []);

        if ($request->hasFile('hero_image')) {
            $data['hero_image'] = $request->file('hero_image')->store('pages', 'public');
        }

        $page->update($data);

        return redirect()->route('admin.pages.index')->with('success', 'Đã cập nhật trang đích.');
    }

    public function toggle(CustomPage $page)
    {
        $page->update(['is_active' => ! $page->is_active]);

        return back()->with('success', $page->is_active ? 'Đã kích hoạt trang.' : 'Đã tạm ẩn trang.');
    }

    public function destroy(CustomPage $page)
    {
        $page->delete();

        return back()->with('success', 'Đã xóa trang đích.');
    }

    protected function validateData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'hero_image' => ['nullable', 'image', 'max:4096'],
            'hero_heading' => ['nullable', 'string', 'max:255'],
            'hero_subtitle' => ['nullable', 'string', 'max:1000'],
            'hero_button_text' => ['nullable', 'string', 'max:255'],
            'hero_button_link' => ['nullable', 'string', 'max:255'],
            'hero_button_category_id' => ['nullable', 'exists:categories,id'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'template' => ['required', 'string', 'in:landing,basic'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'published_at' => ['nullable', 'date'],
        ]);

        return $data;
    }

    protected function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: Str::slug('trang-'.Str::random(6));
        $slug = $base;
        $i = 1;
        while (CustomPage::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}