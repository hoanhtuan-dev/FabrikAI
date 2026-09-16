<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CustomPage;
use App\Models\MenuItem;
use Illuminate\Http\Request;

class AdminMenuController extends Controller
{
    public function index()
    {
        $headerMenu = MenuItem::location('header')->orderBy('sort_order')->orderBy('id')->get();
        $footerMenu = MenuItem::location('footer')->orderBy('sort_order')->orderBy('id')->get();

        $categories = Category::active()->with('children', fn ($q) => $q->active())->orderBy('sort_order')->get();

        $pages = [
            ['label' => 'Trang chủ', 'url' => '/'],
            ['label' => 'Cửa hàng', 'url' => '/shop'],
            ['label' => 'Blog', 'url' => '/blog'],
            ['label' => 'Giới thiệu', 'url' => '/gioi-thieu'],
            ['label' => 'Liên hệ', 'url' => '/lien-he'],
            ['label' => 'Câu hỏi thường gặp', 'url' => '/hoi-dap'],
            ['label' => 'Chính sách bảo mật', 'url' => '/chinh-sach-bao-mat'],
            ['label' => 'Điều khoản sử dụng', 'url' => '/dieu-khoan'],
        ];

        $customPages = CustomPage::active()->orderBy('title')->get();

        return view('admin.menu.index', compact('headerMenu', 'footerMenu', 'categories', 'pages', 'customPages'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['sort_order'] = (int) MenuItem::where('location', $data['location'])
            ->where('parent_id', $data['parent_id'])->max('sort_order') + 1;

        MenuItem::create($data);

        return back()->with('success', 'Đã thêm mục menu.');
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $data = $this->validated($request);
        $menuItem->update($data);

        return back()->with('success', 'Đã cập nhật mục menu.');
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();

        return back()->with('success', 'Đã xóa mục menu.');
    }

    public function toggleActive(MenuItem $menuItem)
    {
        $menuItem->update(['is_active' => ! $menuItem->is_active]);

        return back()->with('success', 'Đã cập nhật trạng thái.');
    }

    public function moveUp(MenuItem $menuItem)
    {
        $prev = MenuItem::where('location', $menuItem->location)
            ->where('parent_id', $menuItem->parent_id)
            ->where('sort_order', '<', $menuItem->sort_order)
            ->orderByDesc('sort_order')->first();

        if ($prev) {
            $this->swapSort($menuItem, $prev);
        }

        return back()->with('success', 'Đã di chuyển lên.');
    }

    public function moveDown(MenuItem $menuItem)
    {
        $next = MenuItem::where('location', $menuItem->location)
            ->where('parent_id', $menuItem->parent_id)
            ->where('sort_order', '>', $menuItem->sort_order)
            ->orderBy('sort_order')->first();

        if ($next) {
            $this->swapSort($menuItem, $next);
        }

        return back()->with('success', 'Đã di chuyển xuống.');
    }

    protected function swapSort(MenuItem $a, MenuItem $b): void
    {
        [$a->sort_order, $b->sort_order] = [$b->sort_order, $a->sort_order];
        $a->save();
        $b->save();
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'location' => ['required', 'in:header,footer'],
            'label' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:custom,category,page,landing_page'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'custom_page_id' => ['nullable', 'integer', 'exists:custom_pages,id'],
            'parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['parent_id'] = $data['parent_id'] ?? null;
        $data['type'] = $data['type'] ?? 'custom';
        $data['category_id'] = $data['category_id'] ?? null;
        $data['custom_page_id'] = $data['custom_page_id'] ?? null;
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}