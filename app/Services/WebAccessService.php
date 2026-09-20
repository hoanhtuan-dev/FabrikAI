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
     * CÁCH BẬT TÌM KIẾM của từng GIAO THỨC (không phải của từng NHÀ CUNG CẤP).
     *
     * Vì sao khoá theo giao thức: chọn dùng nhà cung cấp/model nào là việc của CÀI ĐẶT (Model Registry ·
     * Nhóm công việc · Luồng ưu tiên · Custom Providers). Mã nguồn chỉ được biết "giao thức này bật tìm
     * kiếm bằng cách nào" — thêm một nhà cung cấp mới nói cùng giao thức thì KHÔNG phải sửa mã.
     * Nhà cung cấp tự khai (Custom Providers) có thể nói tham số của riêng họ ở cột `search_param`.
     */
    public const SEARCH_DIALECTS = [
        // OpenAI-compatible trên DashScope: bật bằng cờ trong body.
        'qwen' => ['mode' => 'body_flag', 'param' => 'enable_search'],
        'dashscope' => ['mode' => 'body_flag', 'param' => 'enable_search'],
        // Gemini: grounding bằng Google Search (một "tool").
        'gemini' => ['mode' => 'tools', 'param' => 'google_search'],
    ];

    /**
     * Kế hoạch bật tìm kiếm cho MỘT candidate ĐANG ĐƯỢC CẤU HÌNH — null = không hỗ trợ/không rõ.
     *
     * Thứ tự: nhà cung cấp tự khai (`search_param`) trước, sau đó tới giao thức. Không đoán: thiếu cả hai
     * thì trả null và giao diện phải nói "không có tìm kiếm" thay vì hứa suông.
     *
     * @param  array{provider?:string, model?:string, transport?:string, search_param?:?string}  $candidate
     * @return array{mode:string, param:string, source:string}|null
     */
    public static function planFor(array $candidate): ?array
    {
        $declared = trim((string) ($candidate['search_param'] ?? ''));
        if ($declared !== '') {
            return [
                'mode' => 'body_flag',
                'param' => $declared,
                'source' => 'khai trong Cài đặt (Custom Provider)',
            ];
        }

        $dialect = self::SEARCH_DIALECTS[(string) ($candidate['transport'] ?? '')] ?? null;
        if ($dialect === null) {
            return null;
        }

        return $dialect + ['source' => 'giao thức '.$candidate['transport']];
    }

    /** Giao thức này có tìm kiếm tích hợp không? (giữ cho nơi gọi cũ; mặc định KHÔNG) */
    public static function supportsSearch(?string $transport): bool
    {
        return isset(self::SEARCH_DIALECTS[(string) $transport]);
    }

    /** Bảng khả năng tìm kiếm theo TỪNG model ĐANG ĐƯỢC CẤU HÌNH (không theo nhà cung cấp nào cả). */
    public function providerSearchMap(): array
    {
        $out = [];
        foreach ($this->candidates() as $candidate) {
            $plan = self::planFor($candidate);
            $out[] = [
                'provider' => (string) ($candidate['provider'] ?? ''),
                'model' => (string) ($candidate['model'] ?? ''),
                'transport' => (string) ($candidate['transport'] ?? ''),
                'supported' => $plan !== null,
                'plan' => $plan,
                'label' => $plan === null
                    ? 'Không có tìm kiếm web (giao thức không khai tham số tìm kiếm)'
                    : 'Bật tìm kiếm bằng `'.$plan['param'].'` — '.$plan['source'],
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
        // "Chưa cấu hình model dùng được" KHÁC "model không có tìm kiếm" — gộp hai thứ này là nói sai với
        // người dùng: nhóm rỗng nghĩa là việc cần làm nằm ở Cài đặt (thêm key/model), không phải lỗi agent.
        $hasModel = $map !== [];

        // Câu kết luận nói ĐÚNG cái đang có — đây là câu người dùng đọc để quyết định có tin hay không.
        if (! $outbound) {
            $verdict = 'no_internet';
            $verdictLabel = 'Máy chủ KHÔNG gọi được ra internet — mọi phân tích chỉ dựa trên dữ liệu bạn nhập.';
        } elseif (! $hasModel) {
            $verdict = 'no_model_configured';
            $verdictLabel = 'Máy chủ có internet nhưng NHÓM SUY LUẬN chưa có model dùng được (thiếu key hoặc chưa gán model) — agent đang chạy bằng bộ quy tắc có sẵn. Vào Cài đặt → Nhóm công việc để cấu hình; khi đã có model thì khả năng tìm kiếm web phụ thuộc chính model đó.';
        } elseif ($active) {
            $verdict = 'internet_and_search';
            $verdictLabel = 'Máy chủ có internet và model bạn đang cấu hình CÓ tìm kiếm web — kết quả phân tích có thể kèm nguồn thật.';
        } else {
            $verdict = 'internet_no_search';
            // KHÔNG nêu tên nhà cung cấp nào và KHÔNG gợi ý mua key của ai: việc chọn model là ở Cài đặt.
            $verdictLabel = 'Máy chủ có internet nhưng model bạn đang cấu hình KHÔNG có tìm kiếm web — câu trả lời chỉ dựa trên dữ liệu hệ thống gửi vào (hiện là dữ liệu mẫu + dữ liệu của chính bạn). Muốn có nguồn thật: chọn một model/nhà cung cấp có tìm kiếm trong Cài đặt → Nhóm công việc (hoặc khai tham số tìm kiếm cho Custom Provider).';
        }

        return [
            'outbound' => [
                'ok' => $outbound,
                'results' => $results,
                'checked_at' => now()->toISOString(),
                'cache_minutes' => self::CACHE_MINUTES,
            ],
            'model_search' => [
                // supported = có model dùng được VÀ model đó có tìm kiếm. has_model tách riêng để giao diện
                // phân biệt "chưa cấu hình" với "đã cấu hình nhưng không có tìm kiếm".
                'has_model' => $hasModel,
                'supported' => $active !== null,
                'active' => $active ? ['provider' => $active['provider'], 'model' => $active['model']] : null,
                'candidates' => $map,
                'note' => 'Tìm kiếm tích hợp là tính năng của NHÀ CUNG CẤP model, không phải của FabrikAI.',
            ],
            // NHÓM CÔNG VIỆC nào chưa có model — đọc từ CHÍNH Cài đặt, không phải danh sách cứng.
            // Người dùng cần thấy "nhóm tạo ảnh chưa có model" như một trạng thái CẤU HÌNH, không phải lỗi.
            'task_groups' => $this->taskGroups(),
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

    /**
     * Trạng thái CẤU HÌNH của các nhóm công việc mà Studio cần — đọc thẳng từ Cài đặt.
     *
     * Nhóm rỗng KHÔNG phải lỗi: đó là "chưa cài đặt model/key". Giao diện phải nói đúng như vậy, và
     * danh sách nhóm lấy từ hằng số của ứng dụng (không phải tên nhà cung cấp nào).
     *
     * @return list<array{group:string, label:string, configured:bool, candidates:int, models:list<string>}>
     */
    public function taskGroups(): array
    {
        $labels = [
            'prompt' => 'Suy luận & viết nội dung (Agent Studio)',
            'vision' => 'Đọc ảnh',
            'image' => 'Tạo ảnh',
            'edit' => 'Sửa ảnh',
            'video' => 'Video',
        ];

        $out = [];
        foreach ($labels as $group => $label) {
            $rows = function_exists('studio_task_group_models') ? studio_task_group_models($group) : [];
            // PHÂN BIỆT hai chuyện rất khác nhau (đo trên production: nhóm image có 3 model ĐÃ GÁN nhưng
            // 0 model DÙNG ĐƯỢC vì thiếu key):
            //   configured — đã gán model cho nhóm trong Cài đặt;
            //   usable     — có ít nhất một candidate thật sự chạy được (có key đang bật).
            // Gộp hai thứ này lại là nói sai: người dùng tưởng đã xong, hoặc tưởng hệ thống hỏng.
            $usable = app(AiModelGateway::class)->candidates($group);
            $out[] = [
                'group' => $group,
                'label' => $label,
                'configured' => $rows !== [],
                'candidates' => count($rows),
                'usable' => count($usable),
                'needs_key' => $rows !== [] && $usable === [],
                'models' => array_values(array_map(
                    fn ($row) => trim((string) ($row['provider'] ?? '').':'.(string) ($row['model'] ?? '')),
                    $rows,
                )),
                'usable_models' => array_values(array_map(
                    fn ($row) => trim((string) ($row['provider'] ?? '').':'.(string) ($row['model'] ?? '')),
                    $usable,
                )),
            ];
        }

        return $out;
    }
}
