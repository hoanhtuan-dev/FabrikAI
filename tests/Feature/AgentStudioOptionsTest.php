<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * QUYỀN TUỲ CHỌN CỦA NGƯỜI DÙNG trong Agent Studio (2026-09-25).
 *
 * Bốn thứ trước đây THUẬT TOÁN quyết và người dùng chỉ đọc được:
 *   (a) TỔNG SỐ SKU — cơ cấu danh mục do máy chủ tính, không có ô nào để đổi;
 *   (b) BẢNG SIZE — chỉ có 3 preset cứng, không thêm/bớt size nào;
 *   (c) BẢNG MÀU — do máy chủ chọn;
 *   (d) BẢNG MOOD — lưới màu để NHÌN: sửa ô không đổi được prompt ảnh nào.
 *
 * Bất biến khoá ở đây:
 *   1. Tổng SKU người dùng chọn phải THẮNG, và cơ cấu danh mục giữ nguyên hình dạng (chia theo tỉ lệ
 *      + làm tròn phần dư) — không phải ghi đè một nhóm rồi để tổng lệch.
 *   2. Bảng size người dùng đặt đi thẳng vào brief, ĐÚNG THỨ TỰ họ nhập, và là thứ kế hoạch sản xuất đọc.
 *   3. Bảng màu + bảng mood người dùng sửa đi vào PROMPT (đây là phần làm bảng mood "hoạt động thật").
 *   4. Mỗi MẪU một prompt khác nhau — 12 mẫu dùng chung một prompt là 12 ảnh giống nhau.
 *   5. Không có model nào chạy thì mẫu VẪN có prompt dùng được ngay (tất định), không chặn giữa việc.
 */
class AgentStudioOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function brief(array $extra = []): array
    {
        return $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', array_merge([
                'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở',
                'region' => 'all',
                'ai' => false,
            ], $extra))
            ->assertOk()
            ->json();
    }

    /**
     * [LỖI THẬT — 2026-09-21] "CẬP NHẬT SỐ LIỆU" KHÁC "AI ĐANG TẮT".
     *
     * Người dùng bấm «Cập nhật số liệu» ở bước Định hướng (chạy tất định cho tức thì). Trước đây lượt
     * đó bị máy chủ đóng dấu `ai_disabled` — y như thể họ vừa tắt công tắc Suy luận AI. Dấu ấy nằm trong
     * bản brief, theo bản brief vào phiên làm việc, nên mở lại trang vẫn thấy câu "Brief này được dựng
     * khi Suy luận AI đang TẮT" trong khi công tắc đang BẬT.
     */
    public function test_a_user_requested_number_refresh_is_not_reported_as_ai_being_off(): void
    {
        // Cần có model trong nhóm công việc thì nhánh `ai_disabled` mới tồn tại (không có model nào thì
        // lý do luôn là no_model_key, và bài này không phân biệt được gì).
        StudioModel::create([
            'group' => 'prompt', 'name' => 'DeepSeek', 'provider' => 'deepseek',
            'model_id' => 'deepseek-chat', 'api_key_ref' => 'deepseek', 'priority' => 9, 'enabled' => true,
        ]);
        StudioApiKey::create([
            'provider' => 'deepseek', 'label' => 'deepseek', 'value' => 'sk-test',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', 'deepseek:deepseek-chat');

        $base = ['prompt' => 'Bộ sưu tập linen pastel cho nữ công sở', 'region' => 'all'];

        // (a) Người dùng TẮT công tắc AI ⇒ lý do đúng là "đang tắt".
        $off = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', $base + ['ai' => false])->assertOk();
        $this->assertSame('ai_disabled', $off->json('model.reason'));

        // (b) Người dùng BẤM cập nhật số liệu ⇒ phải là lý do RIÊNG, không phải "AI đang tắt".
        $refresh = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', $base + ['ai' => false, 'refresh' => 1])->assertOk();
        $this->assertSame('rules_refresh', $refresh->json('model.reason'),
            'Thao tác cập nhật số liệu của người dùng bị ghi thành "AI đang tắt" — câu sai ấy sẽ theo bản brief vào phiên làm việc.');
        $this->assertSame('rule', $refresh->json('model.mode'));
    }

    /** Không cấu hình model nào thì lý do vẫn phải là "chưa cấu hình", dù có cờ refresh hay không. */
    public function test_the_refresh_flag_never_masks_a_missing_model(): void
    {
        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', [
                'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở', 'region' => 'all',
                'ai' => false, 'refresh' => 1,
            ])->assertOk();

        $this->assertSame('no_model_key', $response->json('model.reason'));
    }

// ── GIAO DIỆN: băng cảnh báo KHÔNG được sáng lại từ một dữ kiện lịch sử ───────────
    //
    // Ba bài dưới đây khoá phần GIAO DIỆN của cùng lỗi vừa khoá ở trên. Repo không có runner JS
    // (package.json chỉ có build/dev) nên bất biến phía client được khoá bằng cách đọc thẳng mã nguồn —
    // đúng tiền lệ của DesignSystemTest và AgentStudioPageTest.

    /** Đọc mã nguồn giao diện, tính từ gốc dự án. */
    private function src(string $rel): string
    {
        $path = base_path($rel);
        $this->assertFileExists($path, 'Thiếu tệp giao diện '.$rel.' — bài kiểm tra này đang trỏ sai chỗ.');

        return (string) file_get_contents($path);
    }

    /**
     * BĂNG CẢNH BÁO CHỈ ĐƯỢC DỰNG TỪ SỰ CỐ CỦA LƯỢT VỪA RỒI.
     *
     * Đây là chỗ bản trước sai và là lý do người dùng báo "dai dẳng": băng được quyết bởi một cờ nằm
     * TRONG BẢN BRIEF, mà bản brief thì theo phiên làm việc qua mọi lần mở lại trang. Lý do `ai_disabled`
     * — vốn không phân biệt được lượt "Cập nhật số liệu" do người dùng bấm với lượt AI thật sự bị tắt —
     * nằm trong tệp phiên, nên cứ nạp lại là băng sáng lại dù công tắc đang BẬT.
     */
    public function test_the_ai_alarm_is_driven_by_real_failures_only(): void
    {
        $core = $this->src('resources/js/studio/composables/useAgentStudio.js');

        $this->assertMatchesRegularExpression('/const MODEL_ALARM_REASONS = \\[([^\\]]*)\\];/', $core,
            'Lõi chưa có danh sách lý do đáng dựng băng cảnh báo.');
        preg_match('/const MODEL_ALARM_REASONS = \\[([^\\]]*)\\];/', $core, $found);
        $list = $found[1];

        foreach (['no_model_key', 'model_error', 'invalid_output'] as $reason) {
            $this->assertStringContainsString($reason, $list, 'Thiếu sự cố thật '.$reason.' trong danh sách cảnh báo.');
        }
        foreach (['ai_disabled', 'rules_refresh'] as $never) {
            $this->assertStringNotContainsString($never, $list,
                'Lý do '.$never.' không phải sự cố của lượt vừa rồi, không được dựng băng cảnh báo: nó nằm trong '
                .'bản brief đã lưu nên băng sẽ sáng lại ở mọi lần mở lại phiên.');
        }

        $this->assertStringContainsString(
            'const modelNeedsAttention = computed(() => MODEL_ALARM_REASONS.includes(activeModel.value?.reason));',
            $core, 'Cờ cảnh báo phải hỏi đúng danh sách lý do trên.');
        $this->assertStringContainsString('v-if="!planLocked && modelNeedsAttention"',
            $this->src('resources/js/studio/AgentStudioApp.vue'),
            'Băng ở đầu trang phải do chính cờ đó quyết.');
    }

    /**
     * CÁI GÌ ĐÃ LÀ SỰ LỰA CHỌN THÌ KHÔNG BÁO LỆCH — cái gì lệch thật thì phải báo.
     *
     * Lượt cập nhật số liệu chạy tất định là ĐÚNG Ý người dùng, nên băng "brief lệch công tắc" phải miễn
     * trừ nó; còn hai câu chữ thì phải nói đúng nguyên nhân, không nói trạng thái công tắc.
     */
    public function test_the_interface_names_a_user_requested_refresh_instead_of_blaming_the_switch(): void
    {
        $core = $this->src('resources/js/studio/composables/useAgentStudio.js');

        $this->assertStringContainsString(
            "if (model?.reason === 'rules_refresh') return false;", $core,
            'Băng "lệch công tắc" chưa miễn trừ lượt cập nhật số liệu — nó sẽ dính mãi mà không có việc gì để làm.');
        $this->assertStringContainsString('rules_refresh:', $core, 'Thiếu nhãn cho lý do rules_refresh.');
        $this->assertStringContainsString('Số liệu vừa được cập nhật bằng bộ quy tắc', $core);
        $this->assertStringContainsString('không gọi AI', $core, 'Nhãn phải nói rõ lượt đó không gọi AI.');
    }

    /**
     * ĐỔI SỐ MÀ KHÔNG MẤT CHỮ.
     *
     * Lượt tất định trả về bản brief không có phần chữ, nên nếu cứ thay thẳng thì người dùng chỉ định đổi
     * số mã hàng là mất luôn brief/prompt do AI viết. Giao diện phải nói với máy chủ rằng đây là lượt
     * "cập nhật số liệu" và phải giữ chữ; kho dữ liệu phải thật sự gộp.
     */
    public function test_a_deterministic_refresh_keeps_the_ai_written_text(): void
    {
        $core = $this->src('resources/js/studio/composables/useAgentStudio.js');
        $store = $this->src('resources/js/studio/store/actions/agentStudio.js');

        $this->assertStringContainsString('if (opts.ai === false) payload.refresh = 1;', $core,
            'Lượt tất định chưa nói cho máy chủ biết đây là "cập nhật số liệu".');
        $this->assertStringContainsString('keepText: opts.ai === false,', $core,
            'Lượt tất định chưa xin giữ phần chữ của bản trước.');
        $this->assertStringContainsString(
            'this.collectionBrief = opts.keepText ? keepAiText(data, this.collectionBrief) : (data || null);',
            $store, 'Kho dữ liệu chưa gộp chữ cũ vào bản mới.');

        foreach (['brief', 'prompt_vi', 'prompt_en', 'brand_narrative', 'next_steps', 'canvas'] as $key) {
            $this->assertStringContainsString("'".$key."'", $store, 'Phần chữ '.$key.' chưa được giữ lại.');
        }
        // Chỉ giữ chữ khi bản CŨ thật sự do AI viết — bản cũ cũng tất định thì không có gì để giữ.
        $this->assertStringContainsString("if ((previous.model && previous.model.mode) !== 'ai') return fresh;", $store);
        // Con số thì vẫn phải của bản MỚI, và phải nói đúng phần nào do ai viết.
        $this->assertStringContainsString('merged.ai_applied = previous.ai_applied;', $store);
        // Câu "phần chữ giữ nguyên, con số vừa tính lại" phải HIỆN RA được: nó nằm trong model.note và
        // chỉ có chip đọc. Không có nhánh này thì nó là dữ liệu chết, còn chip nói "Có suy luận AI" —
        // đúng về phần chữ nhưng giấu mất việc người dùng vừa bấm.
        $this->assertStringContainsString('if (m.note) return m.note;', $core,
            'Câu mô tả lượt cập nhật số liệu đã tính ra nhưng không được hiển thị ở đâu.');
    }

/**
     * DỰNG LẠI BRIEF THÌ PHẢI GHI PHIÊN NGAY — NẾU KHÔNG THÌ F5 LÀ MẤT.
     *
     * Đo được trên trình duyệt thật: bấm «Cập nhật số liệu», biểu đồ hiện 18 mã, tải lại trang về 12 mã,
     * và nút lại mời bấm đúng việc vừa bấm. Nguyên nhân: bộ theo dõi ghi phiên theo dõi prompt/bảng
     * SKU/size/mood/mẫu nhưng KHÔNG theo dõi bản brief, mà createBrief lại không tự ghi gì.
     */
    public function test_a_rebuilt_brief_is_written_to_the_session_at_once(): void
    {
        $core = $this->src('resources/js/studio/composables/useAgentStudio.js');

        $this->assertStringContainsString('await saveSession();', $core,
            'Dựng lại brief xong phải ghi phiên NGAY (nhịp gộp 1,5 giây không cứu được nếu người dùng đóng tab).');
        $this->assertMatchesRegularExpression(
            '/collection,\s*\], \(\) => \{ saveAgentDraft\(\); scheduleSessionSave\(\); \}, \{ deep: true \}\)/',
            $core,
            'Bản brief phải nằm trong danh sách trạng thái được ghi vào phiên — đây là bất biến, không phải đường đi.');
    }

    /** Lượt cập nhật HỎNG không được xoá bản brief người dùng đang xem. */
    public function test_a_failed_refresh_does_not_wipe_the_brief_on_screen(): void
    {
        $store = $this->src('resources/js/studio/store/actions/agentStudio.js');
        $start = strpos($store, 'async createCollectionBrief(payload');
        $this->assertNotFalse($start, 'Không tìm thấy createCollectionBrief trong kho dữ liệu.');
        preg_match('/catch \(error\) \{\s*(.+?)finally/s', substr($store, $start), $caught);

        $this->assertNotEmpty($caught, 'Không đọc được nhánh lỗi của createCollectionBrief.');
        $this->assertStringNotContainsString('collectionBrief = null', $caught[1],
            'Lỗi mạng mà xoá kết quả cũ thì người dùng mất luôn bản brief đang xem — lỗi đã được báo bằng toast rồi.');
    }

    // ── (a) TỔNG SKU ────────────────────────────────────────────────────────────────

    public function test_the_owner_can_choose_the_total_sku_count_and_the_mix_keeps_its_shape(): void
    {
        $auto = $this->brief();
        $chosen = $this->brief(['sku_total' => 18]);

        $this->assertSame('system', $auto['structure']['total_skus_source']);
        $this->assertSame('owner', $chosen['structure']['total_skus_source']);

        // Tổng ĐÚNG BẰNG số đã chọn, và bằng tổng số mã của từng nhóm — hai con số không được lệch.
        $this->assertSame(18, $chosen['structure']['total_skus']);
        $this->assertSame(18, array_sum(array_column($chosen['structure']['categories'], 'count')));

        // Hình dạng cơ cấu giữ nguyên: nhóm nào nhiều mã nhất vẫn nhiều nhất.
        $before = collect($auto['structure']['categories'])->sortByDesc('count')->pluck('category')->all();
        $after = collect($chosen['structure']['categories'])->sortByDesc('count')->pluck('category')->all();
        $this->assertSame($before, $after, 'Đổi tổng SKU mà đảo thứ tự nhóm là đổi cả cơ cấu, không chỉ đổi quy mô.');

        // Không nhóm nào bị bỏ rơi về 0 mã — lệnh cắt thiếu hẳn một nhóm là lỗi sản xuất.
        foreach ($chosen['structure']['categories'] as $row) {
            $this->assertGreaterThan(0, (int) $row['count'], 'Nhóm '.$row['category'].' bị chia về 0 mã.');
        }
    }

    public function test_the_chosen_total_survives_the_plan(): void
    {
        $plan = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/plan', [
                'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở',
                'region' => 'all',
                'sku_total' => 9,
                'assumptions' => ['units_per_sku' => 10],
            ])
            ->assertOk();

        // Lệnh cắt phải cộng ra ĐÚNG 9 mã × 10 cái — con số tiền phải bám ô người dùng vừa chọn.
        $lines = $plan->json('plan.cut_lines');
        $this->assertNotEmpty($lines);
        $this->assertSame(90, array_sum(array_column($lines, 'qty')));
    }

    // ── (b) BẢNG SIZE ───────────────────────────────────────────────────────────────

    public function test_the_owner_can_set_a_full_size_chart_and_the_order_is_kept(): void
    {
        $brief = $this->brief(['size_distribution' => ['XXS' => 4, 'XS' => 8, 'S' => 20, 'M' => 30, 'L' => 24, 'XL' => 14]]);

        $sizes = array_column($brief['size_distribution'], 'size');
        $this->assertSame(['XXS', 'XS', 'S', 'M', 'L', 'XL'], $sizes, 'Bảng size phải theo đúng thứ tự người dùng nhập (chuẩn XXS→XL).');

        $bySize = collect($brief['size_distribution'])->keyBy('size');
        $this->assertSame(30, $bySize['M']['count']);
        $this->assertSame(100, array_sum(array_column($brief['size_distribution'], 'share')));

        // Bỏ size nào thì size đó KHÔNG được tự quay lại (hệ thống không được thêm lại size đã bỏ).
        $this->assertFalse($bySize->has('XXL'));
    }

    public function test_a_size_only_chart_replaces_the_builtin_presets_entirely(): void
    {
        $brief = $this->brief(['size_distribution' => ['Freesize' => 50, 'M' => 50]]);

        $this->assertCount(2, $brief['size_distribution']);
        // Mã size được CHUẨN HOÁ HOA (đúng như bảng hệ số size của xưởng: XS/S/M/L/XL) — size lạ xếp
        // SAU các size chuẩn, và chỉ có đúng những size người dùng đặt.
        $this->assertSame(['M', 'FREESIZE'], array_column($brief['size_distribution'], 'size'));
    }

    // ── (c)+(d) BẢNG MÀU & BẢNG MOOD ĐI VÀO PROMPT ──────────────────────────────────

    public function test_the_owner_palette_and_moodboard_reach_the_image_prompt(): void
    {
        $brief = $this->brief([
            'palette' => [
                ['name' => 'Xanh rêu', 'hex' => '#2F4F3E', 'role' => 'Chủ đạo'],
                ['name' => 'Kem ngà', 'hex' => '#F3ECDD', 'role' => 'Nền'],
            ],
            'moodboard' => [
                ['id' => 'm1', 'label' => 'Bề mặt', 'caption' => 'linen thô, nhăn tự nhiên', 'color' => '#2F4F3E'],
                ['id' => 'm2', 'label' => 'Dáng', 'caption' => 'suông rộng, vai mềm', 'color' => '#F3ECDD'],
            ],
        ]);

        // Bảng màu + bảng mood của NGƯỜI DÙNG phải là thứ được echo về, không phải bản hệ thống tự nghĩ.
        $this->assertSame(['#2F4F3E', '#F3ECDD'], array_column($brief['palette'], 'hex'));
        $this->assertSame(['m1', 'm2'], array_column($brief['moodboard']['items'], 'id'));
        $this->assertSame('owner', $brief['moodboard']['items'][0]['source']);

        // VÀ đi vào prompt ảnh — đây là điều làm bảng mood "hoạt động thật" thay vì chỉ để nhìn.
        $this->assertStringContainsString('linen thô, nhăn tự nhiên', $brief['prompt_vi']);
        $this->assertStringContainsString('suông rộng, vai mềm', $brief['prompt_vi']);
        $this->assertStringContainsString('linen thô, nhăn tự nhiên', $brief['prompt_en']);
    }

    public function test_an_empty_owner_moodboard_falls_back_to_the_generated_one(): void
    {
        $brief = $this->brief(['moodboard' => []]);

        $this->assertSame(24, $brief['moodboard']['count'], 'Không đặt gì thì vẫn phải có bảng mood của hệ thống.');
    }

    // ── (e) PROMPT CHO TỪNG MẪU ─────────────────────────────────────────────────────

    public function test_each_sample_gets_its_own_prompt_without_any_model(): void
    {
        $base = [
            'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở',
            'region' => 'all',
            'ai' => false,
            'moodboard' => [
                ['id' => 'm1', 'label' => 'Bề mặt', 'caption' => 'linen thô', 'color' => '#2F4F3E'],
            ],
        ];

        $one = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', $base + [
                'sample' => ['id' => 'sku-1', 'name' => 'Áo linen tay dài', 'category' => 'Áo / blouse', 'size' => 'M', 'index' => 1, 'total' => 12],
            ])->assertOk();

        $two = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', $base + [
                'sample' => ['id' => 'sku-2', 'name' => 'Quần ống rộng', 'category' => 'Quần', 'size' => 'L', 'index' => 2, 'total' => 12],
            ])->assertOk();

        // Không có model nào chạy ⇒ vẫn phải có prompt dùng được ngay, và nói THẬT là bản tất định.
        $one->assertJsonPath('model.mode', 'rule');
        $this->assertNotSame('', $one->json('prompt_vi'));
        $this->assertNotSame('', $one->json('prompt_en'));

        // 12 mẫu dùng chung một prompt là 12 ảnh giống nhau — mỗi mẫu phải khác.
        $this->assertNotSame($one->json('prompt_vi'), $two->json('prompt_vi'));
        $this->assertNotSame($one->json('photo_context'), $two->json('photo_context'),
            'Bối cảnh chụp phải luân phiên, nếu không lookbook chỉ có một kiểu ảnh.');

        // Nội dung phải bám ĐÚNG mẫu và ĐÚNG bảng mood người dùng đặt.
        $this->assertStringContainsString('Áo linen tay dài', $one->json('prompt_vi'));
        $this->assertStringContainsString('size M', $one->json('prompt_vi'));
        $this->assertStringContainsString('linen thô', $one->json('prompt_vi'));
        $this->assertStringContainsString('Quần ống rộng', $two->json('prompt_vi'));
    }

    public function test_the_sample_endpoint_rejects_broken_input(): void
    {
        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', ['prompt' => 'Bộ sưu tập linen'])
            ->assertStatus(422);

        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', [
                'prompt' => 'Bộ sưu tập linen',
                'palette' => [['name' => 'Sai', 'hex' => 'khong-phai-ma-mau']],
                'sample' => ['id' => 'sku-1', 'index' => 1],
            ])
            ->assertStatus(422);
    }
}
