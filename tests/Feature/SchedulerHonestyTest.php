<?php

namespace Tests\Feature;

use App\Models\WebSource;
use App\Services\DesignAgentService;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "TỰ ĐỘNG MỖI 30 PHÚT" — SỐ ĐO, KHÔNG PHẢI LỜI HỨA (2026-09-23).
 *
 * Lỗi thật bắt được khi deploy: giao diện ghi "FabrikAI tự lấy tin mỗi 30 phút" trong khi host KHÔNG có cron
 * nào gọi \`schedule:run\` (kiểm trên production: 0 khoá mutex của lịch, 0 dòng worker.log, 7 job queue nằm
 * chờ). Câu đó khiến người dùng tin dữ liệu luôn tươi trong khi thực tế chỉ mới khi có người mở màn hình.
 *
 * Nay lịch chạy nền tự ghi NHỊP TIM mỗi 5 phút (TTL 30 phút) và giao diện đọc nhịp đó:
 *   · có nhịp  ⇒ "Máy chủ tự làm mới tin mỗi 30 phút"
 *   · hết nhịp ⇒ "chưa bật lịch chạy nền — tin mới khi bạn mở màn hình hoặc bấm Cập nhật tin"
 */
class SchedulerHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    private function source(): WebSource
    {
        return WebSource::create([
            'slug' => 'nguon-tin', 'name' => 'Nguồn tin', 'url' => 'https://feed.example/rss',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 5,
        ]);
    }

    private function fakeFeed(): void
    {
        Http::fake(['feed.example/*' => Http::response(
            '<?xml version="1.0"?><rss version="2.0"><channel><item><title>Đầm linen</title>'
            .'<link>https://b/1</link><pubDate>'.date('r').'</pubDate><description>linen</description></item></channel></rss>',
            200,
        )]);
    }

    /** Không có nhịp tim (không cron) ⇒ KHÔNG được nói là tự động, và phải nói việc người dùng cần làm. */
    public function test_without_a_scheduler_heartbeat_it_does_not_promise_automatic_refresh(): void
    {
        $this->source();
        $this->fakeFeed();

        $evidence = app(WebSourceService::class)->evidence('all');

        $this->assertFalse($evidence['auto_refresh']['alive']);
        $this->assertStringContainsString('chưa bật lịch chạy nền', $evidence['auto_refresh']['label']);
        $this->assertStringContainsString('Cập nhật tin', $evidence['auto_refresh']['label']);

        // Câu chu kỳ nằm ở báo cáo nguồn của radar (chỗ giao diện đọc), không phải ở mỗi dòng trạng thái.
        $this->fakeFeed();
        $radar = app(DesignAgentService::class)->radar(null, 'all', false);
        $this->assertSame('Khi mở màn hình', collect($radar['sources'])->firstWhere('id', 'nguon-tin')['frequency']);
    }

    /** Có nhịp tim (cron đang chạy) ⇒ được nói "tự động mỗi 30 phút". */
    public function test_with_a_heartbeat_it_reports_automatic_refresh(): void
    {
        $this->source();
        $this->fakeFeed();
        Cache::put('studio:scheduler:heartbeat', now()->toISOString(), now()->addMinutes(30));

        $evidence = app(WebSourceService::class)->evidence('all');

        $this->assertTrue($evidence['auto_refresh']['alive']);
        $this->assertStringContainsString('30 phút', $evidence['auto_refresh']['label']);

        $radar = app(DesignAgentService::class)->radar(null, 'all', false);
        $this->assertSame('Tự động mỗi 30 phút', collect($radar['sources'])->firstWhere('id', 'nguon-tin')['frequency']);
    }

    /** Radar cũng phải dùng cùng một câu (không có chỗ nào hứa khác chỗ nào). */
    public function test_the_radar_source_report_uses_the_same_measured_claim(): void
    {
        $this->source();
        $this->fakeFeed();

        $radar = app(DesignAgentService::class)->radar(null, 'all', false);
        $report = collect($radar['sources'])->firstWhere('id', 'nguon-tin');

        $this->assertNotNull($report);
        $this->assertSame('Khi mở màn hình', $report['frequency']);
    }

    /** Lịch chạy nền phải có mặt trong bộ lịch (nhịp tim + đo tín hiệu + dọn dẹp). */
    public function test_the_schedule_declares_the_heartbeat_and_the_signal_jobs(): void
    {
        $schedule = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('studio-scheduler-heartbeat', $schedule);
        $this->assertStringContainsString('studio:market-signals', $schedule);
        $this->assertStringContainsString("Cache::put('studio:scheduler:heartbeat'", $schedule);
    }

    /** Giao diện đọc khối ĐO được, không viết lại câu "tự lấy tin mỗi 30 phút" bằng tay. */
    public function test_the_screen_reads_the_measured_label(): void
    {
        $view = (string) file_get_contents(resource_path('js/studio/components/DesignAgents.vue'));

        $this->assertStringContainsString('autoRefresh.label', $view);
        $this->assertStringNotContainsString('FabrikAI tự lấy tin mỗi 30 phút', $view,
            'Câu hứa viết cứng đã quay lại — phải đọc từ auto_refresh của máy chủ.');
    }
}
