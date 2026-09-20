<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * SO KHỚP TỪ KHOÁ TIẾNG VIỆT — dùng chung cho lọc tin nguồn ngoài và đo tín hiệu thị trường (2026-09-23).
 *
 * Vì sao phải có lớp này: cả hai chỗ đều so từ khoá tiếng Việt, và cả hai đều từng làm sai theo hai kiểu
 * trái ngược nhau:
 *   · khớp CHUỖI CON: "áo" khớp trong "báo", "đầm" khớp trong "đầm phá" ⇒ tin rác chảy vào phân tích;
 *   · đòi ĐÚNG DẤU: "thời trang" không khớp "thoi trang" ⇒ bỏ sót tin thật ngay trên feed không dấu.
 *
 * Quy tắc ở đây: khớp theo RANH GIỚI TỪ, và chỉ cho phép khớp KHÔNG DẤU với CỤM từ (≥2 tiếng).
 * Lý do của vế thứ hai: bỏ dấu một tiếng là đánh đổi sai — "đầm" thành "dam", "đỏ" thành "do",
 * "trắng" thành "trang" (trang giấy), tức là mở cửa cho một loạt từ khác nghĩa.
 */
class VietnameseText
{
    /** Chuẩn hoá để so cụm từ: chữ thường, mọi dấu câu thành khoảng trắng, gộp khoảng trắng. */
    public static function flatten(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Cụm từ có xuất hiện với RANH GIỚI chữ/số ở hai đầu không? ("áo" KHÔNG khớp trong "báo"). */
    public static function phraseIn(string $phrase, string $haystack): bool
    {
        $phrase = trim(mb_strtolower($phrase));
        if ($phrase === '' || $haystack === '') {
            return false;
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $haystack);
    }

    /** Một từ khoá có được nhắc tới trong đoạn chữ không (kèm quy tắc không-dấu ở trên)? */
    public static function mentions(string $term, string $text): bool
    {
        $term = trim(mb_strtolower($term));
        if ($term === '' || trim($text) === '') {
            return false;
        }

        $flat = self::flatten($text);
        if (self::phraseIn($term, $flat)) {
            return true;
        }

        if (! str_contains($term, ' ')) {
            return false;
        }

        return self::phraseIn(Str::ascii($term), self::flatten(Str::ascii($text)));
    }

    /**
     * Có từ khoá NÀO trong danh sách được nhắc tới không? (nguồn tin khai nhiều từ khoá, khớp một là giữ)
     *
     * @param  list<string>  $terms
     */
    public static function mentionsAny(array $terms, string $text): bool
    {
        foreach ($terms as $term) {
            if (self::mentions((string) $term, $text)) {
                return true;
            }
        }

        return false;
    }
}
