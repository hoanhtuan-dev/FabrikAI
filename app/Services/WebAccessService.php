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
    /** Các KIỂU bật tìm kiếm mà hệ thống biết cách dựng request (nhãn hiển thị ở Cài đặt). */
    public const SEARCH_MODES = [
        'body_flag' => 'Gửi cờ trong body — {"tham số": true} (DashScope/Qwen: enable_search)',
        'tools' => 'Gửi tools: [{"tham số": {}}] (Gemini: google_search)',
        'model_suffix' => 'Nối vào TÊN MODEL (OpenRouter kiểu ":online")',
        'plugins' => 'Gửi plugins: [{"id": "tham số"}]',
        // [2026-09-21] ĐƯỜNG THỨ NĂM: gọi endpoint /responses kèm công cụ tìm kiếm CỦA NHÀ CUNG CẤP
        // (DeepSeek: tools:[{"type":"web_search"}]). ĐO THẬT trên production:
        //   · /chat/completions + tools:[{type:web_search}] → HTTP 422 "unknown variant web_search";
        //   · /responses + cùng tham số → HTTP 200 và web_search_call THẬT — nhưng CHỈ với model hỗ trợ
        //     (deepseek-v4-pro: 1–9 lượt tìm, URL thật · deepseek-flash: nhận tham số rồi BỊA tin + URL).
        // Vì vậy đường này KHÔNG tin theo lời khai: chỉ tính là "đã tìm" khi response có web_search_call.
        'responses_web_search' => 'Gọi endpoint /responses kèm tools: [{"type": "tham số"}] — model PHẢI hỗ trợ (DeepSeek: web_search, chỉ model mới)',
    ];

    public static function planFor(array $candidate): ?array
    {
        $declared = trim((string) ($candidate['search_param'] ?? ''));
        if ($declared !== '') {
            $mode = (string) ($candidate['search_mode'] ?? 'body_flag');

            return [
                'mode' => isset(self::SEARCH_MODES[$mode]) ? $mode : 'body_flag',
                'param' => $declared,
                'source' => 'khai trong Cài đặt (Custom Provider)',
                // Khai báo KHÔNG phải bằng chứng: ta gửi tham số, còn gateway có hiểu hay không chỉ gateway
                // biết. Đo thật 2026-09-23 với DeepSeek: gửi `enable_search` → HTTP 200 nhưng BỎ QUA, model
                // vẫn trả lời "không có quyền truy cập thông tin thời gian thực" ⇒ giao diện phải nói rõ.
                'verified' => false,
            ];
        }

        $dialect = self::SEARCH_DIALECTS[(string) ($candidate['transport'] ?? '')] ?? null;
        if ($dialect === null) {
            return null;
        }

        // Giao thức thì CHẮC CHẮN: chính mã này dựng request theo cách đã biết của giao thức đó.
        return $dialect + ['source' => 'giao thức '.$candidate['transport'], 'verified' => true];
    }

    /**
     * Kế hoạch này dùng ENDPOINT /responses kèm công cụ của nhà cung cấp — khác hẳn bốn kiểu còn lại
     * (chúng chỉ gửi thêm tham số trong /chat/completions).
     *
     * @param  array<string, mixed>|null  $plan
     */
    public static function isHostedMode(?array $plan): bool
    {
        return ($plan['mode'] ?? '') === 'responses_web_search';
    }

    /** Giao thức này có tìm kiếm tích hợp không? (giữ cho nơi gọi cũ; mặc định KHÔNG) */
    public static function supportsSearch(?string $transport): bool
    {
        return isset(self::SEARCH_DIALECTS[(string) $transport]);
    }

    /**
     * Giao thức này gọi được CÔNG CỤ theo chuẩn function-calling không? (đường "tool search" của máy chủ)
     *
     * Đây là đường thứ hai, KHÔNG thay đường thứ nhất: nhà cung cấp có tìm kiếm tích hợp thì dùng luôn cho
     * rẻ; không có (đo trên production: DeepSeek — model văn bản đang chạy) thì máy chủ khai công cụ
     * `web_search`, tự đi tìm rồi trả kết quả về prompt. Danh sách giao thức nằm Ở ĐÂY (một nguồn): cả
     * DesignAgentService lẫn màn hình Cài đặt đều đọc từ đây nên không thể nói lệch nhau.
     */
    public static function supportsToolSearch(?string $transport): bool
    {
        return in_array((string) $transport, ['qwen', 'dashscope', 'openai'], true);
    }

    /** Bảng khả năng tìm kiếm theo TỪNG model ĐANG ĐƯỢC CẤU HÌNH (không theo nhà cung cấp nào cả). */
    public function providerSearchMap(): array
    {
        $out = [];
        foreach ($this->candidates() as $candidate) {
            $plan = self::planFor($candidate);
            $group = (string) ($candidate['group'] ?? '');
            // Công cụ chỉ được KHAI khi model nằm ở vai "Tìm kiếm nguồn ngoài" — đúng điều mà
            // DesignAgentService::searchSetup() làm. Nói khác đi là màn hình hứa một việc agent không làm.
            $tool = $plan === null && self::supportsToolSearch($candidate['transport'] ?? null);
            $toolReady = $tool && $group === DesignAgentService::SEARCH_GROUP;

            $out[] = [
                'provider' => (string) ($candidate['provider'] ?? ''),
                'model' => (string) ($candidate['model'] ?? ''),
                'transport' => (string) ($candidate['transport'] ?? ''),
                'group' => $group,
                'supported' => $plan !== null,
                'plan' => $plan,
                // Số đo cho đường "máy chủ chạy công cụ": model này gọi được hàm không, và nó có nằm ở
                // đúng vai tìm kiếm không (khai model vào vai khác thì công cụ KHÔNG được bật).
                'tool' => $tool,
                'tool_ready' => $toolReady,
                'label' => match (true) {
                    // Đường /responses: KHÔNG phải "gửi tham số rồi cầu mong gateway hiểu" — nó gọi hẳn
                    // một endpoint khác, và lượt chạy ĐẾM ĐƯỢC số lời gọi tìm kiếm thật trong phản hồi. Nhưng
                    // chỉ model hỗ trợ mới tìm (model nhỏ nhận yêu cầu rồi tự bịa tin), nên lời khai ở đây
                    // tuyệt đối không được viết như một lời bảo đảm.
                    self::isHostedMode($plan) => 'Gọi endpoint /responses kèm công cụ tìm kiếm `'.$plan['param'].'` — '.$plan['source']
                        .' · CHỈ model hỗ trợ mới tìm thật (model khác vẫn trả lời nhưng KHÔNG tìm); Agent Studio báo số lượt tìm thật của từng lượt chạy',
                    $plan !== null => 'Bật tìm kiếm ('.($plan['mode'] ?? 'body_flag').') bằng `'.$plan['param'].'` — '.$plan['source']
                        .(($plan['verified'] ?? false) ? '' : ' · CHƯA kiểm chứng: gateway không hỗ trợ thì tham số bị bỏ qua'),
                    $toolReady => 'Máy chủ chạy công cụ tìm kiếm (web_search) khi model gọi — không cần nhà cung cấp có tìm kiếm tích hợp',
                    $tool => 'Gọi được công cụ, nhưng model này chưa nằm ở vai "Agent Studio — Tìm kiếm nguồn ngoài"',
                    default => 'Không có tìm kiếm web (giao thức không khai tham số tìm kiếm và không gọi được công cụ)',
                },
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
        // Đường thứ hai: model ở ĐÚNG vai tìm kiếm và gọi được công cụ do máy chủ chạy.
        $toolReady = collect($map)->first(fn (array $row) => ($row['tool_ready'] ?? false) === true);
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
            // Phân biệt "giao thức chắc chắn" với "bạn khai": khai báo chỉ là khai báo, và người dùng cần
            // biết mức độ tin của chính câu kết luận này.
            $verdictLabel = self::isHostedMode($active['plan'] ?? null)
                // Nói ĐÚNG cơ chế của đường /responses: có tìm thật hay không là chuyện của TỪNG MODEL, và
                // lượt chạy nào cũng trả về số lượt tìm thật — nên đừng hứa, hãy chỉ chỗ kiểm.
                ? 'Máy chủ có internet và model bạn gán cho vai «Tìm kiếm nguồn ngoài» gọi endpoint /responses kèm công cụ tìm kiếm của nhà cung cấp. '
                    .'CHỈ model hỗ trợ mới tìm thật — Agent Studio hiện số lượt tìm được của từng lượt chạy, nên hãy mở một lượt phân tích để đối chiếu.'
                : (($active['plan']['verified'] ?? false)
                    ? 'Máy chủ có internet và model bạn đang cấu hình CÓ tìm kiếm web — kết quả phân tích có thể kèm nguồn thật.'
                    : 'Máy chủ có internet và model bạn đang cấu hình được KHAI là có tìm kiếm web (chưa kiểm chứng được từ phía máy chủ) — nếu gateway không hỗ trợ thì tham số bị bỏ qua và câu trả lời vẫn chỉ dựa trên dữ liệu hệ thống gửi vào.');
        } elseif ($toolReady) {
            // ĐƯỜNG THỨ HAI: nhà cung cấp không có tìm kiếm tích hợp, nhưng model biết gọi hàm ⇒ MÁY CHỦ đi
            // tìm thật rồi trả kết quả về prompt. Câu này nói đúng cơ chế, không hứa "model tự tìm".
            $verdict = 'internet_and_tool_search';
            $verdictLabel = 'Máy chủ có internet và model bạn gán cho vai «Tìm kiếm nguồn ngoài» sẽ GỌI CÔNG CỤ tìm kiếm do máy chủ chạy: '
                .'máy chủ đi tìm tin thật theo từ khoá model hỏi rồi đưa kết quả (kèm nguồn và thời điểm) trở lại cho model đọc. '
                .'Cách này không phụ thuộc việc nhà cung cấp model có sẵn tìm kiếm hay không.';
        } else {
            $verdict = 'internet_no_search';
            // KHÔNG nêu tên nhà cung cấp nào và KHÔNG gợi ý mua key của ai: việc chọn model là ở Cài đặt.
            $verdictLabel = 'Máy chủ có internet nhưng lượt chạy này KHÔNG có tìm kiếm web: model đang cấu hình không có tìm kiếm tích hợp, '
                .'và vai «Agent Studio — Tìm kiếm nguồn ngoài» chưa được gán model nên công cụ tìm kiếm của máy chủ không được bật. '
                .'Câu trả lời chỉ dựa trên dữ liệu hệ thống gửi vào (dữ liệu mẫu + dữ liệu của chính bạn). '
                .'Muốn có nguồn thật: gán một model cho vai «Agent Studio — Tìm kiếm nguồn ngoài» trong Cài đặt → Nhóm công việc.';
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
                // verified = true chỉ khi cách bật đến từ GIAO THỨC (mã này tự dựng request đúng chuẩn);
                // false = do người dùng khai trong Cài đặt ⇒ giao diện phải nói "chưa kiểm chứng".
                'verified' => $active !== null && ($active['plan']['verified'] ?? false) === true,
                'active' => $active ? ['provider' => $active['provider'], 'model' => $active['model']] : null,
                'candidates' => $map,
                'note' => 'Tìm kiếm tích hợp là tính năng của NHÀ CUNG CẤP model, không phải của FabrikAI.',
                // ĐƯỜNG TÌM KIẾM THỨ HAI — số đo riêng, không gộp với tìm kiếm tích hợp: hai cơ chế khác
                // nhau, và gộp lại thì không ai biết vì sao lần này có nguồn còn lần khác thì không.
                'tool_search' => [
                    'available' => $toolReady !== null,
                    'role_configured' => $map !== [] && ($map[0]['group'] ?? '') === DesignAgentService::SEARCH_GROUP,
                    'note' => 'Công cụ do MÁY CHỦ chạy: model chỉ cần biết gọi hàm, không cần nhà cung cấp có tìm kiếm tích hợp.',
                ],
            ],
            // NHÓM CÔNG VIỆC nào chưa có model — đọc từ CHÍNH Cài đặt, không phải danh sách cứng.
            // Người dùng cần thấy "nhóm tạo ảnh chưa có model" như một trạng thái CẤU HÌNH, không phải lỗi.
            'task_groups' => $this->taskGroups(),
            // Nguồn ngoài: ĐỌC TRẠNG THÁI THẬT, không phải hằng số. Trước đây luôn trả 'demo' kể cả khi đã
            // nối nguồn RSS thật ⇒ màn hình "khả năng truy cập internet" nói ngược với thực tế.
            'sources_mode' => $this->sourcesMode(),
            'verdict' => $verdict,
            'verdict_label' => $verdictLabel,
        ];
    }

    /**
     * Trạng thái THẬT của nguồn dữ liệu ngoài: 'live' khi đã đo được tín hiệu từ tin thật, ngược lại 'demo'.
     *
     * Trả 'demo' khi chưa nối nguồn nào — nghĩa là "chưa có dữ liệu thị trường thật", đúng như giao diện
     * cần nói để người dùng không tin nhầm vào số liệu mẫu.
     */
    private function sourcesMode(): string
    {
        try {
            $report = app(MarketSignalService::class)->report('all');

            return ($report['mode'] ?? 'empty') === 'live' ? 'live' : 'demo';
        } catch (\Throwable) {
            // Chưa migrate / lỗi DB ⇒ coi như chưa có dữ liệu thật, KHÔNG được hứa là đang có.
            return 'demo';
        }
    }

    /**
     * Model dùng cho phần SUY LUẬN của agent — đọc theo thứ tự vai: tìm kiếm → suy luận → nhóm nền.
     *
     * Đọc y như DesignAgentService để màn hình "khả năng truy cập internet" không nói khác điều agent làm.
     */
    private function candidates(): array
    {
        $gateway = app(AiModelGateway::class);
        foreach ([DesignAgentService::SEARCH_GROUP, DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP] as $group) {
            $rows = $gateway->candidates($group);
            if ($rows !== []) {
                // Gắn NHÓM THẬT ĐÃ DÙNG vào từng dòng: màn hình phải phân biệt được "model ở vai tìm kiếm"
                // với "model rơi về nhóm suy luận" — hai chuyện cho ra hai câu kết luận khác nhau.
                return array_map(fn (array $row) => $row + ['group' => $group], $rows);
            }
        }

        return [];
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
            DesignAgentService::REASON_GROUP => 'Agent Studio — Suy luận & viết nội dung',
            DesignAgentService::VISION_GROUP => 'Agent Studio — Đọc ảnh mẫu',
            DesignAgentService::SEARCH_GROUP => 'Agent Studio — Tìm kiếm nguồn ngoài',
            'prompt' => 'Suy luận & viết nội dung (dùng chung)',
            'vision' => 'Đọc ảnh (dùng chung)',
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
