<?php

namespace Tests\Feature;

use App\Models\WebSource;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KHAI API TÌM KIẾM CÓ KHOÁ BẰNG MỘT LỆNH (2026-09-26).
 *
 * Vì sao khoá bằng test: lệnh này GHI vào hai bảng cấu hình thật (khoá API + nguồn dữ liệu) và ánh xạ 4
 * trường của Google CSE. Sai một trường thì triệu chứng duy nhất người dùng thấy là "0 kết quả" — đúng loại
 * lỗi im lặng mà dự án này cấm. Bốn bất biến được khoá:
 *   (a) nguồn tạo ra ĐÚNG dạng tìm được theo từ khoá (\`{query}\` + ánh xạ items/title/link/snippet);
 *   (b) KHOÁ không nằm trong URL của nguồn (cột đó hiện nguyên văn trên màn Cài đặt) mà ở bảng khoá đã mã hoá;
 *   (c) khoá chỉ được GẮN vào URL ở đúng lời gọi HTTP;
 *   (d) lệnh THỬ THẬT ngay sau khi khai — có kết quả thì mới coi là xong.
 */
class WebSearchSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    /** @return array<string, mixed> */
    private function cseJson(): array
    {
        return ['items' => [
            ['title' => 'Cách giặt vải linen không nhão', 'link' => 'https://gusa.vn/giat-linen', 'snippet' => 'Hướng dẫn giặt linen'],
            ['title' => 'Giá vải linen 2026', 'link' => 'https://gusa.vn/gia-linen', 'snippet' => 'Bảng giá tham khảo'],
        ]];
    }

    public function test_the_setup_creates_a_searchable_source_and_the_encrypted_key(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response((string) json_encode($this->cseJson()), 200)]);

        $this->artisan('studio:web-search-setup', [
            '--key' => 'AIza-test-key-1234',
            '--cx' => 'CX-abc-123',
            '--test' => 'cách giặt vải linen',
        ])->assertExitCode(0);

        $source = WebSource::query()->where('slug', 'google-cse')->first();
        $this->assertNotNull($source, 'Lệnh phải tạo nguồn tìm kiếm.');
        $this->assertSame('search', $source->kind);

        // (a) ĐÚNG dạng tìm được theo từ khoá + ánh xạ 4 trường của Google CSE.
        $this->assertTrue(WebSourceService::isSearchable($source), 'Nguồn phải có chỗ điền {query}.');
        $this->assertSame('items', $source->items_path);
        $this->assertSame('title', $source->title_field);
        $this->assertSame('link', $source->link_field);
        $this->assertSame('snippet', $source->summary_field);
        $this->assertStringContainsString('cx=CX-abc-123', (string) $source->url);
        $this->assertStringContainsString('q={query}', (string) $source->url);

        // (b) KHOÁ KHÔNG nằm trong URL của nguồn — nhưng ĐỌC ĐƯỢC từ bảng khoá (đã mã hoá).
        $this->assertStringNotContainsString('AIza-test-key-1234', (string) $source->url,
            'Khoá trong cột URL là phơi khoá ở màn Cài đặt, payload API và log.');
        $this->assertSame('AIza-test-key-1234', studio_api_key('google-cse'), 'Khoá phải đọc lại được từ bảng khoá.');

        // (c)(d) Lượt THỬ thật đi ra mạng với khoá gắn ở lời gọi, và trả về kết quả.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'key=AIza-test-key-1234')
            && str_contains(urldecode($request->url()), 'q=cách giặt vải linen'));

        $found = app(WebSourceService::class)->search('cách giặt vải linen', 'all');
        $this->assertSame(2, $found['count'], 'Nguồn vừa khai phải tra được web chung: '.($found['error'] ?? ''));
        $this->assertSame('https://gusa.vn/giat-linen', $found['items'][0]['url']);
    }

    public function test_the_fresh_option_limits_results_at_google_side(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response((string) json_encode($this->cseJson()), 200)]);

        $this->artisan('studio:web-search-setup', [
            '--key' => 'AIza-test-key-1234',
            '--cx' => 'CX-abc-123',
            '--fresh' => '30',
        ])->assertExitCode(0);

        $url = (string) WebSource::query()->where('slug', 'google-cse')->value('url');
        $this->assertStringContainsString('dateRestrict=d30', $url,
            'Google CSE không trả ngày đăng ⇒ lọc độ mới phải do Google làm, ngay trong URL.');
    }

    public function test_missing_credentials_is_refused_and_creates_nothing(): void
    {
        Http::fake();

        $this->artisan('studio:web-search-setup', ['--cx' => 'CX-abc-123'])
            ->expectsOutputToContain('Thiếu --key=… hoặc --cx=…')
            ->assertExitCode(1);

        $this->assertSame(0, WebSource::query()->where('slug', 'google-cse')->count(),
            'Thiếu khoá thì KHÔNG được tạo nguồn nửa vời (nguồn không có khoá là nguồn luôn trả 0).');
        Http::assertNothingSent();
    }

    public function test_a_key_that_google_rejects_is_reported_as_a_failure(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(
            (string) json_encode(['error' => ['message' => 'API key not valid. Please pass a valid API key.']]),
            400
        )]);

        $this->artisan('studio:web-search-setup', [
            '--key' => 'AIza-sai',
            '--cx' => 'CX-abc-123',
        ])
            ->expectsOutputToContain('CHƯA TRA ĐƯỢC GÌ')
            ->assertExitCode(1);
    }
}
