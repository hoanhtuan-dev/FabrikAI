<?php

namespace App\Services;

/**
 * [Yêu cầu 2026-09-17] CẤU HÌNH GIAO DIỆN do OWNER quản lý.
 *
 * Phạm vi: thanh công cụ TRÁI (activity bar) của Studio — THỨ TỰ · NHÃN · ICON · ẨN/HIỆN.
 *
 * Vì sao chỉ chỉnh được PHẦN TRÌNH BÀY của các mục CÓ SẴN: mỗi id gắn cứng với một component
 * card (concept → SuggestCard, tryon → TryOnCard, …). Không có card thì thêm mục cũng không có
 * gì để hiển thị — nên API chỉ nhận id thuộc danh sách gốc và TỪ CHỐI id lạ (kèm thông báo rõ).
 *
 * Lưu ở bảng `settings` (khoá studio_gui_activity_bar) nên đi theo mọi bản deploy, không phụ
 * thuộc localStorage của từng máy — đúng tính chất cấu hình TOÀN CỤC.
 */
use App\Support\IconRegistry;

class StudioGuiConfig
{
    public const SETTING_KEY = 'studio_gui_activity_bar';

    /** Nhãn dài nhất cho phép — thanh công cụ chỉ rộng 56px, nhãn chỉ dùng làm tooltip/aria-label. */
    public const LABEL_MAX = 40;

    /** Bộ id HỢP LỆ + giá trị GỐC (thứ tự gốc = thứ tự ở đây). */
    public const DEFAULTS = [
        // kind='panel'  → mở NHÓM CARD ở sidebar trái (đổi activeActivity)
        ['id' => 'concept',   'kind' => 'panel',  'label' => 'Tạo ảnh',          'icon' => 'sparkles',   'visible' => true],
        ['id' => 'variation', 'kind' => 'panel',  'label' => 'Tạo biến thể ảnh', 'icon' => 'variations', 'visible' => true],
        ['id' => 'tryon',     'kind' => 'panel',  'label' => 'Mặc thử đồ',       'icon' => 'hanger',     'visible' => true],
        ['id' => 'inpaint',   'kind' => 'panel',  'label' => 'Sửa ảnh',          'icon' => 'pencil',     'visible' => true],
        ['id' => 'compose',   'kind' => 'panel',  'label' => 'Ghép ảnh',         'icon' => 'layers',     'visible' => true],
        ['id' => 'upscale',   'kind' => 'panel',  'label' => 'Upscale',          'icon' => 'maximize',   'visible' => true],
        ['id' => 'director',  'kind' => 'panel',  'label' => 'Kịch bản quay',    'icon' => 'film',       'visible' => true],
        // kind='action' → mở POPUP độc lập (không đổi panel). Hai nút này TRƯỚC ĐÂY bị bỏ sót
        // khỏi danh sách nên owner không đổi được nhãn/icon/thứ tự của chúng.
        ['id' => 'prompt',    'kind' => 'action', 'label' => 'Prompt Tạo Ảnh',   'icon' => 'sparkles',   'visible' => true],
        ['id' => 'stylist',   'kind' => 'action', 'label' => 'Trợ lý thiết kế',  'icon' => 'shirt',      'visible' => true],
        // kind='menu' → menu Cài đặt, LUÔN ghim ở ĐÁY thanh công cụ (đổi được nhãn/icon/ẩn-hiện,
        // KHÔNG đổi được vị trí — xem StudioGuiConfig::isPinned()).
        ['id' => 'settings',  'kind' => 'menu',   'label' => 'Cài đặt',          'icon' => 'gear',       'visible' => true],
    ];

    /** Id LUÔN ghim ở đáy thanh công cụ — owner đổi được nhãn/icon/ẩn-hiện nhưng không đổi vị trí. */
    public const PINNED_IDS = ['settings'];


    /** @return array<int, string> */
    public static function defaultIds(): array
    {
        return array_column(self::DEFAULTS, 'id');
    }

    /**
     * Id của các mục mở NHÓM CARD — chỉ nhóm này mới phải khớp `ACTIVITY_CARDS` trong StudioApp.
     * `action` (popup) và `menu` (menu Cài đặt) không có card nên không nằm trong bản đồ đó.
     *
     * @return array<int, string>
     */
    public static function panelIds(): array
    {
        return array_column(array_values(array_filter(self::DEFAULTS, fn ($d) => $d['kind'] === 'panel')), 'id');
    }

    /**
     * Cấu hình ĐANG dùng = mặc định ⊕ phần owner đã lưu.
     *
     * Thứ tự lấy theo bản đã lưu; id nào chưa có trong bản lưu (ví dụ mục MỚI thêm ở bản cập nhật
     * sau) được NỐI VÀO CUỐI thay vì biến mất — nếu không, nâng cấp code sẽ làm mất mục cũ của owner.
     */
    public function all(): array
    {
        $saved = $this->saved();

        if ($saved === []) {
            return $this->pinnedLast(self::DEFAULTS);
        }

        $byId = [];
        foreach (self::DEFAULTS as $d) {
            $byId[$d['id']] = $d;
        }

        $out = [];
        $seen = [];
        foreach ($saved as $row) {
            $id = (string) ($row['id'] ?? '');
            if (! isset($byId[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                // `kind` lấy từ CODE, không nhận từ client — client không được đổi HÀNH VI của nút.
                'kind' => $byId[$id]['kind'],
                'label' => (string) ($row['label'] ?? $byId[$id]['label']),
                'icon' => (string) ($row['icon'] ?? $byId[$id]['icon']),
                'visible' => (bool) ($row['visible'] ?? true),
            ];
        }

        foreach (self::DEFAULTS as $d) {
            if (! isset($seen[$d['id']])) {
                $out[] = $d;
            }
        }

        return $this->pinnedLast($out);
    }

    /**
     * Đưa các id GHIM (menu Cài đặt) xuống cuối, giữ nguyên thứ tự tương đối của phần còn lại.
     * Nhờ vậy thứ tự owner thấy trong trang quản trị ĐÚNG bằng thứ tự thật trên thanh công cụ.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function pinnedLast(array $items): array
    {
        $pinned = [];
        $rest = [];
        foreach ($items as $row) {
            if (in_array((string) ($row['id'] ?? ''), self::PINNED_IDS, true)) {
                $pinned[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return array_merge($rest, $pinned);
    }

    /**
     * Lưu cấu hình mới. Ném InvalidArgumentException kèm thông báo tiếng Việt khi dữ liệu sai.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>  cấu hình sau khi lưu
     */
    public function save(array $items): array
    {
        $valid = self::defaultIds();
        $byId = [];
        foreach (self::DEFAULTS as $d) {
            $byId[$d['id']] = $d;
        }

        $clean = [];
        $seen = [];
        foreach ($items as $i => $row) {
            if (! is_array($row)) {
                throw new \InvalidArgumentException('Mục thứ '.($i + 1).' không hợp lệ.');
            }

            $id = (string) ($row['id'] ?? '');
            if (! in_array($id, $valid, true)) {
                throw new \InvalidArgumentException(
                    'Mục "'.$id.'" không thuộc thanh công cụ. Chỉ nhận: '.implode(', ', $valid).'.'
                );
            }
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException('Mục "'.$id.'" bị lặp.');
            }
            $seen[$id] = true;

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $byId[$id]['label'];
            }
            if (mb_strlen($label) > self::LABEL_MAX) {
                throw new \InvalidArgumentException(
                    'Nhãn của "'.$id.'" dài quá '.self::LABEL_MAX.' ký tự.'
                );
            }

            $icon = (string) ($row['icon'] ?? '');
            if ($icon === '') {
                $icon = $byId[$id]['icon'];
            }
            // Icon lạ ⇒ nút render ra TRỐNG (không ai biết lỗi nằm ở cấu hình). Chặn ngay ở đây.
            if (! IconRegistry::has($icon)) {
                throw new \InvalidArgumentException('Icon "'.$icon.'" không tồn tại trong bộ icon của Studio.');
            }

            $clean[] = [
                'id' => $id,
                'kind' => $byId[$id]['kind'], // từ CODE, không nhận từ client
                'label' => $label,
                'icon' => $icon,
                'visible' => (bool) ($row['visible'] ?? true),
            ];
        }

        if ($clean === []) {
            throw new \InvalidArgumentException('Cấu hình rỗng — phải giữ ít nhất một mục.');
        }

        // Id không được gửi lên (owner chỉ gửi một phần) vẫn phải còn ⇒ nối vào cuối.
        foreach (self::DEFAULTS as $d) {
            if (! isset($seen[$d['id']])) {
                $clean[] = $d;
            }
        }

        set_setting(self::SETTING_KEY, json_encode($this->pinnedLast($clean), JSON_UNESCAPED_UNICODE));

        return $this->all();
    }

    /** Trả về đúng bản gốc trong code (bỏ mọi tuỳ chỉnh của owner). */
    public function reset(): array
    {
        set_setting(self::SETTING_KEY, '');

        return self::DEFAULTS;
    }

    /** @return array<int, array<string, mixed>> */
    private function saved(): array
    {
        $raw = (string) (setting(self::SETTING_KEY) ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
