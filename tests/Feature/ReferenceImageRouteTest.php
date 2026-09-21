<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Services\ImageAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "TẠO BIẾN THỂ TỪ ẢNH" CHẾT VÌ THIẾU KHOÁ — và cách nó phải hành xử (2026-09-21).
 *
 * LỖI THẬT đo trên production: card gửi lượt `refgen`, `generateFromReference()` chỉ nói được với
 * DashScope multimodal, mà luật của `studio_candidate_key` loại khoá Token/Coding-Plan cho nhóm ảnh
 * (host của nó không phục vụ model tạo ảnh). Tài khoản chỉ có khoá Token Plan ⇒ **0 model gọi được** ⇒
 * vòng lặp `continue` im lặng, không log, không lý do, job chết sau **47 ms** với câu chung chung
 * "Không tạo được ảnh mới từ ảnh tham chiếu." — trong khi MỌI card tạo ảnh khác vẫn chạy được.
 *
 * Khoá ở đây: (1) biết TRƯỚC là đường bám trực tiếp có dùng được hay không; (2) khi không dùng được thì
 * KHÔNG chết im lặng — ghi rõ lý do và đi đường MÔ TẢ ảnh gốc; (3) lượt đi đường nào phải được ghi lại.
 */
class ReferenceImageRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        cache()->flush();
    }

    private function imageModel(string $provider, string $modelId, int $priority = 50): void
    {
        StudioModel::create([
            'group' => 'image', 'name' => $provider.' '.$modelId, 'provider' => $provider,
            'model_id' => $modelId, 'api_key_ref' => $provider, 'priority' => $priority, 'enabled' => true,
        ]);
    }

    private function key(string $provider, string $value, ?string $kind = null): void
    {
        StudioApiKey::create([
            'provider' => $provider, 'label' => $provider, 'value' => $value,
            'kind' => $kind, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
    }

    private function gateway(string $slug): void
    {
        StudioProvider::create([
            'slug' => $slug, 'name' => 'Gateway '.$slug, 'protocol' => 'openai',
            'base_url' => 'https://'.$slug.'.example/v1', 'auth_style' => 'bearer',
            'api_key_ref' => $slug, 'priority' => 5, 'enabled' => true,
        ]);
    }

    /** Khoá Token Plan KHÔNG dùng được cho ảnh ⇒ phải biết TRƯỚC, không để người dùng chờ rồi nhận câu chung. */
    public function test_the_direct_reference_route_is_reported_unavailable_when_only_plan_keys_exist(): void
    {
        $this->imageModel('qwen', 'qwen-image-3.0-pro', 90);
        $this->key('qwen', 'sk-sp-token-plan-key', 'plan');

        $this->assertFalse(app(ImageAIService::class)->referenceRouteAvailable(),
            'Khoá Token Plan không phục vụ model tạo ảnh — đường bám trực tiếp phải được báo là KHÔNG dùng được.');
    }

    /** Có khoá trả-theo-dùng thì đường bám trực tiếp dùng được — báo đúng, không hù người dùng vô cớ. */
    public function test_the_direct_reference_route_is_available_with_a_payg_key(): void
    {
        $this->imageModel('qwen', 'qwen-image-3.0-pro', 90);
        $this->key('qwen', 'sk-payg-key', 'paygo');

        $this->assertTrue(app(ImageAIService::class)->referenceRouteAvailable());
    }

    /**
     * Gateway custom (đường đang tạo ảnh được cho mọi card khác) KHÔNG cứu được đường multimodal — nó nói
     * giao thức khác. Nên nếu chỉ có nó thì vẫn phải báo "không dùng được", và app đi đường mô tả.
     */
    public function test_a_custom_openai_gateway_does_not_count_as_the_multimodal_route(): void
    {
        $this->gateway('ckey');
        $this->key('ckey', 'sk-ckey');
        $this->imageModel('ckey', 'phuocanh421994/Qwen_Image_3.0_Pro', 5);

        $this->assertFalse(app(ImageAIService::class)->referenceRouteAvailable());
    }

    /** Bất biến của mã: không chết im lặng, có đường dự phòng, và ghi lại đường đã đi. */
    public function test_the_service_never_fails_silently_and_records_the_route(): void
    {
        $src = (string) file_get_contents(app_path('Services/ImageAIService.php'));

        $this->assertStringContainsString('RefGen: KHÔNG có model sinh ảnh nào gọi được', $src,
            'Thiếu khoá mà không ghi log thì lần sau vẫn phải mò: đúng ca 47 ms không dấu vết.');
        $this->assertStringContainsString('if ($this->refgenAttemptable === 0)', $src,
            'Đường dự phòng phải chỉ chạy khi THẬT SỰ không có model nào gọi được.');
        $this->assertStringContainsString("refgenRoute = 'vision_describe'", $src,
            'Lượt đi đường mô tả phải được GHI LẠI — kết quả khác tính chất với đường bám ảnh gốc.');
        $this->assertStringContainsString("refgenRoute = 'multimodal'", $src);
        $this->assertStringContainsString('refgenViaDescription', $src);

        // Điểm vào phải nói trước đường đi cho người dùng.
        $ctl = (string) file_get_contents(app_path('Http/Controllers/StudioController.php'));
        $this->assertStringContainsString('referenceRouteAvailable()', $ctl);
        $this->assertStringContainsString('không bám từng chi tiết', $ctl);
    }
}
