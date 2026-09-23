<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\WebSource;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NGUỒN TAVILY — API TÌM KIẾM WEB LÀM SẴN CHO AGENT (2026-09-26).
 *
 * Vì sao có nhà cung cấp này: đo trên production — nguồn RSS chỉ có TIN TỨC, nên câu hỏi web chung ("cách
 * giặt vải linen", "giá vải linen") trả về **0 kết quả**. Tavily khác RSS ở ba điểm quan trọng:
 *   · chạy được **KHÔNG CẦN KHOÁ** (chế độ keyless của chính họ) ⇒ có tìm kiếm web ngay, không phải chờ ai lấy khoá;
 *   · trả **NGÀY ĐĂNG** ⇒ bộ lọc độ mới (60 ngày) và số đo xu hướng chạy được thật;
 *   · trả **đoạn trích đã làm sạch** viết cho LLM đọc ⇒ prompt gọn hơn.
 *
 * Bộ test khoá NĂM bất biến: đường keyless chạy thật · có khoá thì đổi sang Bearer (KHÔNG phải sửa mã) ·
 * tuỳ chọn trong URL đi vào body · kết quả quá cũ bị lọc · nhà cung cấp chặn nhịp thì nói THẬT.
 */
class TavilySearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    private function source(string $url = 'https://api.tavily.com/search'): WebSource
    {
        return WebSource::create([
            'slug' => 'tavily-search', 'name' => 'Tavily — tìm kiếm web (cho agent)',
            'url' => $url, 'kind' => 'tavily', 'enabled' => true, 'priority' => 1, 'max_items' => 5,
        ]);
    }

    /** @return array<string, mixed> */
    private function tavilyJson(): array
    {
        return [
            'query' => 'cách giặt vải linen',
            'results' => [
                [
                    'title' => 'Cách giặt vải linen không bị nhão',
                    'url' => 'https://gusa.vn/giat-linen',
                    'content' => 'Giặt nước lạnh, không vắt mạnh, phơi ngang để giữ phom.',
                    'score' => 0.91,
                    // ĐỊNH DẠNG THẬT của Tavily là RFC-2822 ("Tue, 11 Mar 2025 17:00:00 GMT") — dùng đúng
                    // định dạng đó nhưng NGÀY GẦN ĐÂY, vì ngày cũ sẽ bị bộ lọc độ mới loại (đã dính khi viết test).
                    'published_date' => date('r', time() - 5 * 86400),
                ],
                [
                    'title' => 'Bảo quản linen mùa ẩm',
                    'url' => 'https://gusa.vn/bao-quan-linen',
                    'content' => 'Cất túi vải, tránh ẩm.',
                    'score' => 0.72,
                ],
            ],
        ];
    }

    // ── (1)(3) KEYLESS chạy thật + tuỳ chọn trong URL đi vào body ────────────

    public function test_the_keyless_mode_works_without_any_key_and_maps_results(): void
    {
        $this->source('https://api.tavily.com/search?topic=news&time_range=week&depth=advanced');

        $sent = [];
        Http::fake([
            'api.tavily.com/*' => function ($request) use (&$sent) {
                $sent[] = ['body' => json_decode($request->body(), true), 'headers' => $request->headers()];

                return Http::response((string) json_encode($this->tavilyJson()), 200);
            },
        ]);

        $found = app(WebSourceService::class)->search('cách giặt vải linen', 'all', 5);

        // (1) Kết quả được đọc đúng: tiêu đề · URL · đoạn trích · NGÀY ĐĂNG.
        $this->assertSame(2, $found['count'], 'Keyless phải trả được kết quả: '.($found['error'] ?? ''));
        $this->assertSame('https://gusa.vn/giat-linen', $found['items'][0]['url']);
        $this->assertStringContainsString('Giặt nước lạnh', (string) $found['items'][0]['summary']);
        $this->assertNotNull($found['items'][0]['published_at'], 'Tavily trả ngày đăng — đây là điểm hơn hẳn RSS.');

        // KHÔNG có khoá ⇒ đi chế độ keyless; và KHÔNG gửi Authorization rỗng.
        $this->assertCount(1, $sent);
        $this->assertSame('keyless', $sent[0]['headers']['X-Tavily-Access-Mode'][0] ?? null);
        $this->assertArrayNotHasKey('Authorization', $sent[0]['headers']);

        // (3) Tuỳ chọn trong URL của nguồn đi vào BODY của POST (không phải query string của lời gọi).
        $this->assertSame('news', $sent[0]['body']['topic']);
        $this->assertSame('week', $sent[0]['body']['time_range']);
        $this->assertSame('advanced', $sent[0]['body']['search_depth']);
        $this->assertSame('cách giặt vải linen', $sent[0]['body']['query']);
        $this->assertTrue($sent[0]['body']['include_published_date'], 'Phải XIN ngày đăng, nếu không bộ lọc độ mới vô dụng.');
    }

    // ── (2) CÓ KHOÁ ⇒ Bearer, không cần sửa mã ──────────────────────────────

    public function test_a_key_switches_to_bearer_auth_without_code_changes(): void
    {
        $this->source();
        StudioApiKey::create([
            'provider' => 'tavily-search', 'label' => 'Tavily', 'value' => 'tvly-test-key',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);

        $headers = [];
        Http::fake([
            'api.tavily.com/*' => function ($request) use (&$headers) {
                $headers = $request->headers();

                return Http::response((string) json_encode($this->tavilyJson()), 200);
            },
        ]);

        $found = app(WebSourceService::class)->search('cách giặt vải linen', 'all', 5);

        $this->assertSame(2, $found['count']);
        $this->assertSame('Bearer tvly-test-key', $headers['Authorization'][0] ?? null, 'Có khoá thì đi Bearer.');
        $this->assertArrayNotHasKey('X-Tavily-Access-Mode', $headers, 'Có khoá thì KHÔNG đi chế độ keyless.');
    }

    // ── (4) NGÀY ĐĂNG cũ ⇒ lọc như mọi nguồn khác ───────────────────────────

    public function test_results_older_than_the_freshness_window_are_dropped(): void
    {
        $this->source();

        Http::fake(['api.tavily.com/*' => Http::response((string) json_encode(['results' => [
            ['title' => 'Bài cũ về linen', 'url' => 'https://gusa.vn/cu', 'content' => 'x', 'published_date' => 'Mon, 02 Jan 2023 08:00:00 GMT'],
            ['title' => 'Bài mới về linen', 'url' => 'https://gusa.vn/moi', 'content' => 'y', 'published_date' => now()->subDays(3)->toIso8601String()],
        ]]), 200)]);

        $found = app(WebSourceService::class)->search('linen', 'all', 5);

        $this->assertSame(1, $found['count'], 'Bài 2023 phải bị lọc theo trần '.WebSourceService::MAX_AGE_DAYS.' ngày.');
        $this->assertSame('https://gusa.vn/moi', $found['items'][0]['url']);
        $this->assertSame(1, $found['dropped'], 'Số đo phải nói rõ đã bỏ bao nhiêu vì cũ.');
    }

    // ── (5) CHẶN NHỊP ⇒ nói thật, không im lặng trả 0 ────────────────────────

    public function test_a_rate_limited_response_is_reported_instead_of_looking_empty(): void
    {
        $this->source();

        Http::fake(['api.tavily.com/*' => Http::response('{"detail":"Rate limit exceeded"}', 429)]);

        $found = app(WebSourceService::class)->search('linen', 'all', 5);

        $this->assertSame(0, $found['count']);
        $this->assertStringContainsString('giới hạn nhịp', (string) $found['error'], 'Phải nói đúng bệnh, không phải "0 kết quả".');
        // Mã HTTP nằm ở khối trạng thái TỪNG NGUỒN (giao diện đọc khối này để nói nguồn nào hỏng vì gì).
        $this->assertSame(429, $found['sources'][0]['http'] ?? null);
    }

    // ── Lệnh khai nguồn chạy được ở chế độ KHÔNG khoá ───────────────────────

    public function test_the_setup_command_registers_tavily_without_a_key(): void
    {
        Http::fake(['api.tavily.com/*' => Http::response((string) json_encode($this->tavilyJson()), 200)]);

        $this->artisan('studio:web-search-setup', [
            '--provider' => 'tavily',
            '--test' => 'cách giặt vải linen',
        ])->assertExitCode(0);

        $source = WebSource::query()->where('slug', 'tavily-search')->first();
        $this->assertNotNull($source);
        $this->assertSame('tavily', $source->kind);
        $this->assertTrue(WebSourceService::isSearchable($source), 'Nguồn Tavily phải được coi là nguồn TÌM ĐƯỢC.');
        $this->assertSame(0, StudioApiKey::query()->where('provider', 'tavily-search')->count(),
            'Không truyền --key thì KHÔNG được tạo hàng khoá rỗng.');
    }
}
