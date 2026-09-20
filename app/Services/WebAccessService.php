<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * KHẢ NĂNG TRUY CẬP INTERNET CỦA AGENT — ĐO THẬT, không hứa suông (2026-09-23).
 *
 * Vì sao cần: giao diện Agent Studio ghi "Nguồn ngoài đang ở chế độ demo" nhưng câu đó là VĂN BẢN
 * TĨNH — nó không biết máy chủ có ra được internet hay không, cũng không biết model đang cấu hình có
 * khả năng tìm kiếm hay không. Người dùng mua "phân tích xu hướng" cần biết chính xác đang có gì.
 *
 * Hai tầng phải tách bạch, vì chúng KHÁC NHAU và có thể lệch nhau:
 *   1. MÁY CHỦ (tầng này) — Hostinger cho phép gọi ra ngoài (allow_url_fopen + HTTPS). Đo bằng một
 *      request thật tới vài đích cố định, ghi lại mã HTTP và độ trễ.
 *   2. MODEL (tầng suy luận) — chỉ MỘT SỐ provider có tìm kiếm tích hợp (Qwen/DashScope `enable_search`,
 *      Gemini `google_search`). DeepSeek — provider đang chạy trên production — KHÔNG có. Model không
 *      có tìm kiếm thì câu trả lời vẫn chỉ dựa trên dữ liệu mình gửi vào.
 *
 * Kết quả được cache ngắn (10 phút) vì mỗi lần đo là một request ra ngoài; `force` để đo lại.
 */
class WebAccessService
{
    public const CACHE_KEY = 'studio:web-access:v1';

    private const CACHE_MINUTES = 10;

    /**
     * Provider có TÌM KIẾM TÍCH HỢP hay không, và bật bằng tham số nào.
     *
     * Đây là khai báo DUY NHẤT về khả năng tìm kiếm — AiModelGateway đọc lại đúng bảng này khi
     * dựng payload, nên không thể có chuyện giao diện nói "có tìm kiếm" mà request không bật gì.
     */
    public const SEARCH_TRANSPORTS = [
        'qwen' => [
            'supported' => true,
            'param' => 'enable_search',
            'label' => 'Qwen/DashScope — bật enable_search',
        ],
        'gemini' => [
            'supported' => true,
            'param' => 'google_search',
            'label' => 'Gemini — grounding bằng Google Search',
        ],
        'openai' => [
            'supported' => false,
            'param' => '',
            'label' => 'OpenAI-compatible — KHÔNG có tìm kiếm tích hợp',
        ],
        'dashscope' => [
            'supported' => true,
            'param' => 'enable_search',
            'label' => 'DashScope — bật enable_search',
        ],
    ];

    /** Transport này có tìm kiếm tích hợp không? (mặc định KHÔNG — thiếu khai báo thì đừng hứa) */
    public static function supportsSearch(?string $transport): bool
    {
        return (bool) (self::SEARCH_TRANSPORTS[(string) $transport]['supported'] ?? false);
    }

    /** Bảng khả năng tìm kiếm theo TÊN PROVIDER (đã cấu hình trong Model Registry). */
    public function providerSearchMap(): array
    {
        $out = [];
        foreach ($this->candidates() as $candidate) {
            $transport = (string) ($candidate['transport'] ?? '');
            $out[] = [
                'provider' => (string) ($candidate['provider'] ?? ''),
                'model' => (string) ($candidate['model'] ?? ''),
                'transport' => $transport,
                'supported' => self::supportsSearch($transport),
                'label' => self::SEARCH_TRANSPORTS[$transport]['label'] ?? 'Không rõ transport',
            ];
        }

        return $out;
    }

    /**
     * Kết quả đo — có cache. `force = true` để đo lại ngay (nút "Kiểm tra lại" trên giao diện).
     *
     * @return array<string, mixed>
     */
    public function probe(bool $force = false): array
    {
        if ($force) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->measure());
    }

    /** Đo thật: gọi ra ngoài + đọc cấu hình model đang chạy. Không cache (để test được). */
    private function measure(): array
    {
        $results = [];
        foreach ((array) config('studio.web_probe_targets', []) as $url) {
            $url = (string) $url;
            if ($url === '') {
                continue;
            }
            $started = microtime(true);
            try {
                // HEAD trước: rẻ hơn GET và đủ để biết có ra được internet hay không.
                $response = Http::timeout(6)->withHeaders(['User-Agent' => 'FabrikAI-WebProbe/1.0'])->head($url);
                $results[] = [
                    'url' => $url,
                    'ok' => $response->successful(),
                    'status' => $response->status(),
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ];
            } catch (\Throwable $e) {
                // Không ném ra ngoài: mất internet là MỘT KẾT QUẢ ĐO, không phải lỗi của tính năng.
                $results[] = [
                    'url' => $url,
                    'ok' => false,
                    'status' => null,
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                    'error' => class_basename($e),
                ];
            }
        }

        $outbound = collect($results)->contains(fn (array $row) => $row['ok'] === true);
        $map = $this->providerSearchMap();
        $active = collect($map)->firstWhere('supported', true);

        // Câu kết luận nói ĐÚNG cái đang có — đây là câu người dùng đọc để quyết định có tin hay không.
        if (! $outbound) {
            $verdict = 'no_internet';
            $verdictLabel = 'Máy chủ KHÔNG gọi được ra internet — mọi phân tích chỉ dựa trên dữ liệu bạn nhập.';
        } elseif ($active) {
            $verdict = 'internet_and_search';
            $verdictLabel = 'Máy chủ có internet và model đang dùng CÓ tìm kiếm tích hợp — kết quả phân tích có thể kèm nguồn thật.';
        } else {
            $verdict = 'internet_no_search';
            $verdictLabel = 'Máy chủ có internet nhưng MODEL đang cấu hình KHÔNG có tìm kiếm tích hợp — câu trả lời chỉ dựa trên dữ liệu hệ thống gửi vào (hiện là dữ liệu mẫu + dữ liệu của chính bạn).';
        }

        return [
            'outbound' => [
                'ok' => $outbound,
                'results' => $results,
                'checked_at' => now()->toISOString(),
                'cache_minutes' => self::CACHE_MINUTES,
            ],
            'model_search' => [
                'supported' => $active !== null,
                'active' => $active ? ['provider' => $active['provider'], 'model' => $active['model']] : null,
                'candidates' => $map,
                'note' => 'Tìm kiếm tích hợp là tính năng của NHÀ CUNG CẤP model, không phải của FabrikAI.',
            ],
            // Nguồn ngoài vẫn là dữ liệu MẪU cho tới khi có connector thật (chưa có scraping/POS/ERP).
            'sources_mode' => 'demo',
            'verdict' => $verdict,
            'verdict_label' => $verdictLabel,
        ];
    }

    /** Candidate của nhóm công việc agent — cùng nhóm mà hai agent dùng để suy luận. */
    private function candidates(): array
    {
        return app(AiModelGateway::class)->candidates(DesignAgentService::AI_GROUP);
    }
}
