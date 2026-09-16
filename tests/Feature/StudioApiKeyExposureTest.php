<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BẤT BIẾN: vật liệu khoá API KHÔNG BAO GIỜ rời server.
 *
 * Báo cáo gốc (top-5 #2) đánh dấu JSON của settings là "diện rò" của issue API-key-xuống-trình-duyệt.
 * Bản vá là \$hidden = ['value'] trên model StudioApiKey + mutator mã hoá ở tầng model. Nhưng
 * KHÔNG có test nào khoá tính chất đó — và settingsData() trả về CẢ COLLECTION MODEL THÔ
 * (StudioApiKey::...->get()), nghĩa là tính chất an toàn phụ thuộc HOÀN TOÀN vào \$hidden.
 * Chỉ cần ai đó thêm ->makeVisible('value') hoặc bỏ \$hidden là khoá chảy ra ngay.
 *
 * Test này khoá ở mức HTTP: không endpoint settings nào được chứa giá trị khoá — cả bản rõ LẪN
 * bản mã hoá (kẻ tấn công lấy được ciphertext + APP_KEY là giải được).
 */
class StudioApiKeyExposureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-super-secret-value-9f3a1c7e';

    /** Nhãn ASCII để so khớp trên JSON đã decode (tránh vấn đề escape unicode của body thô). */
    private const LABEL = 'Key nhan dang E2E';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@trillfa.com')->firstOrFail();
    }

    /** Tạo một key với giá trị nhận dạng được; trả [model, ciphertext thô]. */
    private function makeKey(): array
    {
        $key = StudioApiKey::create([
            'provider' => 'qwen',
            'label' => self::LABEL,
            'value' => self::SECRET,
            'kind' => 'paygo',
            'priority' => 5,
            'enabled' => true,
        ]);
        $key->refresh();

        return [$key, (string) $key->getRawOriginal('value')];
    }

    public static function settingsEndpoints(): array
    {
        return [
            'settings/data (legacy, trả model thô)' => ['/api/settings/data'],
            'settings-vue/data (SPA)' => ['/api/settings-vue/data'],
        ];
    }

    /**
     * Duyệt ĐỆ QUY JSON để test không phụ thuộc hình dạng payload (settings/data trả mảng thô còn
     * settings-vue/data trả object có api_keys) — và để bắt được field lọt ra ở BẤT KỲ độ sâu nào.
     */
    private function walkJson($node, array &$keys, array &$values): void
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $keys[] = (string) $k;
                $this->walkJson($v, $keys, $values);
            }

            return;
        }
        $values[] = (string) $node;
    }

    #[DataProvider('settingsEndpoints')]
    public function test_settings_endpoints_never_expose_key_material(string $uri): void
    {
        [, $cipher] = $this->makeKey();

        $res = $this->actingAs($this->admin())->getJson($uri)->assertOk();

        $keys = [];
        $values = [];
        $this->walkJson($res->json(), $keys, $values);

        // ASSERT DƯƠNG — chứng minh endpoint THẬT SỰ trả metadata của key, nếu không test sẽ rỗng
        // (endpoint không trả gì thì "không rò" là vô nghĩa).
        $this->assertContains(self::LABEL, $values, 'Endpoint phải trả metadata của key (label).');

        // Bản rõ không được xuất hiện ở bất kỳ đâu.
        foreach ($values as $v) {
            $this->assertStringNotContainsString(self::SECRET, $v, 'Giá trị khoá dạng BẢN RÕ bị rò.');
            // Bản mã hoá cũng không (có APP_KEY là giải ngược được).
            $this->assertStringNotContainsString($cipher, $v, 'Giá trị khoá dạng MÃ HOÁ bị rò.');
        }

        // Và không có field tên 'value' nào lọt ra ở bất kỳ độ sâu nào.
        $this->assertNotContains('value', $keys, "Field 'value' lọt ra JSON.");
    }

    public function test_settings_data_endpoint_actually_works(): void
    {
        // [BUG đã vá vòng 15] settingsData() và settingsSave() khai kiểu trả về
        // 'IlluminateHttpJsonResponse' — MẤT dấu '\' đầu -> PHP resolve thành
        // App\Http\Controllers\IlluminateHttpJsonResponse (không tồn tại) -> LUÔN ném TypeError
        // -> route trả 500 mọi lúc. Lỗi có từ trước khi tách app và chưa từng bị phát hiện vì
        // KHÔNG có test nào gọi 2 route này.
        $this->actingAs($this->admin())->getJson('/api/settings/data')->assertOk();
        $this->actingAs($this->admin())->postJson('/api/settings/save', [])->assertOk();
    }

    public function test_key_value_is_encrypted_at_rest(): void
    {
        [, $cipher] = $this->makeKey();

        // Ghi qua mass-assignment vẫn phải mã hoá (mutator ở TẦNG MODEL, không phụ thuộc controller).
        $this->assertNotSame(self::SECRET, $cipher, 'Giá trị phải được mã hoá khi lưu.');
        $this->assertStringNotContainsString(self::SECRET, $cipher);
    }

    public function test_guest_cannot_reach_settings_endpoints(): void
    {
        $this->makeKey();

        foreach (['/api/settings/data', '/api/settings-vue/data'] as $uri) {
            $this->getJson($uri)->assertStatus(401);
        }
    }
}
