<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Deep AI assistance for the product create/edit form (admin).
 *
 * Config-driven and provider-ordered: it reads EVERYTHING (provider order, model
 * lists, API keys, timeouts, attempt bounds, image downscale, cache TTL) from
 * product_ai_* settings (DB -> env -> config/studio.php). Qwen is tried FIRST,
 * then Gemini — both over vision (multimodal single call) and text. Every path
 * ends in a deterministic offline fallback so the feature never returns empty.
 *
 * Two modes:
 *  - generateFromImage(): one multimodal call (see image + write full structured
 *    content + SEO) — the "Gợi ý từ ảnh" approach (~15s when a key works). The
 *    image understanding is cached by image hash, so later clicks (same image)
 *    only re-run the cheap TEXT step.
 *  - generate(): text-only — combines form state + (cached) understanding + hint.
 */
class ProductAIService
{
    protected array $providers;
    protected int $timeout;
    protected int $totalBudget;
    protected int $maxModels;
    protected int $maxKeys;
    protected int $downscaleMax;
    protected int $cacheTtl;
    protected float $temperature;
    protected int $maxTokens;
    protected int $visionMaxTokens;
    protected int $refineCacheTtl;
    protected int $refineStubCacheTtl;

    /** Hard wall-clock deadline for the whole operation (sync request must never 504). */
    private float $deadline = 0.0;

    private bool $budgetStarted = false;

    /** Diagnostics: what was tried and why it fell back to stub. */
    private array $attempts = [];
    private string $lastError = '';

    /**
     * Hồ sơ thương hiệu (nội dung trang /admin/pages/about) — tạo MỘT LẦN rồi tái
     * sử dụng trong cùng request (static) và qua các request (Laravel cache),
     * được làm mới khi admin lưu trang Giới thiệu. Mọi lời gợi ý / tinh chỉnh AI
     * đều đưa hồ sơ này vào quá trình suy luận.
     */
    private static ?string $brandContext = null;

    /**
     * Build (one time) / read the brand profile from the About page settings.
     * Cached in-process for the whole request + in Laravel cache for reuse.
     */
    public function brandContext(bool $force = false): string
    {
        if (self::$brandContext !== null) {
            return self::$brandContext;
        }

        if (! $force) {
            $cached = Cache::get('product_ai:brand_ctx');
            if (is_string($cached) && $cached !== '') {
                return self::$brandContext = $cached;
            }
        }

        $parts = [];
        $heading = trim((string) setting('about_heading', ''));
        $intro = trim(strip_tags((string) setting('about_intro', '')));
        $body = trim(strip_tags((string) setting('about_body', '')));
        if ($heading !== '') {
            $parts[] = 'Tiêu đề / slogan: '.$heading;
        }
        if ($intro !== '') {
            $parts[] = 'Giới thiệu ngắn: '.$intro;
        }
        if ($body !== '') {
            $parts[] = 'Nội dung mở rộng: '.$body;
        }
        $values = [];
        for ($i = 1; $i <= 3; $i++) {
            $t = trim((string) setting('about_v'.$i.'_title', ''));
            $x = trim((string) setting('about_v'.$i.'_text', ''));
            if ($t !== '') {
                $values[] = $t.($x !== '' ? ': '.$x : '');
            }
        }
        if ($values) {
            $parts[] = 'Giá trị cốt lõi: '.implode(' | ', $values);
        }

        $text = implode("\n", $parts);
        if ($text === '') {
            $text = 'Thương hiệu thời trang phong cách sống Việt Nam, tối giản, tinh tế, hướng đến người hiện đại.';
        }

        Cache::put('product_ai:brand_ctx', $text, 3600);

        return self::$brandContext = $text;
    }

    public function __construct()
    {
        $this->providers = product_ai_providers();
        // HARDCODE generous timeouts (like StyleSuggestService::timeout(90)) instead
        // of reading product_ai_timeout/total_budget from DB/config — those were being
        // overridden to low values on some hosts, causing "network/timeout" mid-generation.
        // Trần mặc định mỗi lần gọi HTTP là 30 giây — nếu provider không trả lời, chuyển
        // sang provider dự phòng (gemini/deepseek) thay vì treo rồi 504. Riêng đầu ra DÀI
        // (desc_variants ~4000 token) được nới trần theo hàm callTimeout() ở trên.
        $this->timeout = 30;
        $this->totalBudget = 110;
        $this->maxModels = product_ai_max_models();
        $this->maxKeys = product_ai_max_keys();
        $this->downscaleMax = product_ai_downscale_max();
        $this->cacheTtl = product_ai_cache_ttl();
        $this->temperature = product_ai_temperature();
        $this->maxTokens = product_ai_max_tokens();
        $this->visionMaxTokens = product_ai_vision_max_tokens();
        // Cache ngắn (giây) cho các lần "tinh chỉnh" TRÙNG (cùng target + prompt +
        // cùng toàn bộ dữ liệu) → bấm lại chip/refresh trả về tức thì, không gọi model.
        $this->refineCacheTtl = max(0, (int) product_ai_config('refine_cache_ttl', 600));
        // TTL cache cho kết quả THẤT BẠI (stub) — rất ngắn để sớm thử lại model.
        $this->refineStubCacheTtl = max(0, (int) product_ai_config('refine_stub_cache_ttl', 20));
    }

    /**
     * Arm the wall-clock budget ONCE for the whole public operation. Vision + the
     * text fallback share this single budget so the whole request always returns
     * well before the gateway/proxy timeout (no 504).
     */
    private function startBudget(): void
    {
        if ($this->budgetStarted) {
            return;
        }
        $this->budgetStarted = true;
        $this->deadline = microtime(true) + $this->totalBudget;
    }

    private function timedOut(): bool
    {
        return $this->deadline > 0.0 && microtime(true) >= $this->deadline;
    }

    /** Remaining seconds for the next HTTP call, clamped to a per-call cap that
     *  scales with the requested output size (output dài cần thời gian sinh lâu hơn —
     *  không được cắt giữa chừng desc_variants ~4000 token ở giây 30). */
    private function remainingTimeout(?int $maxTokens = null): int
    {
        $left = (int) floor($this->deadline - microtime(true));

        return max(1, min($this->callTimeout($maxTokens), $left));
    }

    private function callTimeout(?int $maxTokens): int
    {
        if ($maxTokens === null) {
            return $this->timeout;
        }

        return match (true) {
            $maxTokens >= 3000 => 100,
            $maxTokens >= 1500 => 60,
            $maxTokens >= 800 => 45,
            default => $this->timeout,
        };
    }

    private function record(string $provider, string $detail): void
    {
        $this->attempts[] = $provider.': '.$detail;
        $this->lastError = $detail;
        logger()->warning('ProductAI ['.$provider.'] '.$detail);
    }

    // ------------------------------------------------------------- stage 1: vision

    public function analyzeImage(string $imagePath, bool $force = false): ?array
    {
        if (! is_file($imagePath)) {
            return null;
        }

        $this->startBudget();

        $key = $this->imageCacheKey($imagePath);
        if (! $force) {
            $cached = Cache::get('product_ai_img:'.$key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->attempt('vision', $this->buildVisionPrompt(), $imagePath);

        // Only cache a REAL LLM understanding — offline GD stays uncached so a
        // later click retries the model when quota/network recovers.
        if (is_array($result)) {
            Cache::put('product_ai_img:'.$key, $result, $this->cacheTtl);

            return $result;
        }

        return $this->offlineAnalysis($imagePath);
    }

    /**
     * LIGHT vision prompt: understand the image only (short output, fast) — the
     * heavy content/SEO generation runs in the separate, fast TEXT step (qwen3.8-flash).
     */
    protected function buildVisionPrompt(): string
    {
        return 'Đây là ảnh sản phẩm thời trang/phong cách sống. Phân tích NGẮN GỌN và chỉ trả JSON hợp lệ duy nhất: '
            .'{"styles":"phong cách","colors":"màu chủ đạo","fabric":"chất liệu","subject":"chủ thể/đối tượng",'
            .'"garment":"loại trang phục","keywords":["từ khóa","..."],"feeling":"cảm giác/thông điệp"}.'
            .' Viết tiếng Việt, dùng cho content & SEO.';
    }

    /**
     * Deterministic offline analysis (GD): dominant/warmth/brightness -> a small
     * understanding so the AI always has something to reference without a key.
     */
    protected function offlineAnalysis(string $imagePath): array
    {
        try {
            $img = studio_image_decode($imagePath);
            if (! $img) {
                return [];
            }
            $w = imagesx($img); $h = imagesy($img);
            $step = max(1, (int) round(max($w, $h) / 48));
            $rs = 0; $gs = 0; $bs = 0; $n = 0;
            for ($x = 0; $x < $w; $x += $step) {
                for ($y = 0; $y < $h; $y += $step) {
                    $c = imagecolorat($img, $x, $y);
                    $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                    $rs += $r; $gs += $g; $bs += $b; $n++;
                }
            }
            imagedestroy($img);
            $r = $n ? (int) round($rs / $n) : 128;
            $g = $n ? (int) round($gs / $n) : 128;
            $b = $n ? (int) round($bs / $n) : 128;
            $avg = ($r + $g + $b) / 3;
            $warmth = ($r - $b);
            $style = $warmth > 20 ? 'ấm áp, tự nhiên' : ($warmth < -20 ? 'lạnh, hiện đại thanh lịch' : 'trung tính, tối giản');
            $brightness = $avg > 170 ? 'sáng, tươi' : ($avg < 90 ? 'đậm, sang trọng' : 'trung bình');
            $color = $r > $g + 30 && $r > $b + 30 ? 'tông đỏ/nâu' : ($g > $r && $g > $b ? 'tông xanh' : ($b > $r && $b > $g ? 'tông xanh dương' : 'tông trung tính'));

            return [
                'styles' => $style,
                'colors' => $color.' ('.sprintf('#%02x%02x%02x', $r, $g, $b).')',
                'fabric' => $brightness,
                'subject' => 'hình ảnh sản phẩm',
                'feeling' => $style.', '.$brightness,
                'keywords' => [$color, $style],
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------- stage 2: generate

    /**
     * @param array $input ['name','category','brand','hint','short_description']
     * @param ?array $imageAnalysis cached/fresh vision result
     */
    public function generate(array $input, ?array $imageAnalysis = null): array
    {
        $this->startBudget();

        $prompt = $this->buildPrompt($input, $imageAnalysis);
        // Truyền maxTokens tường minh: với Gemini ép responseMimeType=application/json
        // (đầu ra đúng JSON → dừng sớm, không trả dài dòng → nhanh hơn, đỡ tốn token).
        $result = $this->attempt('text', $prompt, null, $this->maxTokens);

        if (is_array($result)) {
            $result['source'] = 'ai';
            $result['reason'] = 'ok';
            $result['attempts'] = $this->attempts;

            return $result;
        }

        return $this->stub($input, $imageAnalysis);
    }

    /**
     * Tinh chỉnh (fine-tune) AI theo một mục tiêu riêng: tên, danh sách tên,
     * mô tả hay SEO. Luôn nhìn TOÀN BỘ dữ liệu hiện tại của form + hồ sơ thương
     * hiệu (trang Giới thiệu) trong quá trình suy luận.
     *
     * @param array $input dữ liệu toàn cục hiện tại: name, category, brand, tags,
     *                     short_description, description, price, compare_price,
     *                     cost_price, stock, meta_title, meta_description +
     *                     'target' (all|name|names|description|seo) + 'prompt'
     * @param ?array $imageAnalysis cached/fresh vision result
     */
    public function refine(array $input, ?array $imageAnalysis = null): array
    {
        $this->startBudget();

        $target = (string) ($input['target'] ?? 'all');
        if (! in_array($target, ['all', 'name', 'names', 'description', 'desc_variants', 'seo'], true)) {
            $target = 'all';
        }
        $input['target'] = $target;

        // Cache kết quả theo (target + prompt + TOÀN BỘ dữ liệu + hồ sơ thương hiệu):
        // bấm lại đúng chip/prompt với dữ liệu không đổi → trả về tức thì, không gọi model.
        $cacheKey = 'product_ai:refine:'.md5(json_encode([
            't' => $target,
            'p' => $input['prompt'] ?? '',
            'd' => $input,
            'b' => $this->brandContext(),
            'i' => $imageAnalysis,
        ]));
        if ($this->refineCacheTtl > 0) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $prompt = $this->buildRefinePrompt($input, $imageAnalysis, $target);
        // Ngân sách token theo hạng mục — đầu ra NGẮN cần ít token → model trả xong
        // sớm hơn nhiều (gõ 5 cái tên không cần 700 token như viết cả trang HTML).
        $tokens = $this->refineTokens($target);
        $result = $this->attempt('text', $prompt, null, $tokens);

        if (is_array($result)) {
            $result['source'] = 'ai';
            $result['target'] = $target;
            $result['reason'] = 'ok';
            $result['attempts'] = $this->attempts;

            // Chuẩn hoá danh sách tên: luôn là mảng chuỗi, tối đa 8 gợi ý.
            if ($target === 'names') {
                $result['names'] = array_slice(array_values(array_filter(
                    array_map(fn ($n) => is_string($n) ? trim($n) : '', (array) ($result['names'] ?? [])),
                    fn ($n) => $n !== ''
                )), 0, 8);
            }

            if ($this->refineCacheTtl > 0) {
                Cache::put($cacheKey, $result, $this->refineCacheTtl);
            }

            return $result;
        }

        $stub = $this->stubRefine($input, $imageAnalysis, $target);
        // Kết quả THẤT BẠI chỉ cache rất ngắn (chống spam-click trong vài giây) —
        // KHÔNG cache 10 phút như kết quả thật. Nếu không, sau khi quota/rate-limit
        // hồi lại, mọi lần tinh chỉnh vẫn trả stub cũ → "sinh thì được mà tinh
        // chỉnh thì không".
        if ($this->refineStubCacheTtl > 0) {
            Cache::put($cacheKey, $stub, $this->refineStubCacheTtl);
        }

        return $stub;
    }

    /**
     * Ngân sách token output theo mục tiêu tinh chỉnh (config DB → env → default).
     * Càng ít token càng nhanh; ngưỡng dưới đủ để JSON không bị cắt giữa chừng.
     */
    protected function refineTokens(string $target): int
    {
        return match ($target) {
            'names' => max(96, (int) product_ai_config('refine_names_tokens', 500)),
            'name' => max(96, (int) product_ai_config('refine_name_tokens', 350)),
            'seo' => max(128, (int) product_ai_config('refine_seo_tokens', 650)),
            'description' => max(256, (int) product_ai_config('refine_desc_tokens', 1500)),
            'desc_variants' => max(512, (int) product_ai_config('refine_desc_variants_tokens', 4000)),
            default => $this->maxTokens,
        };
    }

    /**
     * SINGLE multimodal call — the model sees the image AND writes the full
     * content + SEO in ONE shot (exactly like the Studio "Gợi ý từ ảnh" card,
     * which the user confirmed works). No fragile vision→text two-step, no
     * separate image-analysis round-trip: one image + one long prompt = one answer.
     */
    public function generateFromImage(array $input, string $imagePath, bool $force = false): array
    {
        $this->startBudget();

        $result = $this->attempt('vision', $this->buildPrompt($input, null, true), $imagePath, $this->maxTokens);

        if (is_array($result)) {
            $result['source'] = 'ai';
            $result['reason'] = 'ok';
            $result['image_analyzed'] = true;
            $result['analysis_cached'] = false;
            $result['attempts'] = $this->attempts;

            return $result;
        }

        // Degrade to the TEXT path (offline GD analysis feeds the stub if needed).
        $out = $this->generate($input, $this->offlineAnalysis($imagePath));
        $out['image_analyzed'] = true;

        return $out;
    }

    // ------------------------------------------------------------- provider attempts

    /**
     * Try each provider in the configured order (qwen first). Returns a parsed
     * JSON result array, or null when no provider succeeded (caller falls back).
     */
    protected function attempt(string $kind, string $prompt, ?string $imagePath = null, ?int $maxTokens = null): ?array
    {
        if (! product_ai_enabled()) {
            $this->record('system', 'AI Sản phẩm đang bị tắt trong cài đặt (product_ai_enabled=0)');

            return null;
        }

        foreach ($this->providers as $provider) {
            if ($this->timedOut()) {
                return null;
            }

            // DeepSeek is TEXT-ONLY: skip it for the vision stage (image analysis)
            // instead of wasting budget probing a model that can't see images.
            if ($provider === 'deepseek' && $imagePath !== null) {
                continue;
            }

            $result = match ($provider) {
                'qwen' => $this->attemptQwen($kind, $prompt, $imagePath, $maxTokens),
                'gemini' => $this->attemptGemini($kind, $prompt, $imagePath, $maxTokens),
                'deepseek' => $this->attemptDeepseek($kind, $prompt, $maxTokens),
                default => null,
            };

            if (is_array($result)) {
                return $result;
            }
        }

        return null;
    }

    protected function attemptQwen(string $kind, string $prompt, ?string $imagePath, ?int $maxTokens = null): ?array
    {
        $models = array_slice(array_values(
            $kind === 'vision' ? product_ai_qwen_vision_models() : product_ai_qwen_text_models()
        ), 0, $this->maxModels);
        if (empty($models)) {
            $models = ['qwen3.8-flash'];
        }

        $keys = array_values(array_unique(array_filter(
            studio_qwen_credentials($kind === 'vision' ? 'vision' : 'prompt')
        )));
        if (empty($keys)) {
            $keys = array_values(array_filter([studio_api_key('qwen'), studio_api_key('dashscope')]));
        }
        $keys = array_slice($keys, 0, $this->maxKeys);

        if (empty($keys)) {
            $this->record('qwen', 'no qwen/dashscope key configured ('.$kind.')');

            return null;
        }

        // Cost/latency guard: put the last key that WORKED first, and drop keys
        // that 429'd (quota) in the last 10 minutes so we don't keep re-probing them.
        $keyId = fn (string $k) => sha1($k);
        $goodKey = Cache::get('product_ai_good_key:'.$kind);
        usort($keys, fn ($a, $b) => ($keyId($a) === $goodKey ? -1 : 0) <=> ($keyId($b) === $goodKey ? -1 : 0));
        $fresh = array_values(array_filter($keys, fn ($k) => ! Cache::get('product_ai_bad_key:'.$kind.':'.$keyId($k))));
        if (! empty($fresh)) {
            $keys = $fresh;
        }

        $keyPrefix = fn (string $k) => substr($k, 0, 8).'…';

        foreach ($keys as $key) {
            if ($this->timedOut()) {
                $this->record('qwen', 'budget exhausted ('.$kind.')');

                return null;
            }

            // KEY-AWARE model list: qwen3.8-flash/max only exist on the Token-Plan
            // host (sk-sp-…); on Pay-As-You-Go (sk-…/sk-ws-…) use the classic
            // dashscope-intl models (qwen-plus/turbo text, qwen-vl-max/plus vision)
            // otherwise the request HANGS until timeout.
            $isPlan = str_starts_with($key, 'sk-sp-');
            $keyModels = $isPlan
                ? $models
                : ($kind === 'vision' ? product_ai_qwen_paygo_vision_models() : product_ai_qwen_paygo_text_models());
            $keyModels = array_slice(array_values(array_unique($keyModels)), 0, $this->maxModels);

            $base = dashscope_base_url($key).'/compatible-mode/v1';
            foreach ($keyModels as $model) {
                if ($this->timedOut()) {
                    $this->record('qwen', 'budget exhausted ('.$kind.')');

                    return null;
                }
                $content = $imagePath !== null
                    ? [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageDataUri($imagePath)]],
                    ]
                    : $prompt;

                $effMaxTokens = $maxTokens ?? ($kind === 'vision' ? $this->visionMaxTokens : $this->maxTokens);

                try {
                    $resp = Http::withToken($key)->timeout($this->remainingTimeout($effMaxTokens))->post($base.'/chat/completions', [
                        'model' => $model,
                        'messages' => [['role' => 'user', 'content' => $content]],
                        'max_tokens' => $effMaxTokens,
                        'response_format' => ['type' => 'json_object'],
                    ]);
                } catch (\Throwable $e) {
                    // Network/timeout chưa chắc là lỗi vĩnh viễn của key — có thể chỉ là
                    // model này chậm ở lượt này, nên vẫn thử model kế tiếp cùng key.
                    // NHƯNG nếu cùng một key (cùng kind) timeout LIÊN TIẾP (host treo /
                    // key chết), đánh dấu bad TẠM THỜI để các request sau bỏ qua ngay,
                    // khỏi đốt ~30s chờ mỗi lần. Cache tách theo kind nên key text chậm
                    // không ảnh hưởng vision.
                    $this->record('qwen', 'network/timeout ('.$kind.', '.$model.', key '.$keyPrefix($key).')');

                    $timeoutCountKey = 'product_ai_timeouts:'.$kind.':'.$keyId($key);
                    $timeouts = (int) Cache::get($timeoutCountKey, 0) + 1;
                    Cache::put($timeoutCountKey, $timeouts, 300);
                    if ($timeouts >= 2) {
                        Cache::put('product_ai_bad_key:'.$kind.':'.$keyId($key), true, 300);
                        $this->record('qwen', 'key tạm bỏ qua sau '.$timeouts.' timeouts ('.$kind.', key '.$keyPrefix($key).')');

                        continue 2; // bỏ qua key này ngay, sang key kế tiếp
                    }

                    continue;
                }

                $status = $resp->status();
                $body = (string) $resp->body();

                // 429 = rate/quota limit on this key -> remember it and skip to the next key.
                // RateQuota (giới hạn tần suất) chỉ TẠM THỜI, hồi sau vài chục giây →
                // backoff ngắn 45s; AllocationQuota (hết hạn mức thật) → backoff dài 600s.
                // (Trước đây mọi 429 đều bị "cấm" 10 phút khiến tinh chỉnh chết lâu
                // hơn nhiều so với generate chỉ vì studio chạy song song dính rate limit.)
                if ($status === 429 || is_qwen_quota_error($body)) {
                    $lower = strtolower($body);
                    $isRateLimit = str_contains($lower, 'ratelimit') || str_contains($lower, 'rate limit')
                        || str_contains($lower, 'rate_limit') || str_contains($lower, 'ratequota');
                    Cache::put('product_ai_bad_key:'.$kind.':'.$keyId($key), true, $isRateLimit ? 45 : 600);
                    $this->record('qwen', ($isRateLimit ? '429 rate-limit' : '429/quota').' ('.$kind.', '.$model.', key '.$keyPrefix($key).')');

                    continue 2;
                }
                // Model not on this host/account -> try the next model (free).
                if ($status === 404 || str_contains(strtolower($body), 'model_not_found') || str_contains(strtolower($body), 'model not exist')) {
                    $this->record('qwen', 'model not found ('.$kind.', '.$model.', key '.$keyPrefix($key).')');

                    continue;
                }
                if ($resp->ok()) {
                    $json = $this->parseJson((string) data_get($resp->json(), 'choices.0.message.content'));
                    if ($json) {
                        Cache::put('product_ai_good_key:'.$kind, $keyId($key), 3600);
                        Cache::forget('product_ai_bad_key:'.$kind.':'.$keyId($key));
                        Cache::forget('product_ai_timeouts:'.$kind.':'.$keyId($key));
                        $this->attempts[] = 'qwen: ok ('.$kind.', '.$model.', key '.$keyPrefix($key).')';

                        return $json;
                    }
                    $this->record('qwen', 'empty/invalid JSON ('.$kind.', '.$model.')');

                    return null;
                }

                // 401/403 = key KHÔNG hợp lệ/hết hạn (vd key Token-Plan sk-sp-… bị thu hồi).
                // Đây là lỗi CỦA RIÊNG KEY NÀY, không phải lỗi provider → đánh dấu key hỏng
                // và thử key kế tiếp (pay-go chẳng hạn). TRƯỚC ĐÂY return null ngay khiến
                // cả provider chết theo: tinh chỉnh hỏng dù vẫn còn key chạy được.
                if ($status === 401 || $status === 403) {
                    Cache::put('product_ai_bad_key:'.$kind.':'.$keyId($key), true, 600);
                    $this->record('qwen', 'HTTP '.$status.' invalid key ('.$kind.', '.$model.', key '.$keyPrefix($key).')');

                    continue 2;
                }

                $this->record('qwen', 'HTTP '.$status.' ('.$kind.', '.$model.', key '.$keyPrefix($key).'): '.substr($body, 0, 120));

                return null; // other error -> stop trying this provider
            }
        }

        return null;
    }

    protected function attemptDeepseek(string $kind, string $prompt, ?int $maxTokens = null): ?array
    {
        $key = studio_api_key('deepseek');
        if (! $key) {
            $this->record('deepseek', 'no deepseek key configured');

            return null;
        }

        if ($this->timedOut()) {
            $this->record('deepseek', 'budget exhausted ('.$kind.')');

            return null;
        }

        $model = product_ai_deepseek_model();
        $effMaxTokens = $maxTokens ?? $this->maxTokens;

        try {
            $resp = Http::withToken($key)->timeout($this->remainingTimeout($effMaxTokens))
                ->post(deepseek_base_url($key).'/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'temperature' => $this->temperature,
                    'max_tokens' => $effMaxTokens,
                    'response_format' => ['type' => 'json_object'],
                    'stream' => false,
                ]);

            if ($resp->ok()) {
                $json = $this->parseJson((string) data_get($resp->json(), 'choices.0.message.content'));
                if ($json) {
                    $this->attempts[] = 'deepseek: ok ('.$kind.', '.$model.')';

                    return $json;
                }
                $this->record('deepseek', 'empty/invalid JSON ('.$kind.', '.$model.')');
            } else {
                $this->record('deepseek', 'HTTP '.$resp->status().' ('.$kind.', '.$model.'): '.substr((string) $resp->body(), 0, 120));
            }
        } catch (\Throwable $e) {
            $this->record('deepseek', 'network/timeout ('.$kind.', '.$model.')');
        }

        return null;
    }

    protected function attemptGemini(string $kind, string $prompt, ?string $imagePath, ?int $maxTokens = null): ?array
    {
        $key = studio_api_key('gemini');
        if (! $key) {
            $this->record('gemini', 'no gemini key configured');

            return null;
        }

        if ($this->timedOut()) {
            $this->record('gemini', 'budget exhausted ('.$kind.')');

            return null;
        }

        $model = $kind === 'vision' ? product_ai_gemini_vision_model() : product_ai_gemini_text_model();
        $parts = [['text' => $prompt]];
        if ($imagePath !== null) {
            [$b64, $mime] = $this->imageBase64($imagePath);
            $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $b64]];
        }

        try {
            $body = ['contents' => [['parts' => $parts]]];
        if ($maxTokens !== null) {
            $body['generationConfig'] = ['responseMimeType' => 'application/json', 'maxOutputTokens' => $maxTokens];
        }
        $resp = Http::withHeaders(['x-goog-api-key' => $key])->timeout($this->remainingTimeout($maxTokens))
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', $body);
            if ($resp->ok()) {
                $json = $this->parseJson((string) data_get($resp->json(), 'candidates.0.content.parts.0.text'));
                if ($json) {
                    $this->attempts[] = 'gemini: ok ('.$kind.', '.$model.')';

                    return $json;
                }
                $this->record('gemini', 'empty/invalid JSON ('.$kind.', '.$model.')');
            } else {
                $this->record('gemini', 'HTTP '.$resp->status().' ('.$kind.', '.$model.'): '.substr((string) $resp->body(), 0, 120));
            }
        } catch (\Throwable $e) {
            $this->record('gemini', 'network/timeout ('.$kind.', '.$model.')');
        }

        return null;
    }

    // ------------------------------------------------------------- prompts

    /**
     * Prompt có cấu trúc cho tinh chỉnh theo mục tiêu. Luôn gồm 3 khối:
     * HỒ SƠ THƯƠNG HIỆU (trang Giới thiệu), DỮ LIỆU SẢN PHẨM HIỆN TẠI (toàn cục),
     * và YÊU CẦU TIN CHỈNH của người dùng.
     */
    protected function buildRefinePrompt(array $input, ?array $imageAnalysis, string $target): string
    {
        $name = trim((string) ($input['name'] ?? '')) ?: 'sản phẩm thời trang/phong cách sống';
        $category = trim((string) ($input['category'] ?? ''));
        $brand = trim((string) ($input['brand'] ?? ''));
        $tags = trim((string) ($input['tags'] ?? ''));
        $short = trim((string) ($input['short_description'] ?? ''));
        // Cắt bớt trường dài (mô tả có thể lên tới hàng chục nghìn ký tự) để prompt
        // ngắn → model prefill nhanh hơn nhiều; vẫn giữ đủ ý chính.
        $description = $this->clampText((string) ($input['description'] ?? ''), 2000);
        $price = (string) ($input['price'] ?? '');
        $metaTitle = trim((string) ($input['meta_title'] ?? ''));
        $metaDesc = trim((string) ($input['meta_description'] ?? ''));
        $userPrompt = $this->clampText((string) ($input['prompt'] ?? ''), 800);

        $img = '';
        if ($imageAnalysis) {
            $st = $imageAnalysis['styles'] ?? '';
            $co = $imageAnalysis['colors'] ?? '';
            $fa = $imageAnalysis['fabric'] ?? '';
            $su = $imageAnalysis['subject'] ?? '';
            $fe = $imageAnalysis['feeling'] ?? '';
            $kw = implode(', ', (array) ($imageAnalysis['keywords'] ?? []));
            $img = "\nDựa trên phân tích ảnh sản phẩm: phong cách={$st}, màu sắc={$co}, chất liệu={$fa}, chủ thể={$su}, cảm giác={$fe}, từ khóa={$kw}";
        }

        $schema = match ($target) {
            'names' => '"names": ["5 tên sản phẩm, mỗi tên <=80 ký tự, phù hợp giọng thương hiệu"]',
            'name' => '"suggested_name": "1 tên sản phẩm hấp dẫn <=80 ký tự"',
            'description' => '"short_description": "2-3 câu <=240 ký tự", "description": "HTML mô tả chi tiết: 4-5 mục <h3> (Phong cách / Chất liệu / Màu sắc / Phù hợp / Bảo quản) + <p>/<ul><li>, tổng ~120-150 từ"',
            'seo' => '"meta_title": "SEO <=60 ký tự", "meta_description": "SEO 120-160 ký tự", "tags": ["3-4 tag"]',
            'desc_variants' => '"variants": [{"label": "2-3 từ mô tả phong cách", "short_description": "2-3 câu <=240 ký tự", "description": "HTML mô tả chi tiết CHUYÊN SÂU: 7-8 mục <h3> (Câu chuyện & cảm hứng / Phong cách & dáng / Chất liệu & hoàn thiện / Màu sắc & họa tiết / Gợi ý phối đồ / Phù hợp với ai / Bảo quản) + <p>/<ul><li>, tổng ~250-300 từ"}] (2-3 phương án khác biệt rõ rệt: VD một bản nhấn phong cách, một bản nhấn chất liệu, một bản nhấn công dụng)',
            default => '"suggested_name": "...", "short_description": "...", "description": "HTML...", "meta_title": "...", "meta_description": "...", "tags": ["..."]',
        };

        $instruction = $userPrompt !== ''
            ? "YÊU CẦU TIN CHỈNH CỦA NGƯỜI DÙNG: {$userPrompt}"
            : 'Hãy cải thiện, làm giàu nội dung cho mục tiêu "'.$target.'" sao cho hấp dẫn và đúng giọng thương hiệu.';

        return <<<PROMPT
Bạn là chuyên gia content & SEO thương mại điện tử thời trang Việt Nam.{$img}

=== HỒ SƠ THƯƠNG HIỆU (lấy từ trang "/admin/pages/about" của cửa hàng — ĐỌC KỸ và luôn viết đúng giọng văn, giá trị này) ===
{$this->clampText($this->brandContext(), 1200)}
=== HẾT HỒ SƠ THƯƠNG HIỆU ===

=== DỮ LIỆU SẢN PHẨM HIỆN TẠI (toàn cục — giữ nguyên những gì không cần đổi) ===
Tên: {$name}
Danh mục: {$category}
Thương hiệu sản phẩm: {$brand}
Thẻ: {$tags}
Mô tả ngắn: {$short}
Mô tả chi tiết (HTML): {$description}
Giá: {$price}
Meta title: {$metaTitle}
Meta description: {$metaDesc}
=== HẾT DỮ LIỆU ===

{$instruction}

Trả về JSON hợp lệ DUY NHẤT (không markdown, không giải thích) đúng schema mục tiêu "{$target}":
{{$schema}
}
PROMPT;
    }

    protected function buildPrompt(array $input, ?array $imageAnalysis, bool $imageAttached = false): string
    {
        $name = ($input['name'] ?? '') ?: 'sản phẩm thời trang/phong cách sống';
        $category = $input['category'] ?? '';
        $brand = $input['brand'] ?? '';
        $hint = $input['hint'] ?? '';
        $currentShort = $input['short_description'] ?? '';
        $currentTags = $input['tags'] ?? '';
        $currentDesc = $this->clampText((string) ($input['description'] ?? ''), 2000);
        $currentPrice = $input['price'] ?? '';
        $currentMetaTitle = $input['meta_title'] ?? '';
        $currentMetaDesc = $input['meta_description'] ?? '';

        $img = '';
        if ($imageAnalysis) {
            $styles = $imageAnalysis['styles'] ?? '';
            $colors = $imageAnalysis['colors'] ?? '';
            $fabric = $imageAnalysis['fabric'] ?? '';
            $subject = $imageAnalysis['subject'] ?? '';
            $feeling = $imageAnalysis['feeling'] ?? '';
            $keywords = implode(', ', (array) ($imageAnalysis['keywords'] ?? []));
            $img = "\nDựa trên phân tích ảnh sản phẩm: phong cách={$styles}, màu sắc={$colors}, chất liệu={$fabric}, chủ thể={$subject}, cảm giác={$feeling}, từ khóa={$keywords}";
        }

        // Làm giàu: nếu đã có short_description thì yêu cầu cải thiện dựa trên đó.
        $refine = $currentShort ? "\nNội dung đã viết (hãy dùng làm nền, giữ ý chính, cải thiện làm giàu hơn): {$currentShort}" : '';

        $basis = $imageAttached
            ? 'HÃY NHÌN ẢNH SẢN PHẨM ĐÍNH KÈM và dựa trên đó'
            : 'dựa trên phân tích ảnh + thông tin người dùng';

        $brandBlock = "\n=== HỒ SƠ THƯƠNG HIỆU (lấy từ trang \"/admin/pages/about\" của cửa hàng — ĐỌC KỸ, viết đúng giọng văn & giá trị) ===\n".$this->clampText($this->brandContext(), 1200)."\n=== HẾT HỒ SƠ THƯƠNG HIỆU ===";

        return <<<PROMPT
Bạn là chuyên gia content & SEO thương mại điện tử thời trang Việt Nam. Viết NGẮN GỌN nhưng hấp dẫn, {$basis}.
{$brandBlock}
{$img}
{$refine}

Thông tin người dùng: Tên={$name} · Danh mục={$category} · Thương hiệu={$brand} · Ý tưởng={$hint}
Dữ liệu hiện tại: Giá={$currentPrice} · Thẻ={$currentTags}
Mô tả ngắn hiện tại: {$currentShort}
Mô tả chi tiết hiện tại: {$currentDesc}
Meta title hiện tại: {$currentMetaTitle}
Meta description hiện tại: {$currentMetaDesc}

Trả về JSON hợp lệ DUY NHẤT (không markdown, không giải thích):
{
  "suggested_name": "tên hấp dẫn <=80 ký tự",
  "brand": "thương hiệu",
  "short_description": "2-3 câu <=240 ký tự",
  "description": "HTML ngắn: 4-5 mục <h3> (Phong cách / Chất liệu / Màu sắc / Phù hợp / Bảo quản) + <p>/<ul><li>, tổng ~120-150 từ",
  "meta_title": "SEO <=60 ký tự",
  "meta_description": "SEO 120-160 ký tự",
  "tags": ["3-4 tag"]
}
PROMPT;
    }

    // ------------------------------------------------------------- helpers

    /**
     * Cắt ngắn văn bản về tối đa $max ký tự — giữ nguyên nếu đã ngắn hơn.
     * Dùng để giảm kích thước prompt đầu vào (prefill nhanh hơn), đặc biệt
     * cho mô tả chi tiết có thể hàng chục nghìn ký tự.
     */
    protected function clampText(?string $s, int $max): string
    {
        $s = trim((string) $s);

        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max).'…';
    }

    protected function parseJson(string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        // Strip markdown code fences (```json … ``` or ```…```).
        $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);

        // Extract the first { … } span (models sometimes prefix a note).
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $json = json_decode($text, true);
        if (is_array($json)) {
            return $json;
        }

        // Common LLM slip: trailing commas before } or ].
        $json = json_decode((string) preg_replace('/,\s*([}\]])/', '$1', $text), true);
        if (is_array($json)) {
            return $json;
        }

        // Last resort: unescape lone control characters that break JSON strings.
        $repaired = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"/s', function ($m) {
            $inner = (string) $m[1];
            $inner = str_replace(["\n", "\r", "\t"], ['\\n', '\\r', '\\t'], $inner);

            return '"'.$inner.'"';
        }, $text);
        $json = json_decode((string) $repaired, true);
        if (is_array($json)) {
            return $json;
        }

        // Output bị CẮT giữa chừng vì chạm max_tokens (rất hay gặp ở tinh chỉnh
        // token thấp): đóng chuỗi/ngoặc còn thiếu, chặt bỏ mảnh cuối dang dở.
        // Cứu được phần lớn kết quả thay vì fallback stub + tốn lượt gọi lại.
        return $this->repairTruncatedJson($text);
    }

    /**
     * Khôi phục JSON bị cắt cụt: đóng chuỗi/ngoặc đang mở, bỏ mảnh cuối chưa
     * hoàn chỉnh (key thiếu value, value dở dang), tối đa vài bước lùi.
     */
    protected function repairTruncatedJson(string $text): ?array
    {
        $candidate = trim($text);

        for ($round = 0; $round < 6; $round++) {
            $closed = $this->closeUnterminatedJson($candidate);
            if ($closed !== null) {
                $decoded = json_decode($closed, true);
                if (is_array($decoded) && $decoded !== []) {
                    return $decoded;
                }
            }

            // Lùi về dấu phẩy cấu trúc gần nhất rồi thử lại.
            $pos = strrpos($candidate, ',');
            if ($pos === false || $pos < 1) {
                return null;
            }
            $candidate = rtrim(substr($candidate, 0, $pos));
        }

        return null;
    }

    /** Đóng mọi chuỗi/ngoặc còn mở; trả về chuỗi JSON "đủ hình" hoặc null nếu rỗng. */
    protected function closeUnterminatedJson(string $text): ?string
    {
        $stack = [];
        $inStr = false;
        $esc = false;

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }

                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{') {
                $stack[] = '}';
            } elseif ($c === '[') {
                $stack[] = ']';
            } elseif ($c === '}' || $c === ']') {
                array_pop($stack);
            }
        }

        if ($inStr) {
            if ($esc) {
                $text = substr($text, 0, -1); // bỏ escape dang dở
            }
            $text .= '"';
        }

        $text = rtrim($text);
        if ($text === '' || $text === '{' || $text === '[') {
            return null;
        }
        // Key vừa gõ xong nhưng chưa có value → gán chuỗi rỗng để JSON hợp lệ.
        if (str_ends_with($text, ':')) {
            $text .= '""';
        }
        while (str_ends_with($text = rtrim($text), ',')) {
            $text = rtrim(substr($text, 0, -1));
        }

        return $text.implode('', array_reverse($stack));
    }

    protected function imageCacheKey(string $imagePath): string
    {
        $fingerprint = is_file($imagePath) ? sha1_file($imagePath) : md5($imagePath);

        return $fingerprint.'|'.implode(',', $this->providers);
    }

    protected function imageBase64(string $path): array
    {
        $mime = 'image/jpeg';
        $contents = @file_get_contents($path);
        $data = base64_encode((string) $contents);
        if (function_exists('getimagesize') && ($info = @getimagesize($path))) {
            $mime = $info['mime'] ?? $mime;
        }
        // Downscale aggressively so the vision request stays small/fast.
        if (function_exists('imagecreatefromstring')) {
            try {
                $img = studio_image_decode((string) $contents);
                if ($img) {
                    $w = imagesx($img); $h = imagesy($img);
                    $scale = min(1, $this->downscaleMax / max($w, $h));
                    if ($scale < 1) {
                        $nw = (int) ($w * $scale); $nh = (int) ($h * $scale);
                        $dst = imagecreatetruecolor($nw, $nh);
                        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                        ob_start(); imagejpeg($dst, null, 88); $data = base64_encode((string) ob_get_clean());
                    }
                }
            } catch (\Throwable $e) {
                // use raw
            }
        }
        return [$data, $mime];
    }

    protected function imageDataUri(string $path): string
    {
        [$b64, $mime] = $this->imageBase64($path);

        return 'data:'.$mime.';base64,'.$b64;
    }

    protected function stub(array $input, ?array $imageAnalysis): array
    {
        $base = ($input['name'] ?? 'Sản phẩm') ?: 'Sản phẩm thời trang';
        $category = $input['category'] ?? '';
        $brand = $input['brand'] ?? 'Trillfa';

        $style = $imageAnalysis['styles'] ?? '';
        $fabric = $imageAnalysis['fabric'] ?? '';
        $color = $imageAnalysis['colors'] ?? '';
        $subject = $imageAnalysis['subject'] ?? '';
        $feeling = $imageAnalysis['feeling'] ?? '';

        $descFabric = $fabric ?: 'chất liệu cao cấp, thoáng mát và bền bỉ';
        $descColor = $color ?: 'tông màu trung tính dễ phối đồ';
        $descStyle = $style ?: 'tối giản, tinh tế, hiện đại';
        $descGarment = $category ?: 'sản phẩm thời trang/phong cách sống';

        // Escape mọi biến nguồn user/AI trước khi ghép HTML (S4 — chống stored XSS;
        // description render bằng v-html/{!! !!} ở storefront).
        $description = '<h3>Phong cách</h3>'
            .'<p>'.e($base).' mang phong cách '.e($descStyle).', tôn dáng và thoải mái — dễ dàng kết hợp trong nhiều hoàn cảnh.</p>'
            .'<h3>Loại trang phục &amp; dáng</h3>'
            .'<p>'.e($descGarment).' với đường cắt tối giản, form cân đối, phù hợp vóc dáng người Việt.</p>'
            .'<h3>Chất liệu &amp; chất lượng</h3>'
            .'<ul><li>'.e($descFabric).', tạo cảm giác dễ chịu khi mặc</li><li>Đường may chắc chắn, bền bỉ theo thời gian</li></ul>'
            .'<h3>Màu sắc &amp; họa tiết</h3>'
            .'<p>'.e($descColor).($subject ? ', phù hợp với ' . e($subject) : '').'.</p>'
            .'<h3>Thiết kế chi tiết</h3>'
            .'<ul><li>Chi tiết tối giản, tinh tế, dễ phối đồ</li><li>Form dáng tôn dáng, thoải mái khi vận động</li></ul>'
            .'<h3>Phù hợp</h3>'
            .'<p>Dễ phối cho công sở, dạo phố hoặc những buổi gặp gỡ nhẹ nhàng.</p>'
            .'<h3>Bảo quản &amp; lưu ý</h3>'
            .'<ul><li>Giặt nhẹ, tránh nước tẩy mạnh</li><li>Ủi ở nhiệt độ thấp để giữ form</li><li>Đổi trả trong 7 ngày</li></ul>'
            .'<blockquote>"'.e($feeling ?: 'Tối giản không phải là ít, mà là đủ.').' — '.e($brand).'"</blockquote>';

        return [
            'suggested_name' => $base,
            'brand' => $brand,
            'short_description' => ($category ? $category.' ' : '').$base.' '.($fabric ? $fabric.' ' : '').'— thiết kế '.$descStyle.', '.$descFabric.', dễ phối đồ và bền bỉ.',
            'description' => $description,
            'meta_title' => $base.' | '.$brand,
            'meta_description' => 'Khám phá '.$base.' '.($fabric ? $fabric.' ' : '').'— '.$descStyle.', chất liệu cao cấp, tôn dáng, giao nhanh, đổi trả dễ dàng.',
            'tags' => array_values(array_filter([$category, $style, $color, $fabric, 'thời trang', 'phong cách', 'trillfa'])),
            'source' => 'stub',
            'reason' => $this->failureReason(),
            'attempts' => $this->attempts,
        ];
    }

    /**
     * Fallback offline (không có key/quota hết): trả về các biến thể an toàn,
     * luôn dựa trên dữ liệu hiện tại + hồ sơ thương hiệu.
     */
    protected function stubRefine(array $input, ?array $imageAnalysis, string $target): array
    {
        $name = trim((string) ($input['name'] ?? '')) ?: 'Sản phẩm thời trang';
        $brand = trim((string) ($input['brand'] ?? '')) ?: 'Trillfa';

        $variants = [
            $name,
            $name.' — Phiên bản '.date('Y'),
            $name.' | '.$brand,
            $name.' — Bản giới hạn',
            $name.' Cao cấp',
        ];

        $result = match ($target) {
            'names' => [
                'names' => $variants,
                'short_description' => (string) ($input['short_description'] ?? ''),
                'description' => (string) ($input['description'] ?? ''),
                'meta_title' => (string) ($input['meta_title'] ?? ''),
                'meta_description' => (string) ($input['meta_description'] ?? ''),
                'tags' => $this->normalizeTags($input['tags'] ?? ''),
            ],
            'name' => [
                'suggested_name' => $variants[0],
                'short_description' => (string) ($input['short_description'] ?? ''),
                'description' => (string) ($input['description'] ?? ''),
                'meta_title' => (string) ($input['meta_title'] ?? ''),
                'meta_description' => (string) ($input['meta_description'] ?? ''),
                'tags' => $this->normalizeTags($input['tags'] ?? ''),
            ],
            'description' => [
                'short_description' => trim((string) ($input['short_description'] ?? '')) ?: $name.' — '.$brand.', thiết kế tối giản, chất liệu cao cấp, dễ phối đồ và bền bỉ.',
                'description' => trim((string) ($input['description'] ?? '')) ?: $this->stubDescription($name, $input, $imageAnalysis),
            ],
            'seo' => [
                'meta_title' => trim((string) ($input['meta_title'] ?? '')) ?: mb_substr($name, 0, 55).' | '.$brand,
                'meta_description' => trim((string) ($input['meta_description'] ?? '')) ?: 'Khám phá '.$name.' — chất liệu cao cấp, tôn dáng, giao nhanh, đổi trả dễ dàng.',
                'tags' => $this->normalizeTags($input['tags'] ?? ''),
            ],
            'desc_variants' => [
                'variants' => [
                    [
                        'label' => 'Phong cách & cảm hứng',
                        'short_description' => trim((string) ($input['short_description'] ?? '')) ?: $name.' — '.$brand.', thiết kế tối giản, tôn dáng.',
                        'description' => trim((string) ($input['description'] ?? '')) ?: $this->stubDescription($name, $input, $imageAnalysis),
                    ],
                    [
                        'label' => 'Chất liệu & bảo quản',
                        'short_description' => $name.' với chất liệu cao cấp, bền bỉ, thoáng mát.',
                        'description' => '<h3>Chất liệu</h3><p>'.e($imageAnalysis['fabric'] ?? 'Chất liệu cao cấp, thoáng mát').'</p><h3>Bảo quản</h3><ul><li>Giặt nhẹ, tránh nước tẩy mạnh</li><li>Ủi ở nhiệt độ thấp</li><li>Đổi trả trong 7 ngày</li></ul>',
                    ],
                ],
            ],
            default => [
                'suggested_name' => $name,
                'short_description' => (string) ($input['short_description'] ?? ''),
                'description' => (string) ($input['description'] ?? ''),
                'meta_title' => (string) ($input['meta_title'] ?? ''),
                'meta_description' => (string) ($input['meta_description'] ?? ''),
                'tags' => $this->normalizeTags($input['tags'] ?? ''),
            ],
        };

        $result['target'] = $target;
        $result['source'] = 'stub';
        $result['reason'] = $this->failureReason();
        $result['attempts'] = $this->attempts;

        return $result;
    }

    protected function stubDescription(string $name, array $input, ?array $imageAnalysis): string
    {
        $category = (string) ($input['category'] ?? '');
        $style = $imageAnalysis['styles'] ?? '';
        $fabric = $imageAnalysis['fabric'] ?? '';
        $color = $imageAnalysis['colors'] ?? '';

        return '<h3>Phong cách</h3>'
            .'<p>'.e($name).' mang phong cách '.e($style ?: 'tối giản, tinh tế, hiện đại').', tôn dáng và thoải mái — dễ dàng kết hợp trong nhiều hoàn cảnh.</p>'
            .'<h3>Loại trang phục &amp; dáng</h3>'
            .'<p>'.e($category ?: 'Sản phẩm thời trang/phong cách sống').' với đường cắt tối giản, form cân đối, phù hợp vóc dáng người Việt.</p>'
            .'<h3>Chất liệu &amp; chất lượng</h3>'
            .'<ul><li>'.e($fabric ?: 'Chất liệu cao cấp, thoáng mát và bền bỉ').'</li><li>Đường may chắc chắn, bền bỉ theo thời gian</li></ul>'
            .'<h3>Màu sắc &amp; họa tiết</h3>'
            .'<p>'.e($color ?: 'Tông màu trung tính dễ phối đồ').'.</p>'
            .'<h3>Bảo quản &amp; lưu ý</h3>'
            .'<ul><li>Giặt nhẹ, tránh nước tẩy mạnh</li><li>Ủi ở nhiệt độ thấp để giữ form</li><li>Đổi trả trong 7 ngày</li></ul>';
    }

    protected function normalizeTags(mixed $tags): array
    {
        if (is_array($tags)) {
            $raw = $tags;
        } else {
            $raw = explode(',', (string) $tags);
        }
        $out = [];
        foreach ($raw as $t) {
            $t = trim((string) $t);
            if ($t !== '' && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out ?: ['thời trang', 'trillfa', 'phong cách'];
    }

    /**
     * Human-readable failure summary for the UI — the FULL attempt chain, so it's
     * visible exactly which key/model failed (token-plan 429 vs PAYG timeout…).
     */
    private function failureReason(): string
    {
        $parts = [];
        foreach ($this->attempts as $a) {
            if (! in_array($a, $parts, true)) {
                $parts[] = $a;
            }
        }

        return $parts ? implode(' · ', $parts) : ($this->lastError ?: 'offline');
    }
}
