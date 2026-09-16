<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\StudioModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CACHE đúng (không phục vụ dữ liệu cũ) cho hai thứ vừa được cache ở vòng 20:
 *   Setting::CACHE_ALL        — cả bảng settings, rememberForever
 *   StudioModel::CACHE_KEY*   — Model Registry, TTL 60s
 *
 * Nguyên tắc: thêm cache thì phải rà MỌI đường ghi và bảo đảm chúng xoá cache. File này khoá
 * nguyên tắc đó bằng test cho từng đường ghi thực tế của app.
 *
 * Đã rà (vòng 21): mọi đường ghi studio_models đều qua Eloquent create/update/delete -> model event
 * 'saved'/'deleted' trong StudioModel::booted() xoá cache ✓. Với settings, chỉ seeder là KHÔNG đi
 * qua Setting::set() — đã sửa seeder sang set_setting() và khoá lại bằng test bên dưới.
 */
class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::flushCache();
    }

    // ── settings ──────────────────────────────────────────────────────────

    public function test_setting_write_invalidates_cache(): void
    {
        set_setting('studio_zz_probe', 'giá trị đầu');
        $this->assertSame('giá trị đầu', setting('studio_zz_probe'));   // làm ấm cache

        set_setting('studio_zz_probe', 'giá trị sau');
        $this->assertSame('giá trị sau', setting('studio_zz_probe'), 'Cache phục vụ giá trị CŨ sau khi ghi.');
    }

    public function test_setting_cache_is_shared_across_keys(): void
    {
        // Đọc 1 key làm ấm cache cả bảng -> các key khác không phát sinh query mới.
        set_setting('studio_zz_a', 'A');
        set_setting('studio_zz_b', 'B');
        Setting::flushCache();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->assertSame('A', setting('studio_zz_a'));
        $this->assertSame('B', setting('studio_zz_b'));
        $this->assertSame('A', setting('studio_zz_a'));
        $q = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $settingsQueries = 0;
        foreach ($q as $row) {
            if (str_contains((string) $row['query'], 'from "settings"')) {
                $settingsQueries++;
            }
        }
        $this->assertLessThanOrEqual(1, $settingsQueries, "Đọc 3 key (2 key khác nhau) tốn {$settingsQueries} query settings — phải là 1.");
    }

    public function test_direct_model_write_invalidates_settings_cache(): void
    {
        // Đây là dạng lỗi đã xảy ra THẬT: cache "cả bảng" chỉ được xoá bởi Setting::set(), nên mọi
        // đường ghi KHÁC (seeder updateOrCreate hàng loạt, console command, DB seeder sau này…) đều
        // để lại cache cũ. Nay model event ở Setting::booted() tự xoá ở mọi đường ghi qua Eloquent,
        // nên test này ghi thẳng bằng updateOrCreate() — cố tình KHÔNG đi qua set().
        set_setting('cache_probe', 'GIÁ TRỊ CŨ');
        $this->assertSame('GIÁ TRỊ CŨ', setting('cache_probe'));   // làm ấm cache

        Setting::updateOrCreate(['key' => 'cache_probe'], ['value' => 'GIÁ TRỊ MỚI']);

        $this->assertSame('GIÁ TRỊ MỚI', setting('cache_probe'),
            'Ghi thẳng qua model mà cache vẫn phục vụ giá trị cũ -> Setting::booted() chưa xoá cache.');
    }

    public function test_model_delete_invalidates_cache(): void
    {
        set_setting('cache_probe_del', 'CÓ MẶT');
        $this->assertSame('CÓ MẶT', setting('cache_probe_del'));

        Setting::where('key', 'cache_probe_del')->first()->delete();   // xoá qua INSTANCE -> có event

        $this->assertNull(setting('cache_probe_del'), 'Xoá setting (instance) mà cache vẫn còn giá trị cũ.');
    }

    public function test_bulk_query_builder_writes_bypass_events_and_need_explicit_flush(): void
    {
        // GIỚI HẠN ĐÃ BIẾT của Eloquent (không phải bug của repo): ghi/xoá HÀNG LOẠT qua query
        // builder KHÔNG kích hoạt model event, nên Setting::booted() không chạy.
        // Test này ghi nhận đúng hành vi đó để lần sau không ai tưởng "đã an toàn tuyệt đối",
        // đồng thời chỉ ra cách xử lý: gọi Setting::flushCache() tường minh.
        set_setting('cache_probe_bulk', 'CŨ');
        $this->assertSame('CŨ', setting('cache_probe_bulk'));

        Setting::where('key', 'cache_probe_bulk')->update(['value' => 'MỚI-QUA-QUERY-BUILDER']);

        $this->assertSame('CŨ', setting('cache_probe_bulk'),
            'Nếu assertion này ĐỔI thành MỚI thì Eloquent đã bắt đầu bắn event cho bulk update — '
            .'khi đó cập nhật lại ghi chú này.');

        Setting::flushCache();

        $this->assertSame('MỚI-QUA-QUERY-BUILDER', setting('cache_probe_bulk'),
            'flushCache() phải là cách xử lý cho đường ghi hàng loạt.');
    }

    // ── model registry ────────────────────────────────────────────────────

    public function test_model_registry_cache_invalidates_on_create(): void
    {
        Cache::forget(StudioModel::CACHE_KEY);
        Cache::forget(StudioModel::CACHE_KEY_ALL);

        $groups = array_keys(studio_task_groups());
        $group = $groups[0];

        $before = collect(studio_task_group_models($group))->pluck('model')->all();

        $row = StudioModel::create([
            'group' => $group, 'name' => 'Model mới do test', 'provider' => 'qwen_test',
            'model_id' => 'model-moi-do-test', 'api_key_ref' => 'qwen', 'priority' => 99, 'enabled' => true,
        ]);

        $after = collect(studio_task_group_models($group))->pluck('model')->all();
        $this->assertContains('model-moi-do-test', $after, 'Thêm model mới nhưng cache registry chưa được xoá.');
        $this->assertNotSame($before, $after);

        // Xoá -> cũng phải biến mất khỏi danh sách ngay.
        $row->delete();
        $afterDelete = collect(studio_task_group_models($group))->pluck('model')->all();
        $this->assertNotContains('model-moi-do-test', $afterDelete, 'Xoá model nhưng cache registry vẫn còn.');
    }

    public function test_model_registry_cache_invalidates_on_update(): void
    {
        Cache::forget(StudioModel::CACHE_KEY);
        Cache::forget(StudioModel::CACHE_KEY_ALL);

        $groups = array_keys(studio_task_groups());
        $group = $groups[0];

        $row = StudioModel::create([
            'group' => $group, 'name' => 'Model tạm', 'provider' => 'qwen_test2',
            'model_id' => 'model-tam', 'api_key_ref' => 'qwen', 'priority' => 98, 'enabled' => true,
        ]);
        $this->assertContains('model-tam', collect(studio_task_group_models($group))->pluck('model')->all());

        // Tắt enabled -> phải rời danh sách ngay (không phải chờ TTL 60s).
        $row->update(['enabled' => false]);
        $this->assertNotContains(
            'model-tam',
            collect(studio_task_group_models($group))->pluck('model')->all(),
            'Tắt enabled nhưng cache registry vẫn trả model đó.'
        );

        $row->delete();
    }
}
