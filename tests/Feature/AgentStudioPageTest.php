<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AGENT STUDIO LÀ MỘT TRANG — không còn là modal trong /studio (2026-09-25).
 *
 * Vì sao có file test này: "chuyển thành trang riêng" là loại thay đổi dễ QUAY LẠI NỬA VỜI —
 * thêm trang mới nhưng vẫn để modal cũ, hoặc thêm entry mới nhưng quên đăng ký vào vite/blade.
 * Khi đó có HAI bề mặt Agent Studio, sửa một bên là bên kia lệch, và bộ test quét giao diện
 * (designAgentsSource) chỉ đọc được một nửa số mảnh.
 *
 * Sáu bất biến khoá ở đây:
 *   (a) khách chưa đăng nhập bị đẩy về đăng nhập; tài khoản studio mở được trang;
 *   (b) vỏ blade mount ĐÚNG element gốc và nạp ĐÚNG entry riêng của trang;
 *   (c) chỉ có MỘT bề mặt: /studio không còn mount modal, và tệp modal cũ đã bị xoá;
 *   (d) bước đang mở ĐÁNH DẤU ĐƯỢC lên URL (?buoc=…) — đọc lúc vào và ghi khi đổi bước;
 *   (e) nút "Agent thiết kế" trên activity bar ĐIỀU HƯỚNG sang trang, không mở popup;
 *   (f) hướng dẫn thiết kế (docs/DESIGN_SYSTEM.md) nói về trang này VÀ các lớp nền của nó
 *       thật sự tồn tại trong app.css — tài liệu không được phép "mục rỗng".
 */
class AgentStudioPageTest extends TestCase
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

    private function src(string $rel): string
    {
        return (string) file_get_contents(base_path($rel));
    }

    public function test_the_page_is_wired_to_a_route_and_to_its_own_vite_entry(): void
    {
        $this->assertSame('/agent-studio', route('agent-studio.page', [], false));

        $blade = $this->src('resources/views/studio/agent-studio.blade.php');
        $this->assertStringContainsString('id="agent-studio-root"', $blade,
            'Vỏ blade phải có element gốc #agent-studio-root — thiếu thì app không mount được.');
        $this->assertStringContainsString("'resources/js/studio/agent-studio.js'", $blade,
            'Trang phải nạp entry RIÊNG của nó.');
        $this->assertStringNotContainsString("'resources/js/studio/main.js'", $blade,
            'Trang Agent Studio KHÔNG được nạp main.js — đó là cả xưởng thiết kế (canvas · dock · layers), gửi thừa cả một bundle.');
        $this->assertStringContainsString('window.__STUDIO_BOOT__', $blade,
            'Thiếu payload khởi động thì store không biết người dùng là ai (credit, tên, quyền).');
    }

    public function test_guest_is_sent_to_login_and_a_studio_user_gets_the_page(): void
    {
        $this->get('/agent-studio')->assertRedirect('/dang-nhap');

        $html = $this->actingAs($this->customer())->get('/agent-studio')->assertOk()->getContent();
        $this->assertStringContainsString('id="agent-studio-root"', $html);
    }

    public function test_there_is_only_one_agent_studio_surface_left(): void
    {
        // Modal cũ đã bị XOÁ khỏi đĩa, không chỉ bị bỏ không dùng.
        $this->assertFileDoesNotExist(resource_path('js/studio/components/DesignAgents.vue'),
            'Modal cũ vẫn còn trên đĩa — hai bề mặt Agent Studio là hai bản sao sẽ lệch nhau.');

        $studio = $this->src('resources/js/studio/StudioApp.vue');
        $this->assertStringNotContainsString('<DesignAgents', $studio,
            '/studio lại mount modal Agent Studio — nay nó là trang riêng /agent-studio.');
        $this->assertStringContainsString("'/agent-studio'", $studio,
            'Nút «Agent thiết kế» phải ĐIỀU HƯỚNG sang trang riêng.');
        $this->assertStringContainsString("id === 'stylist'", $studio,
            'Thiếu nhánh điều hướng cho mục stylist trên activity bar.');

        // [2026-09-26 · đợt 26] Màn hình canvas trống KHÔNG còn là lối vào Agent Studio — nó chỉ còn
        // ô mô tả tạo ảnh. Lối vào duy nhất là rail công cụ của Studio, đã khoá ngay trên (nhánh
        // id === 'stylist' phải điều hướng sang /agent-studio). Ghi lại ở đây để không ai thêm lối vào
        // thứ hai đi đường vòng (popup / state store) mà bỏ qua hợp đồng URL có bước.
        $empty = $this->src('resources/js/studio/components/CanvasEmptyState.vue');
        $this->assertStringNotContainsString("'/agent-studio'", $empty,
            'Canvas trống không còn mở Agent Studio — lối vào nằm ở rail công cụ của Studio.');
    }

    public function test_the_open_step_is_addressable_from_the_url(): void
    {
        $app = $this->src('resources/js/studio/AgentStudioApp.vue');

        $this->assertStringContainsString("const STEP_PARAM = 'buoc'", $app,
            'Thiếu tên tham số URL cho bước đang mở.');
        $this->assertStringContainsString('new URLSearchParams(window.location.search).get(STEP_PARAM)', $app,
            'Trang phải ĐỌC bước từ URL lúc vào — gửi link cho đồng nghiệp là mở đúng bước.');
        $this->assertStringContainsString('window.history.replaceState', $app,
            'Trang phải GHI bước lên URL khi đổi bước — nếu không thì F5 quay về đầu luồng.');

        // Bước trong URL phải là bước CÓ THẬT (nguồn duy nhất: STEPS của composable).
        $core = $this->src('resources/js/studio/composables/useAgentStudio.js');
        $this->assertMatchesRegularExpression(
            "/if \(STEPS\.some\(\(s\) => s\.id === wanted\)\) \{/",
            $app,
            'Bước đọc từ URL phải được kiểm tra thuộc STEPS trước khi áp — nhận bừa giá trị lạ là mở ra bước không tồn tại.'
        );
        foreach (["id: 'dna'", "id: 'radar'", "id: 'brief'", "id: 'canvas'"] as $step) {
            $this->assertStringContainsString($step, $core, 'Thiếu bước '.$step.' trong STEPS.');
        }

        // URL THẮNG PHIÊN: phiên nạp BẤT ĐỒNG BỘ về sau, nên nếu không khoá bước theo URL thì phiên ghi
        // đè bước của link — gửi link cho đồng nghiệp mà họ lại mở đúng chỗ cũ của chính họ.
        $this->assertStringContainsString('agent.lockStepToUrl();', $app,
            'Thiếu khoá bước theo URL — phiên làm việc sẽ ghi đè bước mà link trỏ tới.');
        $this->assertStringContainsString('stepLockedByUrl', $core, 'Lõi chưa có cờ khoá bước theo URL.');
        $this->assertStringContainsString(
            'session.step && !stepLockedByUrl.value',
            $core,
            'Nạp phiên vẫn ghi đè bước đọc từ URL — tham số trên link thành ra vô nghĩa.'
        );
    }

    /**
     * ĐI SANG CANVAS LÀ ĐIỀU HƯỚNG THẬT ⇒ prompt phải sống qua lần chuyển trang.
     *
     * Store là bộ nhớ TRONG TRANG: chỉ ghi vào store rồi nhảy sang /studio là mất sạch công vừa làm,
     * mà giao diện thì vẫn báo "Đã áp dụng". Bất biến: ghi bản BỀN bằng ĐÚNG khoá localStorage mà
     * ConceptCard dùng (không mở khoá lưu thứ hai), rồi /studio phải mở sẵn ô prompt. */
    public function test_apply_to_canvas_survives_the_navigation_to_studio(): void
    {
        $core = $this->src('resources/js/studio/composables/useAgentStudio.js');
        $this->assertStringContainsString('store.savePromptMemory();', $core,
            'Áp dụng vào Canvas mà không ghi bản bền thì chuyển trang là mất prompt.');
        $this->assertStringContainsString("window.location.href = '/?panel=concept&open=prompt';", $core,
            'Thiếu điều hướng sang Studio kèm tham số mở sẵn ô prompt.');
        $this->assertStringContainsString('if (!ok) return false;', $core,
            'Chỉ được rời trang khi việc áp dụng THÀNH CÔNG — prompt rỗng thì phải ở lại và nói lý do.');

        // Bản bền dùng ĐÚNG khoá cũ — mở khoá thứ hai là hai bản sao sẽ lệch nhau.
        $layer = $this->src('resources/js/studio/store/actions/layerTransform.js');
        $this->assertStringContainsString("localStorage.setItem('fabrikai.prompt-cfg'", $layer,
            'Bản bền của prompt phải nằm ở khoá fabrikai.prompt-cfg (khoá ConceptCard vẫn dùng).');

        // Và /studio phải ĐỌC tham số đó, nếu không thì người dùng sang trang rồi tự đi tìm ô nhập.
        $studio = $this->src('resources/js/studio/StudioApp.vue');
        $this->assertStringContainsString("params.get('open') === 'prompt'", $studio,
            '/studio chưa đọc tham số open=prompt nên tham số này là trang trí.');
    }

    /**
     * GÓI KHÔNG CÓ MODULE — nói thẳng, đừng để bốn bước chạy rồi mỗi lượt API trả một dòng lỗi.
     *
     * Lỗ hổng này do chính việc chuyển sang TRANG tạo ra: lối vào cũ nằm trong /studio, nơi activity
     * bar đã biết khoá mục theo gói; nay mở thẳng URL vẫn tới được, mà `moduleLocked()` trả `false`
     * khi "chưa biết" nên trang phải tự nạp quyền theo gói. */
    public function test_the_page_tells_the_truth_when_the_plan_lacks_the_module(): void
    {
        $app = $this->src('resources/js/studio/AgentStudioApp.vue');

        $this->assertStringContainsString("store.moduleLocked('stylist')", $app,
            'Trang chưa hỏi quyền theo gói — người không có gói sẽ thấy bốn bước toàn lỗi.');
        $this->assertStringContainsString('href="/bang-gia"', $app,
            'Màn hình "chưa có trong gói" phải có ĐƯỜNG nâng cấp, không chỉ một câu thông báo.');
        $this->assertStringContainsString('fetchGuiConfig()', $app,
            'Trang phải nạp cấu hình/quyền module của chính nó (mở thẳng URL không đi qua /studio).');

        // Quyền theo gói phải đọc từ MỘT chỗ dùng chung — bản sao thứ hai là cách hai trang lệch nhau.
        $this->assertStringContainsString('applyGuiConfig(store', $app);
        $this->assertStringContainsString("from './guiConfig.js'", $this->src('resources/js/studio/StudioApp.vue'),
            'StudioApp phải dùng chung guiConfig.js thay vì tự fetch /api/gui.');
    }

    public function test_the_page_is_built_from_the_shared_core_not_a_second_copy(): void
    {
        $app = $this->src('resources/js/studio/AgentStudioApp.vue');

        $this->assertStringContainsString("import { useAgentStudio } from './composables/useAgentStudio.js';", $app,
            'Trang phải dùng CHUNG lõi với 4 bước, không chép lại logic.');
        $this->assertStringContainsString('agent.provideAll(provide);', $app,
            'Thiếu provideAll() thì 4 bước (agents/*.vue) không nhận được bề mặt chúng inject().');

        // Lõi phải giữ nguyên nguồn dữ liệu cũ — chuyển sang trang KHÔNG được đổi API.
        $core = $this->src('resources/js/studio/composables/useAgentStudio.js');
        $this->assertStringContainsString("from '../shopPaste.js'", $core,
            'Lõi phải dùng module đọc bảng dán dùng chung (đã khoá bằng DebtFixesTest).');
        $this->assertStringContainsString('export function useAgentStudio()', $core);
    }

    public function test_the_guide_documents_the_page_and_its_foundation_really_exists(): void
    {
        $guide = $this->src('docs/DESIGN_SYSTEM.md');
        $css = $this->src('resources/css/app.css');

        $this->assertStringContainsString('/agent-studio', $guide,
            'Hướng dẫn thiết kế phải nói Agent Studio là một TRANG (người sau còn tìm modal cũ).');

        // Bốn thứ Material mà trang dựa vào: tầng nổi · lớp trạng thái · gợn nước · rail bước.
        // Tài liệu nhắc tên lớp nào thì lớp đó phải có thật trong app.css.
        foreach (['.elev-bar', '.state-layer', '.ripple-ink', '.nav-step'] as $class) {
            $this->assertStringContainsString($class, $guide, 'Hướng dẫn thiếu mô tả lớp '.$class.'.');
            $this->assertMatchesRegularExpression('/^\\s*'.preg_quote($class, '/').'\\s*(?:,[^{]*)?\\{/m', $css,
                'app.css thiếu định nghĩa '.$class.' — hướng dẫn đang nói về một lớp không tồn tại.');
        }

        // Gợn nước phải đọc TOKEN chuyển động, nếu không thì công tắc "giảm chuyển động" bỏ sót nó.
        $this->assertMatchesRegularExpression(
            '/\.ripple-ink\s*\{[^}]*animation:[^;]*var\(--motion-dur-slow\)/s',
            $css,
            'Gợn nước phải dùng token thời lượng, không viết số ms.'
        );
    }

    public function test_the_shipped_build_carries_the_page(): void
    {
        $manifestPath = base_path('public_html/build/manifest.json');
        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertArrayHasKey('resources/js/studio/agent-studio.js', $manifest,
            'Manifest thiếu entry của trang — chưa chạy npm run build (máy chủ không có node, asset phải được commit).');

        $js = (string) file_get_contents(base_path('public_html/build/'.$manifest['resources/js/studio/agent-studio.js']['file']));
        $this->assertStringContainsString('agent-studio-root', $js,
            'Bundle đã build không chứa element gốc của trang — build cũ hoặc sai entry.');
        $this->assertStringContainsString('ripple-host', $js,
            'Bundle thiếu lớp gợn nước — directive chưa được đăng ký trong agent-studio.js.');
    }
}
