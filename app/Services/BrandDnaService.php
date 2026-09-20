<?php

namespace App\Services;

use App\Models\BrandDna;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DNA THƯƠNG HIỆU — hồ sơ do CHÍNH chủ shop khai, dùng cho cả TrendRadar và CollectionBot.
 *
 * Vì sao cần (2026-09-23): trước đây DNA chỉ được SUY RA (đếm project/generation + dò từ khoá trong
 * prompt; không có dữ liệu bán hàng thì rơi về câu mặc định cứng). Hệ quả thật: model nhận một DNA có
 * thể sai hẳn định vị của shop mà chủ shop KHÔNG có cách nào sửa — câu trả lời vì thế chỉ "nghe hợp lý".
 *
 * Ba nguyên tắc:
 *   1. DNA chủ shop khai LUÔN được ưu tiên hơn phần suy ra, và hai phần được TRẢ VỀ RIÊNG (không trộn)
 *      để giao diện nói thật cái nào do người dùng khai, cái nào do hệ thống đoán.
 *   2. Mọi trường đều có TRẦN độ dài và số mục — dữ liệu này đi thẳng vào prompt nên không được phình.
 *   3. Một nguồn khai báo hình dạng (TEXT_FIELDS · LIST_FIELDS · PRICE_BANDS) dùng CHUNG cho validate ở
 *      controller, chuẩn hoá ở đây và giao diện — thêm trường mới chỉ sửa một chỗ.
 */
class BrandDnaService
{
    /** Dải giá: rỗng = CHƯA KHAI (không đoán hộ). */
    public const PRICE_BANDS = ['', 'entry', 'mid', 'premium'];

    public const PRICE_BAND_LABELS = [
        '' => 'Chưa khai',
        'entry' => 'Bình dân',
        'mid' => 'Trung cấp',
        'premium' => 'Cao cấp',
    ];

    /** Trường văn bản: tên => trần ký tự. */
    public const TEXT_FIELDS = [
        'positioning' => 200,
        'customer' => 200,
        'notes' => 500,
    ];

    /** Trường danh sách: tên => [trần số mục, trần ký tự mỗi mục]. */
    public const LIST_FIELDS = [
        'styles' => [8, 40],
        'colors' => [8, 40],
        'categories' => [10, 60],
        'materials' => [8, 40],
        'avoid' => [8, 60],
    ];

    /** Nhãn tiếng Việt — giao diện và câu tóm tắt dùng CHUNG một nguồn. */
    public const FIELD_LABELS = [
        'positioning' => 'Định vị',
        'customer' => 'Khách hàng mục tiêu',
        'price_band' => 'Dải giá',
        'styles' => 'Phong cách',
        'colors' => 'Màu chủ đạo',
        'categories' => 'Nhóm hàng chính',
        'materials' => 'Chất liệu',
        'avoid' => 'Không làm',
        'notes' => 'Ghi chú thêm',
    ];

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        $out = ['price_band' => ''] + array_fill_keys(array_keys(self::TEXT_FIELDS), '');
        foreach (array_keys(self::LIST_FIELDS) as $field) {
            $out[$field] = [];
        }

        return $out;
    }

    /**
     * Chuẩn hoá dữ liệu thô (từ DB hoặc từ request) về ĐÚNG hình dạng đã khai.
     *
     * KHÔNG ném lỗi: dữ liệu cũ/hỏng vẫn phải đọc được. Trường thiếu bù rỗng, giá trị vượt trần bị
     * cắt, danh sách được làm sạch + bỏ trùng và GIỮ thứ tự người dùng nhập.
     *
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    public static function normalize($raw): array
    {
        $out = self::empty();
        if (! is_array($raw)) {
            return $out;
        }

        foreach (self::TEXT_FIELDS as $field => $limit) {
            $out[$field] = Str::limit(trim((string) ($raw[$field] ?? '')), $limit, '');
        }

        $band = (string) ($raw['price_band'] ?? '');
        $out['price_band'] = in_array($band, self::PRICE_BANDS, true) ? $band : '';

        foreach (self::LIST_FIELDS as $field => [$maxItems, $maxChars]) {
            $items = [];
            foreach ((array) ($raw[$field] ?? []) as $item) {
                if (! is_scalar($item)) {
                    continue;
                }
                $value = Str::limit(trim((string) $item), $maxChars, '');
                if ($value === '' || in_array($value, $items, true)) {
                    continue;
                }
                $items[] = $value;
                if (count($items) >= $maxItems) {
                    break;
                }
            }
            $out[$field] = $items;
        }

        return $out;
    }

    /** Hồ sơ có nội dung gì không? (mọi trường rỗng = chưa khai) */
    public static function isEmpty(array $dna): bool
    {
        foreach ($dna as $value) {
            if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Trần số mục của một trường danh sách (giao diện dùng để chặn nhập thêm). */
    public static function listMax(string $field): int
    {
        return self::LIST_FIELDS[$field][0] ?? 0;
    }

    /**
     * Hồ sơ DNA của người dùng + trạng thái để giao diện nói THẬT.
     *
     * @return array{data: array<string, mixed>, is_set: bool, updated_at: ?string, summary: string}
     */
    public function get(?User $user): array
    {
        if (! $user) {
            return ['data' => self::empty(), 'is_set' => false, 'updated_at' => null, 'summary' => ''];
        }

        $row = BrandDna::query()->where('user_id', $user->id)->first();
        $data = self::normalize($row?->data);

        return [
            'data' => $data,
            'is_set' => ! self::isEmpty($data),
            'updated_at' => $row?->updated_at?->toISOString(),
            'summary' => $this->summary($data),
        ];
    }

    /**
     * Lưu hồ sơ DNA của CHÍNH người dùng đang đăng nhập (upsert theo user_id).
     *
     * @param  array<string, mixed>  $raw
     * @return array{data: array<string, mixed>, is_set: bool, updated_at: ?string, summary: string}
     */
    public function save(?User $user, array $raw): array
    {
        if (! $user) {
            return $this->get(null);
        }

        $data = self::normalize($raw);

        DB::transaction(function () use ($user, $data) {
            BrandDna::updateOrCreate(['user_id' => $user->id], ['data' => $data]);
        });

        return $this->get($user);
    }

    /** Xoá hồ sơ (trở về "chưa khai") — không xoá dữ liệu bán hàng hay dự án của người dùng. */
    public function reset(?User $user): array
    {
        if ($user) {
            BrandDna::query()->where('user_id', $user->id)->delete();
        }

        return $this->get($user);
    }

    /**
     * Câu tóm tắt DNA để hiển thị và để đưa vào prompt — CHỈ nói điều chủ shop đã khai.
     *
     * Trả '' khi chưa khai gì: nơi gọi phải tự quyết định dùng phần suy ra hay câu mặc định, chứ
     * không được trộn một câu DNA nửa thật nửa đoán vào prompt.
     */
    public function summary(array $dna): string
    {
        $dna = self::normalize($dna);
        if (self::isEmpty($dna)) {
            return '';
        }

        $parts = [];
        if ($dna['positioning'] !== '') {
            $parts[] = 'định vị: '.$dna['positioning'];
        }
        if ($dna['customer'] !== '') {
            $parts[] = 'khách hàng: '.$dna['customer'];
        }
        if ($dna['price_band'] !== '') {
            $parts[] = 'dải giá: '.self::PRICE_BAND_LABELS[$dna['price_band']];
        }
        $lists = [
            'styles' => 'phong cách',
            'colors' => 'màu chủ đạo',
            'categories' => 'nhóm hàng chính',
            'materials' => 'chất liệu',
            'avoid' => 'KHÔNG làm',
        ];
        foreach ($lists as $field => $label) {
            if ($dna[$field] !== []) {
                $parts[] = $label.': '.implode(', ', $dna[$field]);
            }
        }
        if ($dna['notes'] !== '') {
            $parts[] = 'ghi chú: '.$dna['notes'];
        }

        return 'DNA do chủ shop khai — '.implode(' · ', $parts).'.';
    }
}
