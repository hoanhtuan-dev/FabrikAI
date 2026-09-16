<?php

namespace Tests\Feature;

use App\Models\StudioModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Test cho lỗi CHỈ xuất hiện ở production (phát hiện 2026-09-17 khi deploy fabrikai.shop):
 * cache lưu một Eloquent Collection -> lần ĐỌC lại ném
 *   "The script tried to call a method on an incomplete object. Please ensure that the class
 *    definition Illuminate\Database\Eloquent\Collection ... was loaded before unserialize()"
 * và endpoint trả 500 ở MỌI lần gọi thứ hai trở đi.
 *
 * ⚠️ VÌ SAO BỘ TEST CŨ KHÔNG BẮT ĐƯỢC: phpunit.xml đặt CACHE_STORE=array. Store array giữ giá trị
 * trong bộ nhớ và KHÔNG serialize, nên vòng ghi→đọc không bao giờ chạm tới unserialize().
 * File này chạy trên store CÓ serialize (database) để bịt đúng lỗ hổng đó.
 *
 * Quy tắc rút ra: mọi thứ đi vào cache phải là DỮ LIỆU THUẦN (array/scalar) — không model, không
 * Collection, không object có quan hệ.
 */
class StudioCacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Store serialize thật (bảng cache có sẵn nhờ migration của Laravel + RefreshDatabase).
        config(['cache.default' => 'database']);
        Cache::store('database')->clear();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    public function test_model_registry_is_cached_as_plain_data_not_a_collection(): void
    {
        StudioModel::create([
            'group' => 'image', 'name' => 'Model serialize', 'provider' => 'qwen',
            'model_id' => 'qwen-serialize-test', 'api_key_ref' => 'qwen', 'priority' => 42, 'enabled' => true,
        ]);

        Cache::store('database')->forget(StudioModel::CACHE_KEY);
        Cache::store('database')->forget(StudioModel::CACHE_KEY_ALL);

        // Lần 1: GHI cache. Lần 2: ĐỌC lại -> chính là bước ném lỗi ở production.
        $first = studio_models('image');
        $second = studio_models('image');

        $this->assertNotEmpty($second->all(), 'Đọc cache lần hai phải trả về model.');
        $this->assertSame(
            $first->pluck('model_id')->sort()->values()->all(),
            $second->pluck('model_id')->sort()->values()->all(),
            'Dữ liệu đọc từ cache phải giống lần ghi.'
        );

        // Và mọi phần tử phải truy cập được bằng CẢ mảng (cách các consumer đang dùng).
        $row = $second->firstWhere('model_id', 'qwen-serialize-test');
        $this->assertNotNull($row);
        $this->assertSame('qwen', $row['provider']);
    }

    public function test_enabled_by_group_survives_a_cache_round_trip(): void
    {
        StudioModel::create([
            'group' => 'video', 'name' => 'Video serialize', 'provider' => 'wan',
            'model_id' => 'wan-serialize-test', 'api_key_ref' => 'wan', 'priority' => 7, 'enabled' => true,
        ]);

        Cache::store('database')->forget(StudioModel::CACHE_KEY);

        $first = studio_models_enabled_by_group();
        $second = studio_models_enabled_by_group();   // <- điểm ném lỗi ở production

        $this->assertArrayHasKey('video', $second);
        $this->assertSame('wan-serialize-test', $second['video'][0]['model_id']);

        // Consumer thật (studio_task_group_models) dùng truy cập mảng.
        $candidates = studio_task_group_models('video');
        $this->assertNotEmpty($candidates);
    }

    public function test_defaults_endpoint_survives_repeated_calls_with_a_serializing_cache(): void
    {
        // Đây chính là endpoint đã trả 500 trên production:
        // lần gọi thứ hai đọc cache -> unserialize -> "incomplete object".
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/defaults')->assertOk();
        $this->actingAs($admin)->getJson('/api/defaults')->assertOk();
        $this->actingAs($admin)->getJson('/api/defaults')->assertOk();
    }

    public function test_settings_cache_is_plain_array(): void
    {
        set_setting('serialize_probe', 'giá trị thử');
        Cache::store('database')->forget(\App\Models\Setting::CACHE_ALL);

        $this->assertSame('giá trị thử', setting('serialize_probe'));   // ghi
        $this->assertSame('giá trị thử', setting('serialize_probe'));   // đọc lại từ cache

        $all = \App\Models\Setting::allAsArray();
        $this->assertIsArray($all, 'Setting::allAsArray() phải là mảng thuần để cache an toàn.');
    }
}
