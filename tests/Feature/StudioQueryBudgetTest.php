<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NGÂN SÁCH QUERY cho các endpoint nóng — chống N+1 quay lại âm thầm.
 *
 * Bối cảnh (vòng 20): đo thực tế phát hiện GET /api/defaults tốn **75 query** mỗi lần gọi, trong đó
 *   52x select * from settings where key = ?  (Setting::get() cache theo TỪNG key -> 52 key khác
 *                                              nhau = 52 query; store gọi /api/defaults lúc khởi
 *                                              động SPA nên đây là chi phí mỗi lần mở app)
 *   21x select * from studio_models            (studio_task_group_models() query lại cho MỖI nhóm)
 * Sau khi vá (cache cả bảng settings 1 khoá + nạp model registry 1 lần): còn **9 query**.
 *
 * Ngưỡng ở đây rộng hơn số đo thật (để thay đổi nhỏ hợp lệ không làm đỏ), nhưng CHẶN việc quay lại
 * mức N+1 (52 query) — đó là mục đích của test.
 */
class StudioQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@trillfa.com')->firstOrFail();
    }

    /** Đo một request: trả [tổng query, số query chạm bảng settings]. */
    private function measure(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin())->json('GET', $uri);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $settings = 0;
        foreach ($log as $row) {
            if (str_contains((string) $row['query'], 'from "settings"')) {
                $settings++;
            }
        }

        return [count($log), $settings];
    }

    public function test_defaults_endpoint_stays_within_query_budget(): void
    {
        // Lần 1 có thể ấm cache; lần 2 là trạng thái ổn định mà người dùng thực sự chịu.
        $this->measure('/api/defaults');
        [$total, $settings] = $this->measure('/api/defaults');

        $this->assertLessThanOrEqual(
            15,
            $total,
            "GET /api/defaults tốn {$total} query (ngân sách 15; trước khi vá là 75). Có N+1 quay lại?"
        );
        $this->assertLessThanOrEqual(
            2,
            $settings,
            "GET /api/defaults chạm bảng settings {$settings} lần (trước khi vá là 52). "
            .'Cache cả bảng settings (Setting::map) có bị bỏ không?'
        );
    }

    public function test_library_and_latest_stay_within_query_budget(): void
    {
        [$lib] = $this->measure('/api/library/data');
        $this->assertLessThanOrEqual(25, $lib, "GET /api/library/data tốn {$lib} query (ngân sách 25).");

        [$latest] = $this->measure('/api/latest');
        $this->assertLessThanOrEqual(6, $latest, "GET /api/latest tốn {$latest} query (ngân sách 6).");

        [$boot] = $this->measure('/api/boot');
        $this->assertLessThanOrEqual(3, $boot, "GET /api/boot tốn {$boot} query (ngân sách 3).");
    }

    /**
     * Đếm số query chạm bảng studio_models khi resolve model cho một tập nhóm.
     *
     * QUAN TRỌNG: xoá cache registry TRƯỚC mỗi lần đo. Nếu không, lần đo đầu làm ấm cache và lần đo
     * sau trả 0 — so sánh hai lần đo ở hai trạng thái cache khác nhau là vô nghĩa (đã dính đúng lỗi
     * này khi viết test: 1 nhóm = 2, 7 nhóm = 0).
     */
    private function registryQueriesFor(array $groups): int
    {
        \Illuminate\Support\Facades\Cache::forget(\App\Models\StudioModel::CACHE_KEY);
        \Illuminate\Support\Facades\Cache::forget(\App\Models\StudioModel::CACHE_KEY_ALL);

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($groups as $g) {
            studio_task_group_models($g);
        }

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $n = 0;
        foreach ($log as $row) {
            if (str_contains((string) $row['query'], 'from "studio_models"')) {
                $n++;
            }
        }

        return $n;
    }

    public function test_task_group_model_resolution_is_constant_not_per_group(): void
    {
        // Tính chất đúng không phải "đúng 1 query" (có HAI tập kết quả khác nhau nên có thể có 2
        // query: enabled-theo-nhóm và toàn-bộ-registry) mà là **HẰNG SỐ theo số nhóm** — trước khi
        // vá là O(số nhóm): 7 nhóm = 7 query registry + legacy gọi thêm.
        $groups = array_keys(studio_task_groups());
        $this->assertGreaterThanOrEqual(5, count($groups), 'Tiền đề: phải có nhiều nhóm để phép đo có nghĩa.');

        $oneGroup = $this->registryQueriesFor([$groups[0]]);
        // Lần đo thứ hai (cache đã ấm) cho tập đầy đủ:
        $allGroups = $this->registryQueriesFor($groups);

        $this->assertLessThanOrEqual(
            2,
            $oneGroup,
            "Resolve 1 nhóm tốn {$oneGroup} query registry (trần 2)."
        );
        $this->assertSame(
            $oneGroup,
            $allGroups,
            "Resolve ".count($groups)." nhóm tốn {$allGroups} query registry nhưng 1 nhóm chỉ tốn {$oneGroup} "
            .'— số query KHÔNG được tăng theo số nhóm (N+1 đã quay lại?).'
        );
    }
}
