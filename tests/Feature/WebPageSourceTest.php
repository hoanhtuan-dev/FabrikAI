<?php

namespace Tests\Feature;

use App\Models\WebSource;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NGUỒN LOẠI `page` — DÙNG ĐỊA CHỈ WEB BÌNH THƯỜNG THAY VÌ RSS (2026-09-26).
 *
 * Vì sao có loại nguồn này: rất nhiều site thời trang Việt KHÔNG có RSS, mà lại có trang chuyên mục đầy bài
 * mới. Trước đây những địa chỉ đó không dùng được — bộ đọc JSON "đọc" trang HTML ra vài mục rác (lỗi thật
 * 2026-09-21), nên lớp này CỐ TÌNH từ chối mọi HTML. Nay có bộ đọc HTML riêng, và bộ test này khoá BA điều:
 *   (a) nó bóc được LIÊN KẾT BÀI (đường dẫn tương đối → tuyệt đối, có tiêu đề);
 *   (b) nó BỎ mục menu/điều hướng — nếu không, prompt đầy rác và máy đo tín hiệu đếm cả chúng;
 *   (c) khi KHÔNG bóc được gì thì nó BÁO LỖI chỉ đúng việc cần sửa, không im lặng trả 0 mục.
 * Thêm nữa: một địa chỉ web bình thường có `?keywords={query}` trở thành TRANG TÌM KIẾM của site đó —
 * tìm kiếm thật mà KHÔNG cần API, KHÔNG cần khoá.
 */
class WebPageSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    /** Trang chuyên mục giả: bài thật + menu điều hướng + liên kết ra ngoài + đường dẫn tương đối. */
    private function listingHtml(): string
    {
        return '<html><head><title>Thời trang</title><script>var x = "/bai-viet-tu-javascript-123456.htm";</script></head><body>'
            .'<nav>'
            .'<a href="/">Trang chủ</a>'
            .'<a href="/dang-nhap.htm">Đăng nhập</a>'
            .'<a href="/gio-hang.htm">Giỏ hàng</a>'
            .'<a href="/tag/ao-dai">Tag áo dài</a>'
            .'<a href="https://facebook.com/fabrikai">Theo dõi chúng tôi trên Facebook</a>'
            .'</nav>'
            .'<main>'
            .'<a href="/ao-dai-tet-2026-cach-tan-dep-mat-cho-nang.htm">Áo dài Tết 2026 cách tân đẹp mắt cho nàng du Xuân</a>'
            .'<a href="chat-lieu-linen-ben-dep-quan-ao-mua-he.htm">Chất liệu linen bền đẹp cho quần áo mùa hè năm nay</a>'
            .'<a href="/ao-dai-tet-2026-cach-tan-dep-mat-cho-nang.htm">Áo dài Tết 2026 cách tân đẹp mắt cho nàng du Xuân</a>'
            .'<a href="/xem-them">Xem thêm</a>'
            .'</main></body></html>';
    }

    private function pageSource(string $url): WebSource
    {
        return WebSource::create([
            'slug' => 'bao-chuyen-muc', 'name' => 'Báo — chuyên mục thời trang',
            'url' => $url, 'kind' => 'page', 'enabled' => true, 'priority' => 1, 'max_items' => 10,
        ]);
    }

    // ── (a)(b) BÓC BÀI THẬT, BỎ MENU ────────────────────────────────────────

    public function test_a_normal_web_page_is_read_as_a_list_of_articles(): void
    {
        $source = $this->pageSource('https://bao.example/thoi-trang.htm');
        Http::fake(['bao.example/*' => Http::response($this->listingHtml(), 200)]);

        $fetched = app(WebSourceService::class)->fetch($source, true);

        $this->assertTrue($fetched['ok'], 'Trang HTML bình thường phải đọc được: '.($fetched['error'] ?? ''));
        $urls = array_column($fetched['items'], 'url');

        // (a) Bài thật có mặt, đường dẫn TƯƠNG ĐỐI đã thành TUYỆT ĐỐI.
        $this->assertContains('https://bao.example/ao-dai-tet-2026-cach-tan-dep-mat-cho-nang.htm', $urls);
        $this->assertContains('https://bao.example/chat-lieu-linen-ben-dep-quan-ao-mua-he.htm', $urls);
        $this->assertCount(2, $fetched['items'], 'Phải khử trùng theo URL — bài lặp lại chỉ tính một lần.');

        // Tiêu đề lấy từ chữ của liên kết, không phải URL.
        $this->assertStringContainsString('Áo dài Tết 2026', $fetched['items'][0]['title']);

        // (b) KHÔNG có mục menu/điều hướng/quảng cáo trong kết quả.
        foreach (['dang-nhap', 'gio-hang', 'tag/ao-dai', 'facebook.com', 'xem-them'] as $junk) {
            $this->assertStringNotContainsString($junk, implode(' ', $urls), 'Lọt mục điều hướng vào nguồn: '.$junk);
        }

        // Nói THẬT là không có ngày: đa số trang danh sách không có ngày đăng, và tin không ngày thì không
        // đo được xu hướng tăng/giảm — người dùng cần biết điều đó.
        $this->assertNull($fetched['items'][0]['published_at']);
    }

    /**
     * TIỀN TỐ ĐƯỜNG DẪN là hàng rào QUAN TRỌNG NHẤT của loại nguồn này.
     *
     * [ĐO THẬT 2026-09-26] Bóc trang chuyên mục THỜI TRANG của eva.vn mà không lọc: 10/10 mục đầu là bài
     * NUÔI CON — vì trang đó có khối "đọc nhiều" của toàn site. Nguồn sẽ báo "10 tin" trong khi nội dung sai;
     * khai tiền tố (`/thoi-trang-c13/`) thì chỉ bài CỦA CHUYÊN MỤC ĐÓ đi vào prompt.
     */
    public function test_a_path_prefix_keeps_only_the_articles_of_that_section(): void
    {
        $source = $this->pageSource('https://bao.example/thoi-trang.htm');
        $source->items_path = '/thoi-trang/';
        $source->save();

        Http::fake([
            'bao.example/*' => Http::response('<html><body>'
                .'<a href="/thoi-trang/ao-dai-tet-2026-cach-tan-dep-mat.htm">Áo dài Tết 2026 cách tân đẹp mắt cho nàng</a>'
                .'<a href="/nuoi-con/bi-quyet-day-con-nghe-loi-khong-can-quat.htm">Bí quyết dạy con nghe lời không cần quát mắng</a>'
                .'</body></html>', 200),
        ]);

        $fetched = app(WebSourceService::class)->fetch($source->fresh(), true);

        $urls = array_column($fetched['items'], 'url');
        $this->assertSame(['https://bao.example/thoi-trang/ao-dai-tet-2026-cach-tan-dep-mat.htm'], $urls,
            'Chỉ bài thuộc chuyên mục đã khai mới được vào nguồn.');
    }

    // ── (c) KHÔNG BÓC ĐƯỢC GÌ THÌ PHẢI NÓI RA ───────────────────────────────

    public function test_a_page_without_articles_reports_what_to_fix(): void
    {
        $source = $this->pageSource('https://bao.example/trang-chu.htm');
        Http::fake([
            'bao.example/*' => Http::response('<html><body><a href="/">Trang chủ</a><a href="/lien-he.htm">Liên hệ</a></body></html>', 200),
        ]);

        $fetched = app(WebSourceService::class)->fetch($source, true);

        $this->assertSame([], $fetched['items']);
        $this->assertStringContainsString('trang CHUYÊN MỤC', (string) $fetched['error'], 'Lỗi phải chỉ đúng việc cần sửa.');
    }

    // ── Trang tìm kiếm của chính website: KHÔNG cần API, KHÔNG cần khoá ─────

    public function test_a_normal_web_search_page_works_as_a_search_source(): void
    {
        $source = $this->pageSource('https://bao.example/tim-kiem.htm?keywords={query}');
        $this->assertTrue(WebSourceService::isSearchable($source), 'URL có {query} là nguồn TÌM ĐƯỢC.');

        $sent = [];
        Http::fake([
            'bao.example/*' => function ($request) use (&$sent) {
                $sent[] = $request->url();

                return Http::response('<html><body>'
                    .'<a href="/cach-giat-vai-linen-khong-bi-nhao.htm">Cách giặt vải linen không bị nhão cho người mới</a>'
                    .'<a href="/lien-he.htm">Liên hệ</a></body></html>', 200);
            },
        ]);

        $found = app(WebSourceService::class)->search('cách giặt vải linen', 'all');

        $this->assertSame(1, $found['count'], 'Trang tìm kiếm HTML phải trả được kết quả: '.($found['error'] ?? ''));
        $this->assertSame('https://bao.example/cach-giat-vai-linen-khong-bi-nhao.htm', $found['items'][0]['url']);

        // Từ khoá của model đi vào ĐÚNG chỗ điền của URL.
        $this->assertNotEmpty($sent);
        $this->assertStringContainsString('keywords=', urldecode($sent[0]));
        $this->assertStringContainsString('cách giặt vải linen', urldecode($sent[0]));
    }

    /** Nguồn loại page KHÔNG có {query} vẫn là nguồn ĐỌC TIN (feed), không phải làn tìm kiếm. */
    public function test_a_page_source_without_query_is_read_as_a_feed(): void
    {
        $source = $this->pageSource('https://bao.example/thoi-trang.htm');
        Http::fake(['bao.example/*' => Http::response($this->listingHtml(), 200)]);

        $evidence = app(WebSourceService::class)->evidence('all', WebSourceService::EVIDENCE_LIMIT, true);

        $urls = array_column($evidence['items'], 'url');
        $this->assertContains('https://bao.example/ao-dai-tet-2026-cach-tan-dep-mat-cho-nang.htm', $urls,
            'Bài đọc từ trang web bình thường phải vào được khối DỮ LIỆU của agent.');
        $this->assertSame('live', $evidence['mode']);

        // Trạng thái nguồn nói thật nó là nguồn loại page và đọc được bao nhiêu mục.
        $row = collect($evidence['sources'])->firstWhere('slug', 'bao-chuyen-muc');
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['count']);
    }

    /** Nguồn loại page vẫn phải qua rào chắn SSRF như mọi nguồn khác. */
    public function test_a_page_source_cannot_point_at_an_internal_address(): void
    {
        $source = $this->pageSource('http://127.0.0.1:8000/noi-bo.htm');
        Http::fake(['*' => Http::response('<html><body><a href="/x">Bài viết nội bộ dài dòng nào đó</a></body></html>', 200)]);

        $fetched = app(WebSourceService::class)->fetch($source, true);

        $this->assertFalse($fetched['ok']);
        $this->assertStringContainsString('công khai', (string) $fetched['error']);
        Http::assertNothingSent();
    }
}
