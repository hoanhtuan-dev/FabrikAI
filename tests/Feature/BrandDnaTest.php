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
        // Khả năng là của GIAO THỨC, không phải của nhà cung cấp: thêm một nhà cung cấp mới nói cùng
        // giao thức thì không phải sửa mã. Giao thức lạ ⇒ KHÔNG hứa (false), không đoán.
        $this->assertFalse(WebAccessService::supportsSearch('openai'));
        $this->assertTrue(WebAccessService::supportsSearch('qwen'));
        $this->assertTrue(WebAccessService::supportsSearch('gemini'));
        $this->assertFalse(WebAccessService::supportsSearch('khong-ton-tai'));

        Cache::flush();
        Http::fake(['*' => Http::response('', 204)]);

        // (a) CHƯA có model dùng được ⇒ phải nói "chưa cấu hình", KHÔNG được nói "model không có tìm kiếm"
        // (hai chuyện khác nhau: một cái là việc ở Cài đặt, một cái là năng lực của model).
        $none = app(WebAccessService::class)->probe(true);
        $this->assertFalse($none['model_search']['has_model']);
        $this->assertFalse($none['model_search']['supported']);
        $this->assertSame('no_model_configured', $none['verdict']);
        $this->assertStringContainsString('chưa có model dùng được', $none['verdict_label']);

        // (b) CÓ model nhưng giao thức không khai tìm kiếm ⇒ verdict khác hẳn, và vẫn không nêu tên ai.
        \App\Models\StudioProvider::create([
            'slug' => 'provider-khong-search', 'name' => 'Gateway thường', 'protocol' => 'openai',
            'base_url' => 'https://plain.example/v1', 'auth_style' => 'bearer',
            'search_param' => null, 'api_key_ref' => 'provider-khong-search',
            'priority' => 9, 'enabled' => true,
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => 'provider-khong-search', 'label' => 'x', 'value' => 'sk-x',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        \App\Models\StudioModel::create([
            'group' => 'prompt', 'name' => 'Model thường', 'provider' => 'provider-khong-search',
            'model_id' => 'thuong', 'api_key_ref' => 'provider-khong-search', 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', 'provider-khong-search:thuong');

        $configured = app(WebAccessService::class)->probe(true);
        $this->assertTrue($configured['model_search']['has_model']);
        $this->assertFalse($configured['model_search']['supported']);
        $this->assertSame('internet_no_search', $configured['verdict']);
        $this->assertStringContainsString('KHÔNG có tìm kiếm web', $configured['verdict_label']);
        // Câu kết luận KHÔNG được đẩy người dùng sang một nhà cung cấp cụ thể nào — việc chọn model là
        // ở Cài đặt, và mã nguồn không được quyết hộ.
        foreach (['Qwen', 'DashScope', 'DeepSeek', 'Gemini'] as $vendor) {
            $this->assertStringNotContainsString($vendor, $configured['verdict_label']);
        }
    }

    /**
     * TÌM KIẾM WEB DO CÀI ĐẶT QUYẾT ĐỊNH: một Custom Provider tự khai `search_param` ⇒ hệ thống bật
     * đúng tham số đó, và gateway gửi nó trong request. Không có dòng mã nào biết tên nhà cung cấp.
     */
    public function test_search_follows_the_configured_custom_provider(): void
    {
        \App\Models\StudioProvider::create([
            'slug' => 'gateway-abc', 'name' => 'Gateway ABC', 'protocol' => 'openai',
            'base_url' => 'https://gateway.example/v1', 'auth_style' => 'bearer',
            'search_param' => 'enable_search', 'api_key_ref' => 'gateway-abc',
            'priority' => 9, 'enabled' => true,
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => 'gateway-abc', 'label' => 'gateway-abc', 'value' => 'sk-abc',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        \App\Models\StudioModel::create([
            'group' => 'prompt', 'name' => 'ABC large', 'provider' => 'gateway-abc',
            'model_id' => 'abc-large', 'api_key_ref' => 'gateway-abc', 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', 'gateway-abc:abc-large');

        // 1) Khả năng đọc từ CẤU HÌNH: candidate mang theo search_param đã khai.
        $candidate = app(\App\Services\AiModelGateway::class)->candidates('prompt')[0];
        $plan = WebAccessService::planFor($candidate);
        $this->assertNotNull($plan, 'Provider tự khai tham số tìm kiếm thì phải được coi là CÓ.');
        $this->assertSame('enable_search', $plan['param']);
        $this->assertStringContainsString('Cài đặt', $plan['source']);

        // 2) Request thật có gửi tham số đó (không chỉ hiện trên giao diện).
        Cache::flush();
        Http::fake([
            'gateway.example/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'narrative' => 'n', 'brief' => 'b', 'prompt_vi' => 'vi', 'prompt_en' => 'en',
                'moodboard_captions' => [], 'category_rationale' => [], 'outfit_goals' => [], 'next_steps' => [],
            ], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm'], $this->customer(), true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gateway.example')
                && (json_decode((string) $request->body(), true)['enable_search'] ?? null) === true;
        });
    }

    /** Nhóm công việc chưa có model là trạng thái CẤU HÌNH — báo cáo phải nói đúng, không phải lỗi. */
    public function test_task_group_status_comes_from_settings(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 204)]);

        $groups = collect(app(WebAccessService::class)->probe(true)['task_groups']);

        $this->assertNotEmpty($groups);

        // Báo cáo phải PHẢN ÁNH ĐÚNG Cài đặt — kiểm bằng cách so với chính nguồn cấu hình, KHÔNG hard-code
        // nhóm nào "phải rỗng": bộ seed đổi thì test vẫn đúng, mà lệch khỏi Cài đặt là ĐỎ.
        foreach ($groups as $row) {
            $this->assertSame(
                studio_task_group_models($row['group']) !== [],
                $row['configured'],
                'Trạng thái nhóm '.$row['group'].' không khớp Cài đặt.',
            );
            $this->assertSame(count(studio_task_group_models($row['group'])), $row['candidates']);
            // configured (đã gán model) KHÁC usable (chạy được vì có key). Trên production nhóm image có
            // 3 model đã gán nhưng 0 dùng được — gộp hai thứ này là nói sai với người dùng.
            $usable = app(\App\Services\AiModelGateway::class)->candidates($row['group']);
            $this->assertSame(count($usable), $row['usable']);
            $this->assertSame($row['configured'] && count($usable) === 0, $row['needs_key']);
        }

        // Và nó phải ĐỔI THEO khi Cài đặt đổi: thêm model tạo ảnh cho một provider mới ⇒ báo cáo thấy ngay.
        \App\Models\StudioProvider::create([
            'slug' => 'provider-moi', 'name' => 'Gateway mới', 'protocol' => 'openai',
            'base_url' => 'https://moi.example/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'provider-moi', 'priority' => 9, 'enabled' => true,
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => 'provider-moi', 'label' => 'provider-moi', 'value' => 'sk-x',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        \App\Models\StudioModel::create([
            'group' => 'image', 'name' => 'Model mới', 'provider' => 'provider-moi',
            'model_id' => 'anh-moi', 'api_key_ref' => 'provider-moi', 'priority' => 99, 'enabled' => true,
        ]);

        $again = collect(app(WebAccessService::class)->probe(true)['task_groups'])->keyBy('group');
        $this->assertTrue($again['image']['configured']);
        $this->assertContains('provider-moi:anh-moi', $again['image']['models'],
            'Model vừa thêm trong Cài đặt phải xuất hiện trong báo cáo — không có danh sách cứng nào chen vào.');
        $this->assertGreaterThanOrEqual(1, $again['image']['usable'],
            'Có key đang bật thì nhóm phải được coi là DÙNG ĐƯỢC, không chỉ "đã gán".');
        $this->assertFalse($again['image']['needs_key']);
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

    /** Khối `model` của CẢ HAI agent phải nói được lần chạy đó có tìm kiếm web hay không. */
    public function test_both_agents_expose_the_web_search_flag(): void
    {
        \App\Models\StudioProvider::create([
            'slug' => 'gw-search', 'name' => 'Gateway có tìm kiếm', 'protocol' => 'openai',
            'base_url' => 'https://search.example/v1', 'auth_style' => 'bearer',
            'search_param' => 'enable_search', 'api_key_ref' => 'gw-search', 'priority' => 9, 'enabled' => true,
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => 'gw-search', 'label' => 'gw-search', 'value' => 'sk-x',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        \App\Models\StudioModel::create([
            'group' => 'prompt', 'name' => 'Model có tìm kiếm', 'provider' => 'gw-search',
            'model_id' => 'co-search', 'api_key_ref' => 'gw-search', 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', 'gw-search:co-search');
        Cache::flush();

        // Phải trả ĐỦ 5 hướng trở lên: ít hơn thì normalizeDirections() coi là hỏng và quay về engine tất định
        // (khi đó khối model là của chế độ rule — đúng thiết kế, nhưng không đo được cờ web_search của AI).
        $directions = [];
        for ($i = 1; $i <= 6; $i++) {
            $directions[] = ['title' => 'Hướng AI '.$i, 'thesis' => 't', 'why_now' => 'w', 'action' => 'a', 'risk' => 'r', 'price_band' => 'mid'];
        }

        Http::fake([
            'search.example/*' => Http::response(['choices' => [['message' => ['content' => json_encode(
                ['directions' => $directions],
                JSON_UNESCAPED_UNICODE,
            )]]]], 200),
        ]);

        // TrendRadar: khoá nằm trong khối model.
        $radar = app(DesignAgentService::class)->radar($this->customer(), 'hcm', true);
        $this->assertTrue($radar['model']['web_search'], 'Radar phải nói lần chạy này CÓ tìm kiếm web.');

        Http::fake([
            'search.example/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'narrative' => 'DNA thương hiệu', 'brief' => 'Brief cho xưởng', 'prompt_vi' => 'vi', 'prompt_en' => 'en',
                'moodboard_captions' => array_fill(0, 24, 'caption'), 'category_rationale' => [], 'outfit_goals' => [],
                'next_steps' => ['a', 'b', 'c'],
            ], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        // CollectionBot: TRƯỚC ĐÂY cờ này là khoá RỜI nên không bao giờ tới được client — nay nằm trong model.
        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true);
        $this->assertArrayHasKey('web_search', $brief['model']);
        $this->assertTrue($brief['model']['web_search']);
    }

    // ── (E) BỘ ĐỆM BRIEF THEO input_signature ──────────────────────────────

    /**
     * Bấm lại với CÙNG đầu vào ⇒ không gọi model lần nữa (đo trên production: mỗi lần là ~28 giây + token),
     * và phản hồi phải NÓI THẬT là bản lấy từ bộ đệm.
     */
    public function test_identical_brief_uses_the_cache_instead_of_calling_the_model(): void
    {
        $this->configurePromptGateway('gw-cache', 'model-cache');
        Cache::flush();
        $this->fakeBrief();

        $input = ['prompt' => 'bộ sưu tập đầm linen nữ công sở'];
        $first = app(DesignAgentService::class)->collectionBrief($input, $this->customer(), true);
        $this->assertFalse((bool) ($first['model']['cached'] ?? false), 'Lần đầu phải là bản chạy thật.');
        Http::assertSentCount(1);

        $second = app(DesignAgentService::class)->collectionBrief($input, $this->customer(), true);
        $this->assertTrue((bool) ($second['model']['cached'] ?? false), 'Lần hai phải lấy từ bộ đệm.');
        $this->assertSame(0, $second['model']['latency_ms']);
        $this->assertSame(1, count(Http::recorded()), 'Bấm lại KHÔNG được gọi model lần nữa.');
        $this->assertSame($first['brief'], $second['brief'], 'Nội dung phải y hệt bản đã đệm.');

        // `force = true` (nút "Chạy lại bằng AI") phải bỏ qua bộ đệm.
        app(DesignAgentService::class)->collectionBrief($input, $this->customer(), true, true);
        Http::assertSentCount(2);
    }

    /** Đổi DNA ⇒ khoá đệm đổi ⇒ phải sinh lại (không trả bản viết theo DNA cũ). */
    public function test_changing_the_dna_invalidates_the_brief_cache(): void
    {
        $this->configurePromptGateway('gw-cache2', 'model-cache');
        Cache::flush();
        $this->fakeBrief();
        $user = $this->customer();
        $input = ['prompt' => 'bộ sưu tập đầm linen'];

        app(DesignAgentService::class)->collectionBrief($input, $user, true);
        app(DesignAgentService::class)->collectionBrief($input, $user, true);
        $this->assertSame(1, count(Http::recorded()), 'Chưa đổi gì thì phải dùng bộ đệm.');

        app(BrandDnaService::class)->save($user, ['positioning' => 'Đầm linen nữ công sở']);
        app(DesignAgentService::class)->collectionBrief($input, $user, true);
        $this->assertSame(2, count(Http::recorded()), 'Đổi DNA thì bộ đệm cũ KHÔNG còn đúng.');
    }

    /** Bộ đệm là RIÊNG từng tài khoản — dữ liệu shop của người này không được trả cho người khác. */
    public function test_the_brief_cache_is_per_account(): void
    {
        $this->configurePromptGateway('gw-cache3', 'model-cache');
        Cache::flush();
        $this->fakeBrief();
        $input = ['prompt' => 'bộ sưu tập đầm linen'];

        app(DesignAgentService::class)->collectionBrief($input, $this->customer(), true);
        app(DesignAgentService::class)->collectionBrief($input, $this->owner(), true);

        $this->assertSame(2, count(Http::recorded()), 'Khoá đệm phải gồm tài khoản.');
    }

    // ── (F) KIỂU BẬT TÌM KIẾM KHAI TRONG CÀI ĐẶT ────────────────────────────

    /**
     * Bốn KIỂU bật tìm kiếm đều phải dựng được request đúng — mỗi gateway một cách, người dùng khai
     * trong Cài đặt (không sửa mã): cờ body · tools · NỐI TÊN MODEL · plugins.
     */
    public function test_search_modes_declared_in_settings_build_the_right_request(): void
    {
        $cases = [
            ['body_flag', 'enable_search', fn (array $b) => ($b['enable_search'] ?? null) === true],
            ['tools', 'google_search', fn (array $b) => ($b['tools'][0]['google_search'] ?? null) !== null],
            ['model_suffix', ':online', fn (array $b) => str_ends_with((string) $b['model'], ':online')],
            ['plugins', 'web', fn (array $b) => ($b['plugins'][0]['id'] ?? null) === 'web'],
        ];

        foreach ($cases as $i => [$mode, $param, $check]) {
            $slug = 'gw-mode-'.$i;
            \App\Models\StudioProvider::create([
                'slug' => $slug, 'name' => 'Gateway '.$mode, 'protocol' => 'openai',
                'base_url' => 'https://'.$slug.'.example/v1', 'auth_style' => 'bearer',
                'search_param' => $param, 'search_mode' => $mode, 'api_key_ref' => $slug,
                'priority' => 9, 'enabled' => true,
            ]);
            \App\Models\StudioApiKey::create([
                'provider' => $slug, 'label' => $slug, 'value' => 'sk-'.$i,
                'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
            ]);
            \App\Models\StudioModel::create([
                'group' => 'prompt', 'name' => 'Model '.$mode, 'provider' => $slug,
                'model_id' => 'm-'.$i, 'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
            ]);
            set_setting('studio_task_prompt_model', $slug.':m-'.$i);
            Cache::flush();

            Http::fake([$slug.'.example/*' => Http::response(['choices' => [['message' => ['content' => $this->validBriefJson()]]]], 200)]);
            app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen '.$i], $this->customer(), true);

            $sent = Http::recorded();
            $this->assertNotEmpty($sent, 'Không có request cho kiểu '.$mode);
            $body = (array) json_decode((string) $sent[0][0]->body(), true);
            $this->assertTrue($check($body), 'Kiểu '.$mode.' dựng request sai: '.json_encode($body, JSON_UNESCAPED_UNICODE));
        }
    }

    /** Cấu hình model + key dùng chung cho các test bộ đệm. */
    private function configurePromptGateway(string $slug, string $modelId): void
    {
        \App\Models\StudioProvider::create([
            'slug' => $slug, 'name' => 'Gateway '.$slug, 'protocol' => 'openai',
            'base_url' => 'https://'.$slug.'.example/v1', 'auth_style' => 'bearer',
            'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => $slug, 'label' => $slug, 'value' => 'sk-'.$slug,
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        \App\Models\StudioModel::create([
            'group' => 'prompt', 'name' => $modelId, 'provider' => $slug,
            'model_id' => $modelId, 'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', $slug.':'.$modelId);
    }

    /** JSON hợp lệ cho CollectionBot (đủ trường để normalizeAiBrief nhận). */
    private function validBriefJson(): string
    {
        return json_encode([
            'narrative' => 'DNA thương hiệu', 'brief' => 'Brief cho xưởng', 'prompt_vi' => 'vi', 'prompt_en' => 'en',
            'moodboard_captions' => array_fill(0, 24, 'caption'), 'category_rationale' => [], 'outfit_goals' => [],
            'next_steps' => ['a', 'b', 'c'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Fake mọi gateway: trả JSON hợp lệ cho brief. */
    private function fakeBrief(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $this->validBriefJson()]]]], 200)]);
    }

    /**
     * Khai báo trong Cài đặt KHÔNG phải bằng chứng: đo thật 23/09/2026 — DeepSeek nhận `enable_search`
     * với HTTP 200 nhưng BỎ QUA, model vẫn nói "không có quyền truy cập thông tin thời gian thực". Giao diện
     * phải phân biệt hai mức tin, nếu không nó hứa hộ người dùng một năng lực không tồn tại.
     */
    public function test_declared_search_is_unverified_while_protocol_search_is_verified(): void
    {
        // (a) Giao thức: chắc chắn — chính mã này dựng request đúng chuẩn của giao thức đó.
        $dialect = WebAccessService::planFor(['transport' => 'qwen', 'provider' => 'qwen', 'model' => 'qwen3.8-flash']);
        $this->assertTrue($dialect['verified']);
        $this->assertStringContainsString('giao thức', $dialect['source']);

        // (b) Do người dùng khai: vẫn ra kế hoạch, nhưng đánh dấu CHƯA kiểm chứng.
        $declared = WebAccessService::planFor([
            'transport' => 'openai', 'provider' => 'gateway-x', 'model' => 'm',
            'search_param' => 'enable_search', 'search_mode' => 'body_flag',
        ]);
        $this->assertFalse($declared['verified']);
        $this->assertStringContainsString('khai trong Cài đặt', $declared['source']);

        // (c) Câu kết luận cũng phải khác nhau giữa hai mức tin.
        $this->configurePromptGateway('gw-khai', 'model-khai');
        \App\Models\StudioProvider::where('slug', 'gw-khai')->update([
            'search_param' => 'enable_search', 'search_mode' => 'body_flag',
        ]);
        Cache::flush();
        Http::fake(['*' => Http::response('', 204)]);

        $probe = app(WebAccessService::class)->probe(true);
        $this->assertTrue($probe['model_search']['supported']);
        $this->assertFalse($probe['model_search']['verified'], 'Khai báo không phải bằng chứng.');
        $this->assertStringContainsString('KHAI', $probe['verdict_label']);
        $this->assertStringContainsString('chưa kiểm chứng', $probe['verdict_label']);
    }
}
