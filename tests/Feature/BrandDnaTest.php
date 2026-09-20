<?php

namespace Tests\Feature;

use App\Models\BrandDna;
use App\Models\User;
use App\Services\BrandDnaService;
use App\Services\DesignAgentService;
use App\Services\WebAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DNA THƯƠNG HIỆU + KHẢ NĂNG TRUY CẬP INTERNET CỦA AGENT (Đợt 22 — 2026-09-23).
 *
 * Hai câu hỏi người dùng đặt ra và bộ test này phải trả lời được bằng máy:
 *   1. "Agent có truy cập internet không?" — trước đây giao diện trả lời bằng một CÂU VĂN TĨNH, không
 *      đo gì cả. Nay câu trả lời là kết quả ĐO (máy chủ ra được internet không + model đang cấu hình có
 *      tìm kiếm tích hợp không) và phải nói đúng cả hai chiều.
 *   2. "Quản lý/sửa DNA ở đâu?" — trước đây DNA chỉ được SUY RA (đếm dự án + dò từ khoá, không có gì
 *      thì dùng câu mặc định cứng) và chủ shop không sửa được. Nay là hồ sơ riêng, lưu theo tài khoản,
 *      ưu tiên hơn mọi suy đoán, và đi thẳng vào prompt của agent.
 */
class BrandDnaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function owner(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    // ── (A) HỒ SƠ DNA ───────────────────────────────────────────────────────

    /** Chưa khai gì ⇒ mọi trường rỗng, is_set=false, và KHÔNG có câu tóm tắt nào được bịa ra. */
    public function test_a_user_without_dna_gets_an_empty_honest_shape(): void
    {
        $this->actingAs($this->customer())->getJson('/api/brand-dna')
            ->assertOk()
            ->assertJsonPath('is_set', false)
            ->assertJsonPath('summary', '');

        $response = $this->actingAs($this->customer())->getJson('/api/brand-dna')->json();
        $this->assertSame([], $response['dna']['styles']);
        $this->assertSame('', $response['dna']['positioning']);
        $this->assertArrayHasKey('labels', $response, 'Giao diện cần nhãn trường để không tự chế chữ.');
    }

    /** Lưu rồi đọc lại: hồ sơ nằm trong DB của CHÍNH người dùng và có câu tóm tắt đúng nội dung. */
    public function test_dna_is_saved_per_account_and_summarised(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->putJson('/api/brand-dna', [
            'positioning' => 'Thời trang nữ công sở tối giản',
            'customer' => 'nữ 25–35 tuổi, ngân sách 400–800k',
            'price_band' => 'mid',
            'styles' => ['tối giản', 'thanh lịch'],
            'colors' => ['trắng ngà', 'be'],
            'avoid' => ['họa tiết to'],
        ])->assertOk()->assertJsonPath('saved', true)->assertJsonPath('is_set', true);

        $this->assertSame(1, BrandDna::query()->where('user_id', $user->id)->count());

        $summary = $this->actingAs($user)->getJson('/api/brand-dna')->json('summary');
        $this->assertStringContainsString('Thời trang nữ công sở tối giản', $summary);
        $this->assertStringContainsString('Trung cấp', $summary, 'Dải giá phải được dịch sang nhãn người dùng đọc được.');
        $this->assertStringContainsString('KHÔNG làm: họa tiết to', $summary);
    }

    /** Hồ sơ của NGƯỜI KHÁC không bao giờ lộ sang — kể cả khi cùng mở một trang. */
    public function test_dna_is_isolated_between_accounts(): void
    {
        $this->actingAs($this->customer())->putJson('/api/brand-dna', ['positioning' => 'Shop của khách'])->assertOk();
        $this->actingAs($this->owner())->putJson('/api/brand-dna', ['positioning' => 'Shop của chủ'])->assertOk();

        $this->assertSame(2, BrandDna::query()->count());
        $this->assertSame('Shop của chủ',
            $this->actingAs($this->owner())->getJson('/api/brand-dna')->json('dna.positioning'));
        $this->assertSame('Shop của khách',
            $this->actingAs($this->customer())->getJson('/api/brand-dna')->json('dna.positioning'));
    }

    /** Dữ liệu bẩn bị chặn ở BIÊN: dải giá lạ · danh sách quá dài · mục quá dài · mục trùng. */
    public function test_dna_input_is_validated_and_clamped(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->putJson('/api/brand-dna', ['price_band' => 'khong-co-that'])->assertStatus(422);
        $this->actingAs($user)->putJson('/api/brand-dna', ['positioning' => str_repeat('a', 201)])->assertStatus(422);
        $this->actingAs($user)->putJson('/api/brand-dna', ['styles' => array_fill(0, 9, 'x')])->assertStatus(422);
        $this->actingAs($user)->putJson('/api/brand-dna', ['styles' => ['ok', str_repeat('b', 41)]])->assertStatus(422);

        // Trần số mục của giao diện lấy từ CÙNG khai báo với validate.
        $this->assertSame(8, BrandDnaService::listMax('styles'));
        $this->assertSame(10, BrandDnaService::listMax('categories'));
    }

    /** Chuẩn hoá: bỏ trùng, bỏ rỗng, giữ thứ tự, cắt theo trần — dữ liệu cũ/hỏng vẫn đọc được. */
    public function test_normalisation_cleans_lists_without_throwing(): void
    {
        $clean = BrandDnaService::normalize([
            'styles' => ['  tối giản ', 'tối giản', '', 'thanh lịch'],
            'price_band' => 'premium',
            'positioning' => null,
            'categories' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'],
        ]);

        $this->assertSame(['tối giản', 'thanh lịch'], $clean['styles']);
        $this->assertCount(10, $clean['categories'], 'Quá trần số mục thì cắt, không ném lỗi.');
        $this->assertSame('', $clean['positioning']);
        $this->assertSame('premium', $clean['price_band']);
        $this->assertSame(BrandDnaService::empty(), BrandDnaService::normalize('khong-phai-mang'));
    }

    /** Xoá hồ sơ chỉ xoá DNA — KHÔNG đụng dữ liệu bán hàng hay dự án của người dùng. */
    public function test_resetting_dna_keeps_the_shop_data(): void
    {
        $user = $this->customer();
        $user->projects()->create(['name' => 'BST giữ lại']);
        $this->actingAs($user)->putJson('/api/brand-dna', ['positioning' => 'X'])->assertOk();

        $this->actingAs($user)->deleteJson('/api/brand-dna')->assertOk()->assertJsonPath('is_set', false);

        $this->assertSame(0, BrandDna::query()->count());
        $this->assertSame(1, $user->projects()->count(), 'Xoá DNA không được xoá dự án.');
    }

    /** Khách chưa đăng nhập không đọc/ghi được hồ sơ của ai. */
    public function test_dna_endpoints_require_authentication(): void
    {
        $this->getJson('/api/brand-dna')->assertStatus(401);
        $this->putJson('/api/brand-dna', ['positioning' => 'X'])->assertStatus(401);
    }

    // ── (B) DNA ĐI VÀO AGENT ────────────────────────────────────────────────

    /** DNA chủ shop khai THẮNG phần suy ra, và nguồn được nói rõ cho giao diện. */
    public function test_owner_dna_wins_over_derived_guesses(): void
    {
        $user = $this->customer();
        $user->projects()->create(['name' => 'BST']);
        app(BrandDnaService::class)->save($user, ['positioning' => 'Đầm linen nữ công sở', 'avoid' => ['họa tiết to']]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'bộ sưu tập đầm linen'], $user, false);

        $this->assertSame('owner', $brief['brand_dna']['source']);
        $this->assertSame('Do bạn khai', $brief['brand_dna']['source_label']);
        $this->assertSame(['họa tiết to'], $brief['brand_dna']['fields']['avoid']);
        $this->assertStringContainsString('Đầm linen nữ công sở', (string) $brief['brand_dna']['summary']);
        $this->assertStringContainsString('Đầm linen nữ công sở', (string) $brief['brand_narrative']['narrative'],
            'Câu chuyện thương hiệu phải dùng DNA chủ shop khai, không phải câu suy ra.');
    }

    /** Chưa khai ⇒ nguồn phải là suy ra/mặc định, KHÔNG được giả vờ là do người dùng khai. */
    public function test_without_dna_the_source_is_marked_as_derived(): void
    {
        $user = $this->customer();

        $radar = app(DesignAgentService::class)->radar($user, 'all', false);
        $signal = $radar['internal_brand_signal'];

        $this->assertFalse($signal['dna_is_set']);
        $this->assertContains($signal['dna_source'], ['default', 'derived', 'shop_data']);
        $this->assertNotSame('owner', $signal['dna_source']);
    }

    /** Khách CHƯA đăng nhập vẫn phải nhận đủ khoá DNA (không nổ Undefined array key). */
    public function test_anonymous_signal_still_exposes_dna_keys(): void
    {
        $radar = app(DesignAgentService::class)->radar(null, 'all', false);

        $this->assertSame('default', $radar['internal_brand_signal']['dna_source']);
        $this->assertSame(BrandDnaService::empty(), $radar['internal_brand_signal']['dna']);
    }

    // ── (C) KHẢ NĂNG TRUY CẬP INTERNET ──────────────────────────────────────

    /** Máy chủ ra được internet ⇒ nói CÓ; và luôn kèm số đo + thời điểm đo. */
    public function test_probe_reports_server_internet_with_evidence(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 204)]);

        $result = app(WebAccessService::class)->probe(true);

        $this->assertTrue($result['outbound']['ok']);
        $this->assertNotEmpty($result['outbound']['results']);
        $this->assertSame(204, $result['outbound']['results'][0]['status']);
        $this->assertNotNull($result['outbound']['checked_at']);
        $this->assertSame('demo', $result['sources_mode'], 'Nguồn ngoài vẫn là dữ liệu mẫu — chưa có connector thật.');
    }

    /** Mất internet là MỘT KẾT QUẢ ĐO (không ném lỗi), và câu kết luận phải nói đúng. */
    public function test_probe_survives_without_internet(): void
    {
        Cache::flush();
        Http::fake(['*' => fn () => throw new \RuntimeException('không có mạng')]);

        $result = app(WebAccessService::class)->probe(true);

        $this->assertFalse($result['outbound']['ok']);
        $this->assertSame('no_internet', $result['verdict']);
        $this->assertStringContainsString('KHÔNG gọi được ra internet', $result['verdict_label']);
    }

    /**
     * Tầng MODEL: DeepSeek (provider đang chạy trên production) KHÔNG có tìm kiếm tích hợp ⇒ câu kết
     * luận phải nói thẳng là không, dù máy chủ có internet. Đây là chỗ dễ nói dối người dùng nhất.
     */
    public function test_model_without_builtin_search_is_reported_honestly(): void
    {
        $this->assertFalse(WebAccessService::supportsSearch('openai'));
        $this->assertTrue(WebAccessService::supportsSearch('qwen'));
        $this->assertTrue(WebAccessService::supportsSearch('gemini'));
        $this->assertFalse(WebAccessService::supportsSearch('khong-ton-tai'));

        Cache::flush();
        Http::fake(['*' => Http::response('', 204)]);
        $result = app(WebAccessService::class)->probe(true);

        // Bộ seed không cấu hình model cho nhóm agent ⇒ không có candidate nào có tìm kiếm.
        $this->assertFalse($result['model_search']['supported']);
        $this->assertSame('internet_no_search', $result['verdict']);
        $this->assertStringContainsString('KHÔNG có tìm kiếm tích hợp', $result['verdict_label']);
    }

    /** Endpoint chỉ mở cho tài khoản studio, và KHÔNG gọi model nào (chỉ đo + đọc cấu hình). */
    public function test_web_access_endpoint_needs_an_account(): void
    {
        $this->getJson('/api/design-agent/web-access')->assertStatus(401);

        Cache::flush();
        Http::fake(['*' => Http::response('', 204)]);
        $this->actingAs($this->customer())->getJson('/api/design-agent/web-access')
            ->assertOk()
            ->assertJsonPath('outbound.ok', true);
    }

    /** Kết quả đo được CACHE (mỗi lần đo là request ra ngoài) nhưng `force=1` thì đo lại thật. */
    public function test_probe_caches_but_force_re_measures(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 204)]);
        $web = app(WebAccessService::class);

        $web->probe(true);
        $web->probe();
        Http::assertSentCount(2);   // 2 đích đo, KHÔNG đo lại lần hai

        $web->probe(true);
        Http::assertSentCount(4);
    }

    // ── (D) DNA ĐI THẲNG VÀO PROMPT GỬI MODEL ───────────────────────────────

    /**
     * Bằng chứng mạnh nhất: DNA phải nằm TRONG payload gửi model, kèm luật "không đề xuất món trong
     * avoid". Không có test này thì rất dễ có chuyện giao diện hiện DNA mà prompt không hề chứa nó.
     */
    public function test_dna_reaches_the_model_prompt(): void
    {
        \App\Models\StudioModel::create([
            'group' => 'prompt', 'name' => 'DeepSeek chat', 'provider' => 'deepseek',
            'model_id' => 'deepseek-chat', 'api_key_ref' => 'deepseek', 'priority' => 9, 'enabled' => true,
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => 'deepseek', 'label' => 'deepseek', 'value' => 'sk-test',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', 'deepseek:deepseek-chat');

        $user = $this->customer();
        app(BrandDnaService::class)->save($user, [
            'positioning' => 'Đầm linen nữ công sở',
            'avoid' => ['họa tiết to'],
        ]);

        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'narrative' => 'DNA', 'brief' => 'brief', 'prompt_vi' => 'vi', 'prompt_en' => 'en',
                'moodboard_captions' => [], 'category_rationale' => [], 'outfit_goals' => [], 'next_steps' => [],
            ], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $user, true);

        Http::assertSent(function ($request) {
            // Thân request do HTTP client mã hoá LẠI (mất JSON_UNESCAPED_UNICODE) nên phải giải mã rồi mới
            // so chữ tiếng Việt — so thẳng trên chuỗi thô sẽ trượt vì chuỗi thành \uXXXX.
            $body = json_encode(json_decode((string) $request->body(), true), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'Đầm linen nữ công sở')
                && str_contains($body, 'họa tiết to')
                && str_contains($body, 'brand_dna')
                && str_contains($body, 'avoid');
        });
    }
}
