<?php

namespace App\Ai;

use App\Models\StudioProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * NHÚNG VĂN BẢN (embeddings) — lớp mỏng, ĐO ĐƯỢC (Việc #9 · 2026-09-26).
 *
 * VÌ SAO CẦN LỚP RIÊNG: trước đợt này cả dự án chưa từng gọi /embeddings. Đo trên production (2026-09-26):
 *   · deepseek (api.deepseek.com)        → /embeddings HTTP **404** — DeepSeek KHÔNG có dịch vụ nhúng;
 *   · ckey (api.xah.io/v1)               → text-embedding-3-small HTTP **200 · 1536 chiều**;
 *   · qwen-paygo (maas.qwencloudapi.com) → text-embedding-v3     HTTP **200 · 1024 chiều**;
 *   · fal.ai                             → tạo ảnh, không có nhúng.
 * Nên "có nhúng được hay không" KHÔNG phải câu hỏi lý thuyết — nó phụ thuộc nhà cung cấp ĐANG CÓ KHOÁ, và
 * nơi gọi phải nhận câu trả lời THẬT (null + lý do) chứ không phải một lời hứa.
 *
 * CHỌN NHÀ CUNG CẤP THEO (địa chỉ + khoá), KHÔNG theo Model Registry: nhúng không cần một model CHAT, nó
 * cần một địa chỉ có endpoint /embeddings và một khoá. Ràng buộc vào Model Registry sẽ làm cả tính năng
 * đứng im chỉ vì chưa ai khai model chat cho nhóm vai — đúng kiểu phụ thuộc không cần thiết. Thứ tự: nhà
 * cung cấp TỰ KHAI trước (bảng studio_providers, theo priority), rồi tới nhà cung cấp DỰNG SẴN có khoá.
 *
 * CHỈ HỖ TRỢ GIAO THỨC openai-compatible (/embeddings). Gemini có dạng gọi nhúng KHÁC (:embedContent) nên
 * chưa nằm trong lớp này — thiếu nó thì hệ thống lùi về tìm theo từ khoá và nói rõ, chứ không gọi sai.
 */
class EmbeddingGateway
{
    /**
     * Model nhúng theo TỪNG nhà cung cấp — số đo ở trên, không phải suy đoán.
     * Nhà cung cấp không có trong bảng này đi theo danh sách mặc định (thử lần lượt).
     */
    private const MODELS = [
        'ckey' => ['text-embedding-3-small'],
        'qwen-paygo' => ['text-embedding-v3'],
        'qwen' => ['text-embedding-v3'],
        'openai' => ['text-embedding-3-small'],
        'deepseek' => [],   // đã đo: 404 — đừng thử lại mỗi lượt
    ];

    private const DEFAULT_MODELS = ['text-embedding-3-small', 'text-embedding-v3'];

    private const CACHE_KEY = 'studio:embeddings:provider';

    private const CACHE_SECONDS = 3600;

    /**
     * Trần số văn bản cho MỘT lời gọi.
     *
     * ĐO TRÊN PRODUCTION (2026-09-26): lô **16** làm nhà cung cấp (qwen-paygo) trả lỗi cho CẢ LÔ ⇒ lập chỉ
     * mục ra 0 tài liệu dù chỉ MỘT lô hỏng. Hạ xuống 8 và thêm đường thử lại từng văn bản (xem embed()).
     */
    public const BATCH = 8;

    /** Cắt văn bản trước khi nhúng: văn bản quá dài làm hỏng cả lô, và phần đuôi hiếm khi mang nghĩa. */
    public const TEXT_MAX = 2000;

    /** Có nhà cung cấp nào nhúng được không — để giao diện NÓI THẬT trước khi người dùng bấm. */
    public function available(): bool
    {
        return $this->resolve() !== null;
    }

    /** Nhà cung cấp + model sẽ dùng (null nếu không có) — dùng cho dòng "đang dùng …" ở giao diện. */
    public function provider(): ?array
    {
        $resolved = $this->resolve();

        return $resolved === null ? null : ['provider' => $resolved['provider'], 'model' => $resolved['model']];
    }

    /**
     * Nhúng một danh sách văn bản. Trả null khi KHÔNG nhà cung cấp nào làm được — nơi gọi phải có đường lùi
     * (DesignSearchService lùi về tìm theo từ khoá và nói rõ đang chạy chế độ nào).
     *
     * @param  list<string>  $texts
     * @return array{vectors: list<list<float>>, provider: string, model: string, dims: int}|null
     */
    public function embed(array $texts): ?array
    {
        $clean = [];
        foreach ($texts as $index => $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $clean[$index] = mb_substr($text, 0, self::TEXT_MAX);
            }
        }
        if ($clean === []) {
            return null;
        }

        $resolved = $this->resolve();
        if ($resolved === null) {
            return null;
        }

        // KHOÁ LÀ CHỈ SỐ ĐẦU VÀO, không phải thứ tự trong kết quả: một văn bản hỏng bị bỏ qua thì các văn
        // bản còn lại vẫn phải gắn đúng vào tài liệu của nó.
        $vectors = [];
        $failed = 0;
        $dims = 0;

        foreach (array_chunk($clean, self::BATCH, true) as $chunk) {
            $batch = $this->call($resolved, array_values($chunk));
            if ($batch !== null && count($batch) === count($chunk)) {
                $keys = array_keys($chunk);
                foreach ($batch as $n => $vector) {
                    $vectors[$keys[$n]] = $vector;
                    $dims = max($dims, count($vector));
                }

                continue;
            }

            // LÔ HỎNG ⇒ THỬ LẠI TỪNG VĂN BẢN. Đây là bài học ĐO ĐƯỢC ở production: một lô 16 văn bản hỏng
            // làm cả lượt lập chỉ mục ra 0 tài liệu, trong khi 15 văn bản kia hoàn toàn nhúng được.
            foreach ($chunk as $key => $text) {
                $one = $this->call($resolved, [$text]);
                if ($one !== null && isset($one[0])) {
                    $vectors[$key] = $one[0];
                    $dims = max($dims, count($one[0]));
                } else {
                    $failed++;
                }
            }
        }

        if ($vectors === []) {
            // Không nhúng được văn bản nào ⇒ nhà cung cấp hỏng thật. Bỏ ghi nhớ để lượt sau dò lại.
            Cache::forget(self::CACHE_KEY);

            return null;
        }

        return [
            'vectors' => $vectors,
            'provider' => $resolved['provider'],
            'model' => $resolved['model'],
            // Số chiều ĐO TỪ KẾT QUẢ, không lấy từ tài liệu nhà cung cấp: cách duy nhất biết chắc.
            'dims' => $dims,
            'failed' => $failed,
        ];
    }

    /**
     * Tìm nhà cung cấp nhúng được, có ghi nhớ 1 giờ (dò lại mỗi lượt là mỗi lượt tìm có thể tốn 3 lời gọi hỏng).
     *
     * @return array{provider: string, model: string, url: string, key: string}|null
     */
    public function resolve(bool $fresh = false): ?array
    {
        if (! $fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['url'])) {
                return $cached;
            }
        }

        foreach ($this->candidates() as $candidate) {
            $slug = (string) $candidate['provider'];
            foreach ((self::MODELS[$slug] ?? self::DEFAULT_MODELS) as $model) {
                $probe = ['provider' => $slug, 'model' => $model, 'url' => $candidate['url'], 'key' => $candidate['key']];
                if ($this->call($probe, ['probe']) !== null) {
                    Cache::put(self::CACHE_KEY, $probe, self::CACHE_SECONDS);

                    return $probe;
                }
            }
        }

        return null;
    }

    /**
     * Danh sách (nhà cung cấp · khoá · địa chỉ) CÓ THỂ gọi /embeddings, theo thứ tự ưu tiên.
     *
     * @return list<array{provider: string, key: string, url: string}>
     */
    public function candidates(): array
    {
        $out = [];
        $seen = [];

        // (1) Nhà cung cấp TỰ KHAI (bảng studio_providers) — đúng thứ đang chạy trên production.
        foreach (StudioProvider::query()->where('enabled', true)->orderBy('priority')->get() as $provider) {
            $slug = (string) $provider->slug;
            $key = studio_api_key((string) ($provider->api_key_ref ?: $slug));
            $url = rtrim((string) $provider->base_url, '/');
            if ($key === null || $key === '' || $url === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = ['provider' => $slug, 'key' => $key, 'url' => $url];
        }

        // (2) Nhà cung cấp DỰNG SẴN trong danh mục, chỉ khi CÓ KHOÁ — không có khoá thì gọi cũng vô ích.
        foreach (studio_provider_catalog() as $slug => $meta) {
            $slug = (string) $slug;
            $key = studio_api_key($slug);
            $url = rtrim((string) ($meta['base_url'] ?? ''), '/');
            if ($key === null || $key === '' || $url === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = ['provider' => $slug, 'key' => $key, 'url' => $url];
        }

        return $out;
    }

    /**
     * GỌI /embeddings. Trả null khi lỗi — lớp này KHÔNG ném ra ngoài: nhúng là đường phụ, và một nhà cung
     * cấp hỏng không được làm hỏng việc tìm kiếm (đường lùi là tìm theo từ khoá).
     *
     * @param  array{provider: string, model: string, url: string, key: string}  $target
     * @param  list<string>  $texts
     * @return list<list<float>>|null
     */
    private function call(array $target, array $texts): ?array
    {
        try {
            $res = Http::withToken($target['key'])
                ->timeout(30)
                ->acceptJson()
                ->post($target['url'].'/embeddings', [
                    'model' => $target['model'],
                    // Mảng input khi có nhiều văn bản: một lời gọi cho cả lô thay vì N lời gọi.
                    'input' => count($texts) === 1 ? $texts[0] : $texts,
                ]);

            if (! $res->successful()) {
                return null;
            }

            $rows = $res->json('data');
            if (! is_array($rows) || $rows === []) {
                return null;
            }

            $vectors = [];
            foreach ($rows as $row) {
                $vector = $row['embedding'] ?? null;
                if (! is_array($vector) || $vector === []) {
                    return null;
                }
                $vectors[] = array_map(fn ($v) => (float) $v, $vector);
            }

            return $vectors;
        } catch (\Throwable) {
            return null;
        }
    }
}
