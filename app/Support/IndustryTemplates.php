<?php

namespace App\Support;

/**
 * MẪU VIỆC THEO NGÀNH (Đợt 2 — 2026-09-19).
 *
 * Vì sao: người mới mở Studio gặp một ô prompt TRỐNG và một tab "Hàng loạt" TRỐNG. Họ biết việc mình
 * cần làm ("chụp lookbook cho bộ Thu Đông", "ra ảnh đăng sàn", "gửi mẫu kỹ thuật cho xưởng") nhưng
 * không biết phải gõ gì, chọn tỉ lệ nào, cần mấy kiểu ảnh. Đó là chỗ tốn thời gian nhất của lần dùng đầu.
 *
 * Mẫu việc = một CÔNG VIỆC TRỌN VẸN kèm nguyên liệu điền sẵn: danh sách prompt cho tab Hàng loạt,
 * tỉ lệ khung, độ phân giải, và (với mẫu cho xưởng) cả bảng size + ghi chú kỹ thuật để đưa vào gói xuất.
 *
 * Nguồn DUY NHẤT: mảng dưới đây. Giao diện đọc qua `GET /api/job-templates` nên sửa/thêm mẫu ở đây là
 * mọi nơi có ngay — không có bản sao trong JS.
 */
class IndustryTemplates
{
    /** Tỉ lệ & độ phân giải hợp lệ (khớp validation của /api/generate). */
    private const RATIOS = ['1:1', '4:3', '3:4', '16:9', '9:16', '4:5', '21:9', '19:6'];

    public const TEMPLATES = [
        [
            'id' => 'lookbook',
            'icon' => 'camera',
            'title' => 'Lookbook bộ sưu tập',
            'for' => 'Nhà thiết kế',
            'hint' => '6 kiểu ảnh cho một bộ: toàn thân, chi tiết chất liệu, dáng đứng, cận đường may, nhóm, ngoài trời.',
            'ratio' => '4:5',
            'resolution' => '2K',
            'prompts' => [
                'người mẫu nữ mặc áo sơ mi linen trắng form rộng, toàn thân, phông studio xám nhạt, ánh sáng mềm',
                'cận chi tiết chất liệu linen của áo sơ mi, thấy rõ thớ vải và đường may',
                'người mẫu đứng nghiêng tay chống hông, tôn phom dáng áo, nền trắng ngà',
                'cận chi tiết cổ áo và tay áo, thấy đường may và cúc',
                'hai người mẫu đứng cạnh nhau, một mặc áo trắng một mặc áo be, tông màu hài hoà',
                'người mẫu dạo bước ngoài trời, nắng sớm, phông cây xanh mờ, phong cách lookbook thương mại',
            ],
            'export' => [
                'sizes' => "S, 84, 68, 92, 58, 56\nM, 88, 72, 96, 59, 57\nL, 92, 76, 100, 60, 58",
                'note' => 'Vải linen 100%, màu trắng ngà. Đường may 1cm, không dùng khoá kéo kim loại.',
            ],
        ],
        [
            'id' => 'ecommerce',
            'icon' => 'tag',
            'title' => 'Ảnh đăng sàn thương mại điện tử',
            'for' => 'Chủ doanh nghiệp',
            'hint' => 'Ảnh 1:1 nền sạch, sản phẩm chiếm phần lớn khung — đúng chuẩn ảnh chính của sàn.',
            'ratio' => '1:1',
            'resolution' => '2K',
            'prompts' => [
                'áo sơ mi linen trắng trải phẳng trên nền trắng tinh, sản phẩm chiếm 80% khung, ánh sáng đều không bóng đổ',
                'áo sơ mi linen trắng treo trên mắc gỗ, nền trắng, chụp chính diện',
                'cận chi tiết đường may vai và cúc áo trên nền trắng, độ nét cao',
                'áo sơ mi linen trắng gấp gọn đặt nghiêng 45 độ trên nền trắng, thấy nếp gấp tự nhiên',
            ],
            'export' => null,
        ],
        [
            'id' => 'factory',
            'icon' => 'scissors',
            'title' => 'Mẫu kỹ thuật gửi xưởng',
            'for' => 'Chủ xưởng may',
            'hint' => 'Ảnh đủ chi tiết để cắt may (mặt trước · mặt sau · đường may · chất liệu) + bảng size và ghi chú kỹ thuật điền sẵn khi xuất gói.',
            'ratio' => '3:4',
            'resolution' => '2K',
            'prompts' => [
                'áo sơ mi nam mặt trước, chụp thẳng, nền trắng, thấy rõ cổ áo, tay áo và thân áo',
                'áo sơ mi nam mặt sau, chụp thẳng, nền trắng, thấy rõ cầu vai và đường may sống lưng',
                'cận chi tiết đường may vai và nách áo, thấy rõ mật độ mũi may',
                'cận chi tiết cổ áo và bác tay, thấy độ dày vải và cách gấp nẹp',
            ],
            'export' => [
                'sizes' => "S, 96, 80, 100, 70, 58\nM, 100, 84, 104, 72, 59\nL, 104, 88, 108, 74, 60\nXL, 108, 92, 112, 76, 61",
                'note' => 'Vải cotton poplin. Vai 1cm, nách 0.8cm, gấu 2cm. Cúc nhựa 4 lỗ màu trắng, túi ngực trái.',
            ],
        ],
        [
            'id' => 'catalogue',
            'icon' => 'grid',
            'title' => 'Catalogue nhiều SKU',
            'for' => 'Chủ doanh nghiệp',
            'hint' => 'Một bối cảnh, nhiều sản phẩm — dán thêm SKU vào danh sách ở tab Hàng loạt rồi bấm tạo một lần.',
            'ratio' => '4:5',
            'resolution' => '1K',
            'prompts' => [
                'áo thun basic trắng, người mẫu đứng thẳng, nền be sáng, ánh sáng studio mềm',
                'áo polo xanh navy, người mẫu đứng thẳng, nền be sáng, ánh sáng studio mềm',
                'quần tây ống suông đen, người mẫu đứng thẳng, nền be sáng, ánh sáng studio mềm',
            ],
            'export' => null,
        ],
    ];

    /**
     * Danh sách mẫu việc cho giao diện (đã kiểm hợp lệ — không phát ra mẫu hỏng).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::TEMPLATES as $tpl) {
            $prompts = array_values(array_filter(array_map('trim', $tpl['prompts'] ?? []), fn ($p) => $p !== ''));
            if ($prompts === []) {
                continue;
            }

            $out[] = [
                'id' => (string) $tpl['id'],
                'icon' => (string) ($tpl['icon'] ?? 'sparkles'),
                'title' => (string) $tpl['title'],
                'for' => (string) ($tpl['for'] ?? ''),
                'hint' => (string) ($tpl['hint'] ?? ''),
                'ratio' => in_array($tpl['ratio'] ?? '', self::RATIOS, true) ? (string) $tpl['ratio'] : '1:1',
                'resolution' => in_array($tpl['resolution'] ?? '', ['1K', '2K'], true) ? (string) $tpl['resolution'] : '2K',
                'prompts' => $prompts,
                'export' => $tpl['export'] ? [
                    'sizes' => (string) ($tpl['export']['sizes'] ?? ''),
                    'note' => (string) ($tpl['export']['note'] ?? ''),
                ] : null,
            ];
        }

        return $out;
    }
}
