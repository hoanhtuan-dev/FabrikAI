<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\SuggestResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [2026-09-22] Tiến trình THẬT khi AI suy luận + danh sách 10 gợi ý mới nhất.
 *
 * POST /api/suggest/stream  → NDJSON: phase → provider → result (card hiện tiến trình)
 * GET  /api/suggest/recent  → 10 kết quả mới nhất của CHÍNH người dùng
 * POST /api/suggest         → giữ nguyên JSON, nay kèm _meta (provider/model/thời gian)
 *
 * [Đổi chính sách 2026-09-22 — Yêu cầu: "thông báo/chỉ báo/tiến trình không rò rỉ chi tiết kỹ thuật
 *  phía backend, tên model AI, nhà cung cấp". Trước đây bài test này KHẲNG ĐỊNH ĐIỀU NGƯỢC LẠI
 *  ("Phase 'vision' phải nói rõ provider/model để UI hiển thị trung thực"): nhãn tiến trình và
 *  thông báo lỗi đều nhét thẳng 'deepseek · deepseek-flash' ra giao diện. Nay:
 *    · NHÃN TIẾN TRÌNH + THÔNG BÁO LỖI = câu nói với NGƯỜI DÙNG (không provider/model/lỗi thô);
 *    · chi tiết kỹ thuật vẫn còn nguyên trong sự kiện 'provider'/_meta và trong log.
 *  Luật đầy đủ: docs/DESIGN_SYSTEM.md §6.]
 */
class SuggestStreamTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function deepseekReady(): void
    {
        StudioModel::create([
            'group' => 'vision', 'name' => 'DeepSeek Flash', 'provider' => 'deepseek',
            'model_id' => 'deepseek-flash', 'api_key_ref' => 'deepseek', 'priority' => 10, 'enabled' => true,
        ]);
        StudioApiKey::create([
            'provider' => 'deepseek', 'label' => 'DeepSeek', 'value' => 'sk-deepseek-test',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
    }

    private function visionJson(): string
    {
        return json_encode([
            'styles' => ['editorial'], 'background' => 'studio', 'pose' => 'standing',
            'fabric' => 'silk', 'silhouette' => 'midi dress', 'camera' => '50mm',
            'garment_type' => 'midi dress', 'color_palette' => ['ivory'], 'embellishment' => 'plain solid',
            'detail_notes' => 'ivory silk midi dress',
            'image_prompt_en' => 'Editorial photo of an ivory silk midi dress.',
            'prompt_vi' => 'Anh editorial vay lua mau ngoc trai.',
            'video_prompt_en' => 'Catwalk video of the same ivory silk midi dress.',
            'keywords' => ['silk'],
        ]);
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->image('ref.jpg', 240, 320);
    }

    // ── 1. Stream: phase + provider + result ─────────────────────────────────

    public function test_stream_endpoint_emits_progress_then_result(): void
    {
        $this->deepseekReady();
        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson()]]]], 200),
        ]);

        $this->actingAs($this->admin());
        $response = $this->post('/api/suggest/stream', ['image' => $this->upload()]);
        $response->assertOk();

        $body = $response->streamedContent();
        $lines = array_values(array_filter(array_map('trim', explode("\n", $body))));
        $events = array_map(fn ($l) => json_decode($l, true), $lines);

        $types = array_map(fn ($e) => $e['type'] ?? null, $events);
        $this->assertContains('phase', $types, 'Phải có sự kiện tiến trình');
        $this->assertContains('provider', $types, 'Phải cho biết AI nào đang suy luận');
        $this->assertSame('result', end($types), 'Sự kiện cuối phải là result');

        $provider = collect($events)->firstWhere('type', 'provider');
        $this->assertSame('deepseek', $provider['provider']);
        $this->assertSame('deepseek-flash', $provider['model']);
        $this->assertSame('openai', $provider['transport']);

        $result = collect($events)->firstWhere('type', 'result');
        $this->assertNotEmpty($result['data']['image_prompt_en']);
        $this->assertSame('deepseek', $result['data']['_meta']['provider']);
        $this->assertGreaterThanOrEqual(0, $result['data']['_meta']['elapsed_ms']);

        // Phase 'vision' là câu NÓI VỚI NGƯỜI DÙNG ⇒ KHÔNG được nêu provider/model.
        $vision = collect($events)->first(fn ($e) => ($e['type'] ?? '') === 'phase' && ($e['key'] ?? '') === 'vision');
        $this->assertNotNull($vision);
        foreach (['deepseek', 'deepseek-flash', 'provider', 'model'] as $leak) {
            $this->assertStringNotContainsString($leak, mb_strtolower($vision['label']),
                'Nhãn tiến trình để lộ chi tiết kỹ thuật ('.$leak.') — xem docs/DESIGN_SYSTEM.md §6.');
        }
        $this->assertStringContainsString('đọc ảnh', $vision['label'], 'Nhãn tiến trình phải nói người dùng đang chờ việc gì.');

        // MỌI nhãn phase trong lượt này đều phải sạch — không chỉ riêng 'vision'.
        foreach (collect($events)->where('type', 'phase') as $phase) {
            $this->assertDoesNotMatchRegularExpression(
                '/(deepseek|qwen|gemini|dashscope|replicate|fal|veo|wan|flux|provider|model|http\s*\d{3}|json)/i',
                (string) ($phase['label'] ?? ''),
                'Nhãn tiến trình còn chi tiết kỹ thuật: '.($phase['label'] ?? '')
            );
        }
    }

    public function test_stream_reports_error_event_when_provider_fails(): void
    {
        $this->deepseekReady();
        set_setting('studio_suggest_fallback', '0'); // tắt fallback màu để buộc lỗi
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->actingAs($this->admin());
        $body = $this->post('/api/suggest/stream', ['image' => $this->upload()])->streamedContent();
        $events = array_map(fn ($l) => json_decode($l, true), array_filter(array_map('trim', explode("\n", $body))));

        $error = collect($events)->firstWhere('type', 'error');
        $this->assertNotNull($error, 'Provider lỗi phải phát sự kiện error');

        // Thông báo lỗi hiển thị cho NGƯỜI DÙNG: câu hướng dẫn, KHÔNG có tên model/provider và
        // cũng KHÔNG có nội dung lỗi thô của nhà cung cấp (ở đây là 'boom' từ Http::fake).
        foreach (['deepseek', 'deepseek-flash', 'provider', 'model', 'boom'] as $leak) {
            $this->assertStringNotContainsString($leak, mb_strtolower($error['message']),
                'Thông báo lỗi để lộ chi tiết kỹ thuật ('.$leak.') — xem docs/DESIGN_SYSTEM.md §6.');
        }
        $this->assertStringContainsString('thử lại', mb_strtolower($error['message']),
            'Thông báo lỗi phải nói người dùng nên làm gì tiếp.');
    }

    // ── 2. JSON cũ vẫn chạy + có _meta ──────────────────────────────────────

    public function test_json_suggest_still_works_and_carries_meta(): void
    {
        $this->deepseekReady();
        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson()]]]], 200),
        ]);

        $this->actingAs($this->admin());
        $json = $this->post('/api/suggest', ['image' => $this->upload()])->assertOk()->json();

        $this->assertNotEmpty($json['image_prompt_en']);
        $this->assertSame('deepseek', $json['_meta']['provider']);
        $this->assertSame('deepseek-flash', $json['_meta']['model']);
    }

    // ── 3. Danh sách 10 gợi ý mới nhất ───────────────────────────────────────

    public function test_recent_endpoint_returns_latest_ten_of_own_results_only(): void
    {
        $me = $this->admin();
        $other = User::factory()->create();

        for ($i = 1; $i <= 12; $i++) {
            $me->suggestResults()->create([
                'reference_url' => '/storage/studio/ref/mine-'.$i.'.jpg',
                'garment_type' => 'look-'.$i,
                'styles' => ['s'.$i],
                'image_prompt_en' => 'prompt '.$i,
                'created_at' => now()->subMinutes(20 - $i),
            ]);
        }
        $other->suggestResults()->create([
            'reference_url' => '/storage/studio/ref/theirs.jpg',
            'garment_type' => 'cua-nguoi-khac',
            'image_prompt_en' => 'secret',
        ]);

        $this->actingAs($me);
        $res = $this->getJson('/api/suggest/recent')->assertOk()->json();

        $this->assertCount(10, $res['items'], 'Chỉ trả 10 mục mới nhất');
        $this->assertSame(12, $res['total'], 'Total là tổng số của CHÍNH người dùng');
        $this->assertSame('look-12', $res['items'][0]['garment_type'], 'Mới nhất đứng đầu');
        $this->assertSame('look-3', $res['items'][9]['garment_type'], 'Mục thứ 10 là look-3');

        $types = array_column($res['items'], 'garment_type');
        $this->assertNotContains('cua-nguoi-khac', $types, 'Không được lộ kết quả của người khác');

        // Đủ trường để khôi phục vào card mà không cần gọi thêm.
        foreach (['reference_url', 'image_prompt_en', 'prompt_vi', 'video_prompt_en', 'color_palette', 'keywords', 'ago', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $res['items'][0], 'Thiếu trường '.$key);
        }
    }

    public function test_recent_endpoint_respects_limit_and_requires_auth(): void
    {
        $me = $this->admin();
        for ($i = 1; $i <= 5; $i++) {
            $me->suggestResults()->create(['reference_url' => '/storage/x'.$i.'.jpg', 'image_prompt_en' => 'p'.$i]);
        }

        $this->actingAs($me);
        $this->assertCount(3, $this->getJson('/api/suggest/recent?limit=3')->assertOk()->json('items'));
        // limit=99 bị chặn trần 20, nhưng người dùng chỉ có 5 kết quả ⇒ trả đúng 5.
        $this->assertCount(5, $this->getJson('/api/suggest/recent?limit=99')->assertOk()->json('items'), 'Trần 20 + chỉ có 5 mục');

        $this->postJson('/api/suggest/recent')->assertStatus(405); // chỉ GET
    }
}
