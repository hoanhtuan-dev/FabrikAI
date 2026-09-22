<?php

namespace App\Support;

use App\Models\User;

/**
 * DANH SÁCH KHU của trang hợp nhất "Cài đặt & Quản trị" — MỘT NGUỒN CHÂN LÝ.
 *
 * [2026-09-26 · đợt 24] Trước đây mỗi khu là một trang riêng, mỗi trang tự khai menu của nó. Sau khi
 * gộp, danh sách khu được khai Ở ĐÚNG MỘT CHỖ (lớp này) và cả hai phía cùng đọc:
 *   · Blade  → resources/views/studio/partials/hub-bar.blade.php (thanh cho trang MÁY CHỦ render:
 *              /he-thong-thiet-ke · /bao-cao-nhom — vẫn chạy được khi JS hỏng);
 *   · Vue    → SettingsHubApp.vue đọc JSON từ thuộc tính data-areas (thanh cho các khu SPA).
 * Thêm/bớt khu = sửa DUY NHẤT file này; hai thanh tự khớp, không có danh sách thứ hai để lệch.
 *
 * `ownerOnly`: khu chỉ owner. Lưu ý: đây là chuyện HIỂN THỊ — máy chủ vẫn chặn thật bằng middleware
 * auth+admin trên /settings, /admin, /he-thong-thiet-ke, /bao-cao-nhom.
 * `spa`: true nếu khu được render trong trang hợp nhất (đổi khu TẠI CHỖ, không nạp lại trang);
 *        false nếu khu là trang máy chủ render (bấm là điều hướng thật).
 */
final class SettingsAreas
{
    public const MINE = 'mine';
    public const SYSTEM = 'system';
    public const ADMIN = 'admin';
    public const DESIGN = 'design';
    public const COSTS = 'costs';

    /** @return array<int, array{id:string,label:string,short:string,href:string,icon:string,desc:string,ownerOnly:bool,spa:bool}> */
    public static function all(): array
    {
        return [
            [
                'id' => self::MINE,
                'label' => 'Cài đặt của tôi',
                'short' => 'Của tôi',
                'href' => '/cai-dat',
                'icon' => 'user',
                'desc' => 'Preset · khuôn mặt · dáng pose · trợ lý thiết kế · giao diện — dữ liệu riêng của bạn.',
                'ownerOnly' => false,
                'spa' => true,
            ],
            [
                'id' => self::SYSTEM,
                'label' => 'Cài đặt hệ thống',
                'short' => 'Hệ thống',
                'href' => '/settings',
                'icon' => 'gear',
                'desc' => 'Nhà cung cấp AI · API key · model — áp dụng cho mọi tài khoản FabrikAI.',
                'ownerOnly' => true,
                'spa' => true,
            ],
            [
                'id' => self::ADMIN,
                'label' => 'Quản trị',
                'short' => 'Quản trị',
                'href' => '/admin',
                'icon' => 'shieldCheck',
                'desc' => 'Người dùng · gói cước · sổ credit · giao diện Studio — thao tác ảnh hưởng toàn hệ thống.',
                'ownerOnly' => true,
                'spa' => true,
            ],
            [
                'id' => self::DESIGN,
                'label' => 'Hệ thống thiết kế',
                'short' => 'Thiết kế',
                'href' => '/he-thong-thiet-ke',
                'icon' => 'palette',
                'desc' => 'Thư viện theme daisyUI · bảng token hai chế độ — số liệu đo từ chính mã nguồn.',
                'ownerOnly' => true,
                'spa' => false,
            ],
            [
                'id' => self::COSTS,
                'label' => 'Chi phí theo nhóm',
                'short' => 'Chi phí',
                'href' => '/bao-cao-nhom',
                'icon' => 'activity',
                'desc' => 'Ảnh và credit cộng từ chính bảng generations — báo cáo không thể lệch với thực tế.',
                'ownerOnly' => true,
                'spa' => false,
            ],
        ];
    }

    /** Khu mà người dùng NÀY được thấy trên thanh chung. */
    public static function visibleFor(?User $user): array
    {
        $owner = (bool) ($user?->isAdmin());

        return array_values(array_filter(self::all(), fn (array $a): bool => ! $a['ownerOnly'] || $owner));
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::all(), 'id');
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $area) {
            if ($area['id'] === $id) {
                return $area;
            }
        }

        return null;
    }

    /** Giá trị lạ trên URL rơi về khu "Cài đặt của tôi" thay vì để trang không có khu nào. */
    public static function normalize(string $id): string
    {
        return in_array($id, self::ids(), true) ? $id : self::MINE;
    }
}
