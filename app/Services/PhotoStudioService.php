<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * STUDIO — DỰNG KHUNG HÌNH TỪ ẢNH NGƯỜI MẪU + BỐI CẢNH (thay bản "phòng chụp shot-list" trước đó).
 *
 * LUỒNG MỚI (chốt 2026-09-22):
 *   ảnh 1 = NGƯỜI MẪU MẶC TRANG PHỤC (kết quả từ bước trước — GIỮ NGUYÊN, đây là sản phẩm)
 *   ảnh 2 = BỐI CẢNH (tùy chọn — AI đưa người mẫu vào đúng bối cảnh này)
 *   ảnh 3 = THAM CHIẾU THÊM (tùy chọn — chi tiết/phụ kiện/màu cần bám)
 *   + ô nhập prompt + CHIP NHANH lấy từ "Cài đặt của tôi" (Preset) — người dùng tự cấu hình.
 *
 * Vì sao bỏ bối cảnh/ánh sáng/ống kính/dáng theo danh mục dựng sẵn: người dùng đã có nguồn chân lý
 * riêng — PRESET trong Cài đặt của tôi (mỗi preset là cặp nhãn + đoạn chèn vào prompt, chia theo
 * nhóm Chất liệu/Phom dáng/Phong cách/Bối cảnh/Dáng/Góc máy/Ống kính…). Chip trong Studio đọc ĐÚNG
 * dữ liệu đó (kể cả phần người dùng tự thêm/sửa/ẩn) nên không còn hai nơi định nghĩa trùng nhau.
 *
 * Toàn bộ prompt do MÁY dựng — tất định, xem trước và sửa được trước khi tốn credit.
 */
class PhotoStudioService
{
    /** Trần số chip chọn một lần — tránh prompt phình to làm model bỏ qua chỉ dẫn chính. */
    public const MAX_CHIPS = 12;

    /** Tỉ lệ khung theo mục đích dùng ảnh. */
    public const RATIOS = [
        ['id' => '1:1', 'name' => '1:1 — Sàn TMĐT / feed', 'use' => 'Ảnh chính sàn, lưới Instagram'],
        ['id' => '4:5', 'name' => '4:5 — Lookbook / feed dọc', 'use' => 'Lookbook, bài đăng dọc'],
        ['id' => '3:4', 'name' => '3:4 — Catalogue', 'use' => 'Catalogue, bảng size'],
        ['id' => '9:16', 'name' => '9:16 — Story / TikTok', 'use' => 'Story, video dọc'],
        ['id' => '4:3', 'name' => '4:3 — Bối cảnh rộng', 'use' => 'Ảnh có không gian, hậu trường'],
    ];

    /** Vai trò 3 ô ảnh — id 1-based cho khớp thứ tự gửi lên model. */
    public const SLOTS = [
        ['id' => 1, 'name' => 'Người mẫu mặc trang phục', 'hint' => 'BẮT BUỘC — ảnh này được giữ nguyên 100%', 'required' => true],
        ['id' => 2, 'name' => 'Bối cảnh', 'hint' => 'Tùy chọn — AI đưa người mẫu vào đúng bối cảnh này', 'required' => false],
        ['id' => 3, 'name' => 'Tham chiếu thêm', 'hint' => 'Tùy chọn — chi tiết, phụ kiện hoặc màu cần bám', 'required' => false],
    ];

    /** Nhãn nhóm chip — PHẢI khớp CAT_LABELS của mục "Preset" trong Cài đặt của tôi. */
    private const CHIP_CATEGORY_LABELS = [
        'fabric' => 'Chất liệu',
        'silhouette' => 'Phom dáng',
        'style' => 'Phong cách',
        'background' => 'Bối cảnh',
        'pose' => 'Dáng đứng',
        'camera' => 'Góc máy',
        'lens' => 'Ống kính',
        'video_scene' => 'Kịch bản quay',
        'inpaint' => 'Sửa ảnh (Inpaint)',
    ];

    /**
     * Ghép preset DÙNG CHUNG (bảng presets) với bản của CHÍNH người dùng (user_catalogs 'presets')
     * rồi gom thành nhóm chip. Bản ghép phải GIỐNG hệt logic ở Cài đặt của tôi
     * (resources/js/studio/composables/useLocalCatalog.js — hàm merge), nếu không thì chip trong
     * Studio sẽ khác thứ người dùng nhìn thấy ở trang Cài đặt:
     *   · hidden: mục dùng chung bị người dùng ẨN;
     *   · edits : ghi đè theo id;
     *   · custom: mục người dùng tự thêm, xếp theo sort_order rồi id, nối vào CUỐI nhóm.
     *
     * @param  list<array<string, mixed>>  $baseline
     * @param  array<string, mixed>        $userCatalog
     * @return list<array{id: string, label: string, items: list<array{id: string, label: string, injection: string, note: string}>}>
     */
    public function chipGroups(array $baseline, array $userCatalog = []): array
    {
        $merged = $this->mergeCatalog($baseline, $userCatalog);

        $groups = [];
        foreach ($merged as $item) {
            $category = (string) ($item['category'] ?? '');
            $injection = trim((string) ($item['prompt_injection'] ?? ''));
            $label = trim((string) ($item['ui_label'] ?? ''));
            if ($injection === '' || $label === '') {
                continue;
            }
            if (! isset($groups[$category])) {
                $groups[$category] = [
                    'id' => $category,
                    'label' => self::CHIP_CATEGORY_LABELS[$category] ?? ($category !== '' ? $category : 'Khác'),
                    'items' => [],
                ];
            }
            $groups[$category]['items'][] = [
                'id' => (string) ($item['id'] ?? $label),
                'label' => Str::limit($label, 80, ''),
                'injection' => Str::limit($injection, 600, ''),
                'note' => Str::limit(trim((string) ($item['note'] ?? '')), 200, ''),
            ];
        }

        return array_values($groups);
    }

    /**
     * Bản đồ chip theo id để controller tra ngược từ id người dùng gửi lên ⇒ KHÔNG tin nội dung
     * client gửi, luôn dùng đúng đoạn chèn đang có trong Cài đặt của tôi.
     *
     * @return array<string, array{id: string, label: string, injection: string, category: string}>
     */
    public function chipIndex(array $baseline, array $userCatalog = []): array
    {
        $out = [];
        foreach ($this->mergeCatalog($baseline, $userCatalog) as $item) {
            $id = (string) ($item['id'] ?? '');
            $injection = trim((string) ($item['prompt_injection'] ?? ''));
            if ($id === '' || $injection === '') {
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'label' => Str::limit(trim((string) ($item['ui_label'] ?? '')), 80, ''),
                'injection' => Str::limit($injection, 600, ''),
                'category' => (string) ($item['category'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Dựng prompt hoàn chỉnh cho Studio.
     *
     * @param  array  $setup  {prompt, chips[], variants, ratio}
     * @param  array<string, array{id:string,label:string,injection:string,category:string}>  $chipIndex
     * @param  int    $imageCount  số ảnh người dùng đã chọn (1 = chỉ có ảnh người mẫu)
     */
    public function scene(array $setup, array $chipIndex = [], int $imageCount = 1, bool $imageReady = true, int $creditPerImage = 1): array
    {
        $prompt = Str::limit(trim((string) ($setup['prompt'] ?? '')), 2000, '');
        $variants = max(1, min(4, (int) ($setup['variants'] ?? 1)));
        $ratio = in_array((string) ($setup['ratio'] ?? ''), array_column(self::RATIOS, 'id'), true)
            ? (string) $setup['ratio'] : '';

        $requested = array_values(array_unique(array_map('strval', (array) ($setup['chips'] ?? []))));
        $used = [];
        $unknown = [];
        foreach ($requested as $id) {
            if (isset($chipIndex[$id])) {
                $used[] = $chipIndex[$id];
            } else {
                $unknown[] = $id;
            }
        }
        $overCap = count($used) > self::MAX_CHIPS;
        $used = array_slice($used, 0, self::MAX_CHIPS);

        return [
            'engine' => 'studio-scene-v2',
            'prompt' => $this->assemble($prompt, $used, $imageCount),
            'user_prompt' => $prompt,
            'used_chips' => array_map(fn (array $c) => [
                'id' => $c['id'],
                'label' => $c['label'],
                'category' => $c['category'],
                'category_label' => self::CHIP_CATEGORY_LABELS[$c['category']] ?? $c['category'],
                'injection' => $c['injection'],
            ], $used),
            'image_count' => $imageCount,
            'variants' => $variants,
            'ratio' => $ratio ?: null,
            'ratio_name' => $ratio ? (string) (collect(self::RATIOS)->firstWhere('id', $ratio)['name'] ?? $ratio) : null,
            'credit_per_image' => $creditPerImage,
            'total_images' => max(1, $imageCount ? 1 : 0) * $variants,
            'total_credits' => max(1, $imageCount ? 1 : 0) * $variants * $creditPerImage,
            'image_ready' => $imageReady,
            'slots' => self::SLOTS,
            'warnings' => $this->warnings($imageCount, $imageReady, $prompt, $used, $overCap, $unknown),
            'notes' => $this->notes(),
        ];
    }

    /** Prompt cuối cùng gửi cho model — dựng theo đúng thứ tự ưu tiên: giữ sản phẩm > bối cảnh > chỉ dẫn người dùng. */
    private function assemble(string $userPrompt, array $chips, int $imageCount): string
    {
        $parts = [
            'Professional fashion photograph. The FIRST image is the model wearing the garment: keep her identity, face, hair, '
            .'body proportions and pose, and keep the garment EXACTLY as it is (fabric, colour, print, cut, seams, fit) with 100% fidelity — '
            .'do NOT redesign, restyle or alter the garment, and do not change its colour.',
        ];

        if ($imageCount >= 2) {
            $parts[] = 'SCENE: place the model naturally into the setting shown in the SECOND image — match its perspective, camera height, '
                .'scale, ground contact and lighting direction so she belongs in it; keep the real architecture, materials and props of that setting.';
        } else {
            $parts[] = 'SCENE: keep the original setting of the first image unless the direction below says otherwise; if the direction asks for a new setting, '
                .'make it a clean professional fashion setting with believable light and a readable background.';
        }

        if ($imageCount >= 3) {
            $parts[] = 'EXTRA REFERENCE: the THIRD image is a supporting reference (detail, accessory or colour) — follow it faithfully where it applies.';
        }

        if ($userPrompt !== '') {
            $parts[] = 'DIRECTION: '.$userPrompt;
        }

        if ($chips !== []) {
            $parts[] = 'DETAILS: '.implode(' · ', array_map(fn (array $c) => $c['injection'], $chips)).'.';
        }

        $parts[] = 'QUALITY: photorealistic, sharp fabric texture, natural skin, believable anatomy, professional retouching, '
            .'one coherent lighting between the model and the scene. No text, no watermark, no logo, no extra limbs, no distorted hands; '
            .'garment colours must stay accurate.';

        return implode(' ', $parts);
    }

    /** @return list<array{level: string, message: string}> */
    private function warnings(int $imageCount, bool $imageReady, string $prompt, array $chips, bool $overCap, array $unknown): array
    {
        $out = [];
        if ($imageCount < 1) {
            $out[] = ['level' => 'error', 'message' => 'Cần ảnh 1: ảnh người mẫu mặc trang phục (kết quả từ bước trước).'];
        }
        if ($prompt === '' && $chips === []) {
            $out[] = ['level' => 'error', 'message' => 'Nhập prompt hoặc chọn ít nhất một chip nhanh để AI biết cần làm gì.'];
        }
        if (! $imageReady) {
            $out[] = ['level' => 'warning', 'message' => 'Chưa cấu hình model tạo/sửa ảnh (nhóm “edit”) — kết quả sẽ là ẢNH MẪU (chế độ demo), không phải ảnh do AI tạo.'];
        }
        if ($overCap) {
            $out[] = ['level' => 'warning', 'message' => 'Chỉ dùng tối đa '.self::MAX_CHIPS.' chip một lần — các chip thừa đã bị bỏ qua.'];
        }
        if ($unknown !== []) {
            $out[] = ['level' => 'info', 'message' => count($unknown).' chip không còn tồn tại trong Cài đặt của tôi (có thể đã bị xoá hoặc ẩn) — đã bỏ qua.'];
        }
        if ($imageCount === 1 && $prompt !== '') {
            $out[] = ['level' => 'info', 'message' => 'Chưa có ảnh bối cảnh: AI sẽ giữ nguyên khung hình gốc và chỉ làm theo prompt.'];
        }

        return $out;
    }

    /** @return list<string> */
    private function notes(): array
    {
        return [
            'Chip nhanh lấy từ PRESET trong «Cài đặt của tôi» — sửa/thêm/ẩn ở đó là chip trong Studio đổi theo.',
            'Ảnh 1 luôn là sản phẩm: AI chỉ đổi bối cảnh/ánh sáng theo chỉ dẫn, không thiết kế lại trang phục.',
            'Ảnh 2 (bối cảnh) nên là nơi có thật — AI bám vào phối cảnh, mặt đất và hướng sáng của ảnh đó.',
        ];
    }

    /**
     * Ghép baseline ⊕ bản của người dùng — giống hệt useLocalCatalog.merge() ở giao diện.
     *
     * @return list<array<string, mixed>>
     */
    private function mergeCatalog(array $baseline, array $userCatalog): array
    {
        $hidden = [];
        foreach ((array) ($userCatalog['hidden'] ?? []) as $id) {
            $hidden[(string) $id] = true;
        }
        $edits = (array) ($userCatalog['edits'] ?? []);
        $custom = array_values(array_filter((array) ($userCatalog['custom'] ?? []), 'is_array'));

        $out = [];
        foreach ($baseline as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($hidden[$id])) {
                continue;
            }
            $out[] = isset($edits[$id]) && is_array($edits[$id]) ? array_merge($row, $edits[$id]) : $row;
        }

        usort($custom, fn (array $a, array $b) => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0)
            ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));

        return array_merge($out, $custom);
    }

    public function ratioOptions(): array
    {
        return self::RATIOS;
    }
}
