<?php

namespace App\Ai;

/**
 * ÁNH XẠ MỘT CANDIDATE CỦA DỰ ÁN → CẤU HÌNH NHÀ CUNG CẤP CỦA LARAVEL AI SDK — MỘT nguồn duy nhất.
 *
 * Vì sao tách ra lớp riêng: cùng một luật (driver nào · địa chỉ nào · model mặc định nào) được dùng ở HAI
 * chỗ — cầu nối đăng ký provider (App\Ai\RegistryProviders) và động cơ chạy text qua SDK
 * (App\Ai\SdkTextEngine). Chép luật ra hai nơi là cách chắc chắn nhất để chúng lệch nhau, và lệch ở đây thì
 * lỗi chỉ lộ ra lúc chạy thật (tốn tiền, khó truy).
 *
 * Luật driver: mọi thứ KHÔNG phải Gemini đi openai-compatible. Dự án đã có sẵn địa chỉ đầy đủ + khoá cho
 * từng nhà cung cấp, nên driver tổng quát là đường ít phụ thuộc nhất vào cách SDK hiểu từng hãng. (Đã đo:
 * SDK gọi {url}/chat/completions — đúng cách AiModelGateway đang gọi.)
 */
class SdkProviderMap
{
    /** Tiền tố tên provider đăng ký vào SDK — để không đụng tên provider dựng sẵn của SDK. */
    public const PREFIX = 'fabrikai_';

    /**
     * Cấu hình provider SDK cho MỘT candidate + MỘT khoá. null = không dựng được (thiếu địa chỉ).
     *
     * @param  array<string, mixed>  $candidate  dòng đã giải của AiModelGateway::candidates()
     * @return array{driver:string, key:string, url:string, models:array{text:array{default:string}}}|null
     */
    public static function configFor(array $candidate, string $key): ?array
    {
        $base = self::baseFor($candidate, $key);
        if ($base === '') {
            return null;
        }

        return [
            'driver' => self::driverFor($candidate),
            'key' => $key,
            'url' => $base,
            // SDK đòi model mặc định cho đường openai-compatible. Ta luôn truyền model tường minh khi
            // gọi, nhưng vẫn khai để provider dựng được mà không ném lỗi.
            'models' => ['text' => ['default' => (string) ($candidate['model'] ?? '')]],
        ];
    }

    /**
     * Tên provider ổn định cho (nhà cung cấp · KHOÁ) — mỗi khoá một entry để failover xoay được.
     *
     * Khoá theo VÂN TAY CỦA KHOÁ chứ không theo chỉ số vòng lặp: cùng một khoá luôn ra cùng một tên dù
     * được duyệt theo thứ tự nào, nên hai đường (cầu nối đăng ký sẵn và động cơ chạy từng lượt) không
     * bao giờ ghi đè nhầm cấu hình của nhau; và cùng một khoá khai hai lần thì tự khử trùng.
     */
    public static function nameForKey(array $candidate, string $key): string
    {
        return self::PREFIX.($candidate['provider'] ?? 'x').'_'.substr(md5($key), 0, 8);
    }

    /** Gemini đi driver riêng; phần còn lại đi driver tổng quát. */
    public static function driverFor(array $candidate): string
    {
        return ($candidate['transport'] ?? '') === 'gemini' ? 'gemini' : 'openai-compatible';
    }

    /** Địa chỉ gọi thật — CÙNG luật với AiModelGateway (đừng để hai nơi lệch nhau). */
    public static function baseFor(array $candidate, string $key): string
    {
        $transport = (string) ($candidate['transport'] ?? '');
        $base = rtrim((string) ($candidate['base'] ?? ''), '/');

        if ($transport === 'qwen') {
            // Qwen: địa chỉ suy từ CHÍNH khoá (gói Token Plan nằm ở host riêng).
            return function_exists('dashscope_base_url') ? dashscope_base_url($key).'/compatible-mode/v1' : '';
        }

        if ($transport === 'gemini') {
            return $base !== '' ? $base : 'https://generativelanguage.googleapis.com/v1beta/';
        }

        return $base;
    }
}
