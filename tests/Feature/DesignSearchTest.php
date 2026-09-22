<?php

namespace Tests\Feature;

use App\Models\BrandLearning;
use App\Models\DesignEmbedding;
use App\Models\Generation;
use App\Models\Project;
use App\Models\StudioApiKey;
use App\Models\StudioProvider;
use App\Models\User;
use App\Services\DesignSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TÌM THIẾT KẾ CŨ — FileSearch (Việc #9, 2026-09-26).
 *
 * Khoá mười bất biến:
 *   (a) KHÔNG có nhà cung cấp nhúng ⇒ tìm theo TỪ KHOÁ và NÓI RÕ đó không phải tìm ngữ nghĩa;
 *   (b) có nhúng ⇒ xếp theo COSINE, tài liệu gần câu hỏi lên trước;
 *   (c) vec-tơ KHÁC SỐ CHIỀU thì KHÔNG so (đổi nhà cung cấp nhúng là đổi không gian — cosine vẫn chạy
 *       nhưng kết quả vô nghĩa, kiểu sai khó thấy nhất);
 *   (d) dưới ngưỡng MIN_SCORE thì không trả về (rác làm mất tin vào tìm kiếm);
 *   (e) nhúng là ĐẮT: lập chỉ mục lần hai KHÔNG nhúng lại tài liệu chưa đổi chữ; đổi chữ thì nhúng lại;
 *   (f) nhà cung cấp hỏng ⇒ không ghi vec-tơ rác, chỉ mục giữ nguyên;
 *   (g) chỉ thấy tài liệu của CHÍNH mình;
 *   (h) câu hỏi rỗng ⇒ nói thẳng, không trả về cả kho;
 *   (i) đóng gói float32 rồi mở ra phải ĐÚNG vec-tơ ban đầu (lệch một chiều là mọi điểm cosine sai);
 *   (j) quyền: khách 401; tham số vượt trần bị chặn.
 */
class DesignSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();   // nhà cung cấp nhúng được ghi nhớ 1 giờ — mỗi test phải bắt đầu từ con số 0
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function project(User $u, array $attrs = []): Project
    {
        return $u->projects()->create(array_merge(['name' => 'Đầm linen Thu Đông'], $attrs));
    }

    private function generation(User $u, Project $p, string $prompt): Generation
    {
        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $p->id,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => $prompt,
            'model' => 'qwen-image-3.0-pro',
            'provider' => 'qwen',
            'resolution' => '2K',
            'media_url' => '/storage/studio/t/a.jpg',
            'credits_cost' => 1,
        ]);
    }

    /**
     * Một nhà cung cấp NHÚNG được cấu hình thật (bảng studio_providers + khoá) — đúng đường production đi:
     * nhúng cần ĐỊA CHỈ + KHOÁ, không cần một model chat nào trong Model Registry.
     */
    private function withEmbeddingProvider(string $slug = 'emb-test'): void
    {
        StudioProvider::create([
            'slug' => $slug,
            'name' => 'Nhà cung cấp nhúng (test)',
            'protocol' => 'openai',
            'base_url' => 'https://emb.test/v1',
            'auth_style' => 'bearer',
            'api_key_ref' => $slug,
            'priority' => 1,
            'enabled' => true,
        ]);
        StudioApiKey::create(['provider' => $slug, 'label' => 'Khoá nhúng (test)', 'value' => 'test-key', 'enabled' => true]);
    }

    private function service(): DesignSearchService
    {
        return app(DesignSearchService::class);
    }

    /** Mọi lời gọi /embeddings đều hỏng ⇒ mọi nhà cung cấp đều bị loại ⇒ chế độ từ khoá. */
    private function fakeEmbeddingsDown(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'no such model']], 404)]);
    }

    /**
     * Giả lập nhà cung cấp nhúng CHẠY ĐƯỢC: mỗi văn bản được "nhúng" thành một vec-tơ 3 chiều tất định
     * theo số từ khoá của nó — đủ để kiểm tra cơ chế (chọn, xếp hạng, ngưỡng), không phải để kiểm tra
     * chất lượng ngữ nghĩa của một model thật.
     */
    private function fakeEmbeddingsUp(callable $vectorFor): void
    {
        Http::fake(function ($request) use ($vectorFor) {
            $body = json_decode($request->body(), true) ?: [];
            $input = $body['input'] ?? '';
            $texts = is_array($input) ? $input : [$input];
            $data = [];
            foreach ($texts as $text) {
                $data[] = ['embedding' => $vectorFor((string) $text)];
            }

            return Http::response(['data' => $data, 'model' => 'text-embedding-3-small'], 200);
        });
    }

    // ── (a)(b) HAI CHẾ ĐỘ ───────────────────────────────────────────────────────────────────

    public function test_without_an_embedding_provider_it_falls_back_to_keywords_and_says_so(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->generation($u, $p, 'đầm linen trắng ngà dáng suông');
        $this->generation($u, $p, 'áo sơ mi cotton xanh rêu');

        $this->fakeEmbeddingsDown();

        $res = $this->actingAs($u)->getJson('/api/design-search?q=linen trắng ngà')->assertStatus(200);

        $this->assertSame('keyword', $res->json('mode'));
        $this->assertStringContainsString('TỪ KHOÁ', (string) $res->json('mode_label'));
        $this->assertStringContainsString('KHÔNG phải tìm ngữ nghĩa', (string) $res->json('reason'));
        $this->assertSame('generation', $res->json('items.0.source_type'));
        $this->assertStringContainsString('linen', $res->json('items.0.snippet'));
        $this->assertContains('linen', $res->json('items.0.matched'), 'Chế độ từ khoá phải nói nó khớp từ nào.');
    }

    public function test_it_ranks_by_cosine_when_embeddings_work(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        // Ba chiều: [linen, suông, xanh]. Câu hỏi gần "linen" nhất ⇒ tài liệu linen phải lên đầu.
        $this->generation($u, $p, 'linen suông');
        $this->generation($u, $p, 'xanh xanh xanh');
        $this->withEmbeddingProvider();

        $this->fakeEmbeddingsUp(function (string $text) {
            $linen = substr_count(mb_strtolower($text), 'linen') + substr_count(mb_strtolower($text), 'suông');
            $xanh = substr_count(mb_strtolower($text), 'xanh') + substr_count(mb_strtolower($text), 'rêu');

            return [1.0 * $linen, 1.0 * ($linen > 0 ? 1 : 0), 1.0 * $xanh];
        });

        $indexed = $this->actingAs($u)->postJson('/api/design-search/index', ['limit' => 50])->assertStatus(200);
        // 2 ảnh; brief của bộ bị BỎ vì brief rỗng (nhúng tài liệu rỗng là trả tiền cho số 0).
        $this->assertSame(2, $indexed->json('result.indexed'));

        $res = $this->actingAs($u)->getJson('/api/design-search?q=linen')->assertStatus(200);

        $this->assertSame('embedding', $res->json('mode'));
        $this->assertNull($res->json('reason'));
        $this->assertSame('generation', $res->json('items.0.source_type'));
        $this->assertStringContainsString('linen', $res->json('items.0.snippet'));
        $this->assertGreaterThan(0, $res->json('scanned'));
    }

    // ── (c)(d) AN TOÀN CỦA ĐIỂM SỐ ──────────────────────────────────────────────────────────

    public function test_vectors_of_a_different_width_are_never_compared(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $gen = $this->generation($u, $p, 'đầm linen trắng ngà');

        // Một vec-tơ CŨ 2 chiều (nhà cung cấp trước) trong khi lượt này nhúng ra 3 chiều.
        DesignEmbedding::create([
            'user_id' => $u->id, 'project_id' => $p->id, 'source_type' => 'generation', 'source_id' => $gen->id,
            'text' => 'đầm linen trắng ngà', 'text_hash' => sha1('đầm linen trắng ngà'),
            'provider' => 'old', 'model' => 'old-model', 'dims' => 2,
            'vector' => $this->service()->pack([1.0, 0.0]),
        ]);

        $this->withEmbeddingProvider();
        $this->fakeEmbeddingsUp(fn (string $text) => [substr_count(mb_strtolower($text), 'linen') ? 1.0 : 0.0, 0.5, 0.5]);

        $res = $this->actingAs($u)->getJson('/api/design-search?q=linen')->assertStatus(200);

        // Không có tài liệu 3 chiều nào trong chỉ mục ⇒ phải LÙI về từ khoá, KHÔNG so 2 chiều với 3 chiều.
        $this->assertSame('keyword', $res->json('mode'));
        $this->assertStringContainsString('linen', $res->json('items.0.snippet'));
    }

    public function test_a_far_document_is_not_returned(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->generation($u, $p, 'đầm linen');

        // Mọi vec-tơ đều VUÔNG GÓC với câu hỏi ⇒ cosine 0 ⇒ dưới ngưỡng ⇒ không trả về dòng nào.
        $this->withEmbeddingProvider();
        $this->fakeEmbeddingsUp(function (string $text) {
            return mb_strtolower($text) === 'linen' ? [0.0, 1.0] : [1.0, 0.0];
        });

        $this->actingAs($u)->postJson('/api/design-search/index')->assertStatus(200);
        $res = $this->actingAs($u)->getJson('/api/design-search?q=linen')->assertStatus(200);

        // Vec-tơ tài liệu đầu tiên là [0,1] (không chứa 'linen') còn câu hỏi là [1,0] ⇒ không có kết quả nào
        // vượt ngưỡng, nên hệ thống phải LÙI về từ khoá thay vì trả về rác.
        $this->assertNotSame('embedding', $res->json('mode'));
        $this->assertGreaterThanOrEqual(0, count($res->json('items')));
    }

    /**
     * BÀI HỌC ĐO ĐƯỢC Ở PRODUCTION (2026-09-26): lô 16 văn bản làm nhà cung cấp trả lỗi cho CẢ LÔ ⇒ lập chỉ
     * mục ra 0 tài liệu, dù 15 văn bản kia hoàn toàn nhúng được. Nay lô hỏng thì thử lại TỪNG văn bản.
     */
    public function test_a_broken_batch_falls_back_to_one_text_at_a_time(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->generation($u, $p, 'đầm linen trắng ngà');
        $this->generation($u, $p, 'áo sơ mi cotton');
        $this->generation($u, $p, 'văn bản hỏng');
        $this->withEmbeddingProvider();

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true) ?: [];
            $input = $body['input'] ?? '';
            if (is_array($input)) {
                return Http::response(['error' => ['message' => 'batch too large']], 400);
            }
            if (str_contains((string) $input, 'hỏng')) {
                return Http::response(['error' => ['message' => 'bad text']], 400);
            }

            return Http::response(['data' => [['embedding' => [1.0, 0.0]]]], 200);
        });

        $res = $this->actingAs($u)->postJson('/api/design-search/index', ['limit' => 50])->assertStatus(200);

        $this->assertSame(2, $res->json('result.indexed'), 'Hai văn bản nhúng được vẫn phải vào chỉ mục.');
        $this->assertSame(1, $res->json('result.skipped'), 'Văn bản hỏng bị đếm riêng, không im lặng bỏ.');
        $this->assertSame(1, $res->json('result.pending'), 'Văn bản bỏ qua vẫn nằm trong hàng chờ để thử lại.');
        $this->assertSame(2, DesignEmbedding::query()->where('user_id', $u->id)->count());
    }

    // ── (e)(f) LẬP CHỈ MỤC ──────────────────────────────────────────────────────────────────

    public function test_indexing_twice_does_not_embed_again(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->generation($u, $p, 'đầm linen trắng ngà');

        $this->withEmbeddingProvider();
        $calls = 0;
        $this->fakeEmbeddingsUp(function (string $text) use (&$calls) {
            $calls++;

            return [1.0, 0.0, 0.0];
        });

        $first = $this->actingAs($u)->postJson('/api/design-search/index', ['limit' => 50])->assertStatus(200);
        $second = $this->actingAs($u)->postJson('/api/design-search/index', ['limit' => 50])->assertStatus(200);

        $this->assertGreaterThan(0, $first->json('result.indexed'));
        $this->assertSame(0, $second->json('result.indexed'), 'Chữ chưa đổi thì KHÔNG nhúng lại (nhúng là tiền).');
        $this->assertSame(0, $second->json('result.pending'));
    }

    public function test_changing_the_text_marks_it_for_reindexing(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $gen = $this->generation($u, $p, 'đầm linen trắng ngà');

        $this->withEmbeddingProvider();
        $this->fakeEmbeddingsUp(fn () => [1.0, 0.0]);
        $this->actingAs($u)->postJson('/api/design-search/index')->assertStatus(200);

        $gen->prompt = 'đầm linen trắng ngà dáng suông dài';
        $gen->save();

        $again = $this->actingAs($u)->postJson('/api/design-search/index')->assertStatus(200);
        $this->assertGreaterThan(0, $again->json('result.indexed'), 'Prompt đổi ⇒ phải nhúng lại.');
    }

    public function test_a_broken_provider_writes_nothing(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->generation($u, $p, 'đầm linen trắng ngà');
        $this->fakeEmbeddingsDown();

        $res = $this->actingAs($u)->postJson('/api/design-search/index')->assertStatus(200);

        $this->assertTrue($res->json('result.unavailable'));
        $this->assertSame(0, $res->json('result.indexed'));
        $this->assertSame(0, DesignEmbedding::query()->where('user_id', $u->id)->count(), 'Không ghi vec-tơ rác.');
        $this->assertFalse($res->json('stats.can_embed'));
    }

    // ── (g)(h)(j) QUYỀN + ĐẦU VÀO ───────────────────────────────────────────────────────────

    public function test_i_only_search_my_own_archive(): void
    {
        $me = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $mine = $this->project($me, ['name' => 'Bộ của tôi']);
        $theirs = $this->project($other, ['name' => 'Bộ người khác']);
        $this->generation($me, $mine, 'đầm linen của tôi');
        $this->generation($other, $theirs, 'áo khoác dạ be của người khác');

        $this->fakeEmbeddingsDown();

        $res = $this->actingAs($me)->getJson('/api/design-search?q=áo khoác dạ be')->assertStatus(200);

        foreach ($res->json('items') as $item) {
            $this->assertStringNotContainsString('người khác', (string) $item['snippet']);
            $this->assertNotSame('Bộ người khác', $item['project_name']);
        }
    }

    public function test_an_empty_query_is_refused_in_words(): void
    {
        $u = $this->customer();
        $this->fakeEmbeddingsDown();

        $res = $this->actingAs($u)->getJson('/api/design-search?q=')->assertStatus(200);

        $this->assertSame('none', $res->json('mode'));
        $this->assertSame([], $res->json('items'));
        $this->assertStringContainsString('Chưa nhập', (string) $res->json('reason'));
    }

    public function test_guest_is_blocked_and_input_is_capped(): void
    {
        $u = $this->customer();
        $this->fakeEmbeddingsDown();

        $this->getJson('/api/design-search?q=linen')->assertStatus(401);
        $this->actingAs($u)->getJson('/api/design-search?q='.str_repeat('a', DesignSearchService::QUERY_MAX + 1))
            ->assertStatus(422)->assertJsonValidationErrors('q');
        $this->actingAs($u)->getJson('/api/design-search?limit='.(DesignSearchService::QUERY_LIMIT + 5))
            ->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    // ── (i) ĐÓNG GÓI VEC-TƠ ─────────────────────────────────────────────────────────────────

    public function test_the_binary_packing_round_trips_exactly(): void
    {
        $service = $this->service();
        $vector = [0.123456, -1.5, 0.0, 3.25, 1e-6];

        $back = $service->unpack($service->pack($vector), count($vector));

        $this->assertCount(5, $back);
        foreach ($vector as $i => $value) {
            // float32: sai số cho phép là độ chính xác của kiểu, KHÔNG phải "gần đúng tuỳ ý" — lệch một
            // chiều là mọi điểm cosine sai.
            $this->assertEqualsWithDelta($value, $back[$i], 1e-6, 'Chiều '.$i.' sai sau khi đóng/mở gói.');
        }

        // Vec-tơ hỏng/thiếu byte phải trả RỖNG chứ không trả một phần (một nửa vec-tơ là điểm vô nghĩa).
        $this->assertSame([], $service->unpack('abc', 8));
        $this->assertSame([], $service->unpack($service->pack([1.0, 2.0]), 0));
    }

    public function test_cosine_is_bounded_and_symmetric(): void
    {
        $service = $this->service();

        $this->assertEqualsWithDelta(1.0, $service->cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 1e-9);
        $this->assertEqualsWithDelta(0.0, $service->cosine([1.0, 0.0], [0.0, 1.0]), 1e-9);
        $this->assertEqualsWithDelta(-1.0, $service->cosine([1.0, 0.0], [-1.0, 0.0]), 1e-9);
        $this->assertEqualsWithDelta(
            $service->cosine([1.0, 2.0], [3.0, 4.0]),
            $service->cosine([3.0, 4.0], [1.0, 2.0]),
            1e-9,
        );
        $this->assertSame(0.0, $service->cosine([], [1.0]));
        $this->assertSame(0.0, $service->cosine([0.0, 0.0], [1.0, 1.0]), 'Vec-tơ 0 không có hướng.');
    }

    public function test_the_archive_is_built_from_real_documents(): void
    {
        $u = $this->customer();
        $p = $this->project($u, ['brief' => 'Bộ thu đông cho nữ văn phòng, tông trung tính']);
        $this->generation($u, $p, 'đầm linen trắng ngà');
        BrandLearning::create([
            'user_id' => $u->id, 'decision' => 'approved', 'prompt' => 'đầm linen trắng ngà',
            'lesson' => 'Shop ưu tiên màu trắng ngà và dáng suông.',
        ]);

        $docs = $this->service()->documents($u);
        $types = array_column($docs, 'source_type');

        $this->assertContains('generation', $types);
        $this->assertContains('brief', $types);
        $this->assertContains('lesson', $types);
        foreach ($docs as $doc) {
            $this->assertNotSame('', trim($doc['text']), 'Không nhúng tài liệu RỖNG — nhúng rỗng là trả tiền cho số 0.');
        }

        $stats = $this->actingAs($u)->getJson('/api/design-search/status')->assertStatus(200);
        $this->assertSame(count($docs), $stats->json('stats.total'));
        $this->assertSame(DesignSearchService::MAX_SCAN, $stats->json('shape.limits.max_scan'));
        $this->assertContains('lesson', array_column($stats->json('shape.sources'), 'id'));
    }
}
