<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminWidgetController extends Controller
{
    /**
     * Widget definitions: enable/disable + optional item limit + editable content fields.
     */
    protected function widgets(): array
    {
        return [
            'announcement' => [
                'label' => 'Thanh thông báo (announcement bar)',
                'has_limit' => false,
                'fields' => [
                    ['key' => 'text', 'label' => 'Nội dung thông báo', 'type' => 'text', 'default' => 'Miễn phí vận chuyển cho đơn hàng từ 500.000đ'],
                ],
            ],
            'newsletter' => [
                'label' => 'Đăng ký nhận bản tin (Newsletter)',
                'has_limit' => false,
                'fields' => [
                    ['key' => 'kicker', 'label' => 'Nhãn nhỏ', 'type' => 'text', 'default' => 'Nhận ưu đãi độc quyền'],
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Đăng ký nhận bản tin Trillfa Fa'],
                    ['key' => 'subtitle', 'label' => 'Mô tả', 'type' => 'textarea', 'default' => 'Thông tin mới nhất về bộ sưu tập, khuyến mãi và ưu đãi thành viên.'],
                    ['key' => 'button_text', 'label' => 'Chữ nút', 'type' => 'text', 'default' => 'Đăng ký'],
                ],
            ],
            'hero' => ['label' => 'Hero slider', 'has_limit' => false],
            'secondary' => ['label' => 'Banner phụ', 'has_limit' => false],
            'categories' => [
                'label' => 'Khám phá theo danh mục',
                'has_limit' => false,
                'fields' => [
                    ['key' => 'kicker', 'label' => 'Nhãn nhỏ', 'type' => 'text', 'default' => 'Danh mục'],
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Khám phá theo danh mục'],
                    ['key' => 'link_text', 'label' => 'Chữ liên kết', 'type' => 'text', 'default' => 'Xem tất cả'],
                ],
            ],
            'featured' => [
                'label' => 'Sản phẩm nổi bật',
                'has_limit' => true, 'default_limit' => 8,
                'fields' => [
                    ['key' => 'kicker', 'label' => 'Nhãn nhỏ', 'type' => 'text', 'default' => 'Tuyển chọn'],
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Sản phẩm nổi bật'],
                    ['key' => 'link_text', 'label' => 'Chữ liên kết', 'type' => 'text', 'default' => 'Xem tất cả'],
                ],
            ],
            'new' => [
                'label' => 'Hàng mới nhất',
                'has_limit' => true, 'default_limit' => 8,
                'fields' => [
                    ['key' => 'kicker', 'label' => 'Nhãn nhỏ', 'type' => 'text', 'default' => 'Mới về'],
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Hàng mới nhất'],
                    ['key' => 'link_text', 'label' => 'Chữ liên kết', 'type' => 'text', 'default' => 'Xem tất cả'],
                ],
            ],
            'bestsellers' => [
                'label' => 'Bán chạy',
                'has_limit' => true, 'default_limit' => 8,
                'fields' => [
                    ['key' => 'kicker', 'label' => 'Nhãn nhỏ', 'type' => 'text', 'default' => 'Bán chạy'],
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Được yêu thích nhất'],
                    ['key' => 'link_text', 'label' => 'Chữ liên kết', 'type' => 'text', 'default' => 'Xem tất cả'],
                ],
            ],
            'sale' => [
                'label' => 'Ưu đãi / Sale',
                'has_limit' => true, 'default_limit' => 8,
                'fields' => [
                    ['key' => 'kicker', 'label' => 'Nhãn nhỏ', 'type' => 'text', 'default' => 'Deal hot'],
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Ưu đãi đặc biệt &mdash; giảm sâu'],
                ],
            ],
            'blog' => [
                'label' => 'Blog preview',
                'has_limit' => false,
                'fields' => [
                    ['key' => 'kicker', 'label' => 'Nhãn nhỏ', 'type' => 'text', 'default' => 'Blog'],
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Câu chuyện & Phong cách'],
                    ['key' => 'link_text', 'label' => 'Chữ liên kết', 'type' => 'text', 'default' => 'Đọc tất cả'],
                ],
            ],
            'cta' => [
                'label' => 'Khối CTA (kêu gọi hành động)',
                'has_limit' => false,
                'fields' => [
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'default' => 'Sẵn sàng nâng cấp phong cách của bạn?'],
                    ['key' => 'subtitle', 'label' => 'Mô tả', 'type' => 'textarea', 'default' => 'Khám phá bộ sưu tập mới nhất và tận hưởng ưu đãi hấp dẫn dành riêng cho bạn.'],
                    ['key' => 'button_text', 'label' => 'Chữ nút', 'type' => 'text', 'default' => 'Mua sắm ngay'],
                    ['key' => 'button_link', 'label' => 'Liên kết nút', 'type' => 'text', 'default' => '/shop'],
                ],
            ],
        ];
    }

    public function index()
    {
        $widgets = collect($this->widgets())->map(function ($def, $key) {
            $def['key'] = $key;
            $def['enabled'] = widget_enabled($key, true);
            $def['limit'] = widget_limit($key, $def['default_limit'] ?? 8);

            // Attach editable content values.
            $values = [];
            foreach ($def['fields'] ?? [] as $field) {
                $values[$field['key']] = setting('widget_'.$key.'_'.$field['key'], $field['default'] ?? '');
            }
            $def['values'] = $values;

            return $def;
        })->values();

        return view('admin.widgets.index', compact('widgets'));
    }

    public function update(Request $request)
    {
        foreach ($this->widgets() as $key => $def) {
            set_setting('widget_'.$key.'_enabled', $request->boolean('enabled_'.$key, true) ? '1' : '0');

            if ($def['has_limit'] ?? false) {
                $limit = max(1, min(24, (int) $request->input('limit_'.$key, $def['default_limit'] ?? 8)));
                set_setting('widget_'.$key.'_limit', $limit);
            }

            foreach ($def['fields'] ?? [] as $field) {
                $value = $request->input('widget_'.$key.'_'.$field['key'], $field['default'] ?? '');
                set_setting('widget_'.$key.'_'.$field['key'], trim((string) $value));
            }
        }

        return back()->with('success', 'Đã lưu cài đặt widget.');
    }
}
