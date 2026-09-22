<?php

namespace App\Support;

/**
 * TÁCH TỪ KHOÁ TIẾNG VIỆT — MỘT chỗ duy nhất (2026-09-26, việc #7→#9).
 *
 * Vì sao tách khỏi BrandLearningService: bản gốc nằm trong service trí nhớ, và việc #9 (tìm thiết kế cũ)
 * sắp cần ĐÚNG phép tách này. Chép sang là hai bản luật sẽ lệch nhau — mà lệch ở đây nghĩa là "cùng một
 * câu" được cắt thành hai tập từ khác nhau ở hai tính năng, không ai phát hiện.
 *
 * HAI LUẬT, mỗi luật có lý do riêng:
 *   · ĐỘ DÀI TỐI THIỂU LÀ 2, không phải 3: tiếng Việt có từ ngắn nhưng MANG NGHĨA trong ngành — "áo",
 *     "mi", "ly", "ve". Cắt ở 3 sẽ làm "áo sơ mi linen" và "áo thun linen" thành hai thứ khác nhau dù
 *     cùng là áo linen.
 *   · BỎ TỪ ĐỆM (STOP_WORDS): hai prompt khác hẳn nhau vẫn "trùng" chỉ vì cùng có chữ "và" hoặc "của".
 *     Danh sách NGẮN có chủ ý — chỉ những từ xuất hiện dày trong prompt thật.
 */
class Vocabulary
{
    public const STOP_WORDS = [
        'và', 'của', 'cho', 'với', 'các', 'một', 'những', 'trên', 'dưới', 'trong', 'ngoài', 'là', 'có',
        'được', 'theo', 'tại', 'về', 'để', 'khi', 'thì', 'như', 'hay', 'hoặc', 'rất', 'hơi', 'khá', 'này',
        'kia', 'đó', 'mà', 'bằng', 'từ', 'đến', 'sẽ', 'đã', 'đang',
    ];

    public const MIN_LENGTH = 2;

    /**
     * Tách từ khoá: chữ thường, bỏ dấu câu, bỏ từ đệm và từ ngắn hơn MIN_LENGTH.
     *
     * @return list<string> danh sách KHÔNG trùng, giữ thứ tự xuất hiện
     */
    public static function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn (string $word) => mb_strlen($word) >= self::MIN_LENGTH && ! in_array($word, self::STOP_WORDS, true),
        )));
    }

    /** Jaccard trên hai tập từ khoá đã chuẩn hoá — 0..1. Hàm THUẦN, test được không cần DB. */
    public static function jaccard(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $union = array_unique(array_merge($ta, $tb));

        return $union === [] ? 0.0 : count(array_intersect($ta, $tb)) / count($union);
    }
}
