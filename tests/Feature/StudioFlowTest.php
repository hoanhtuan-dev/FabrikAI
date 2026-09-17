<?php

namespace Tests\Feature;

use App\Models\FacePreset;
use App\Models\Generation;
use App\Models\PosePreset;
use App\Models\Preset;
use App\Models\StudioOutfitSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Studio flow tests — tách nhóm test_studio_* ra khỏi file test dùng chung của app cũ
 * (storefront đã bị bỏ ở FabrikAI nên phần shop/cart/checkout không còn port được).
 *
 * URL đã đổi tiền tố theo FabrikAI: /studio/* -> /api/*, SPA /studio -> /.
 * Phần assert vào TRANG HTML cũ (/studio/pattern, /studio/tryon, /studio/api) bị bỏ vì
 * các trang đó không còn tồn tại; chỉ giữ assert vào API/endpoint thật.
 */
class StudioFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    public function test_studio_spa_shell_is_public_but_api_requires_auth(): void
    {
        // SPA shell công khai (Vue tự gọi /api/boot); API vẫn yêu cầu đăng nhập.
        $this->get('/')->assertOk()->assertSee('studio-root');
        // FabrikAI render JSON cho mọi request /api/* (bootstrap/app.php shouldRenderJsonWhen)
        // nên guest nhận 401 thay vì redirect về trang đăng nhập.
        $this->getJson('/api/latest')->assertStatus(401);
    }

    /**
     * [CẬP NHẬT KỲ VỌNG 2026-09-17 · Đợt 0.1 — quyết định Q1: "mở studio cho customer quyền hẹp"]
     *
     * Bất biến MỚI (thay cho "studio là admin-only"):
     *   - khách CHƯA đăng nhập    → 401
     *   - customer đang hoạt động → VÀO ĐƯỢC phần xưởng (/api/latest)
     *   - customer                → VẪN 403 ở phần quản trị
     *   - tài khoản bị khoá (is_active=false) → 403 dù role là admin
     */
    public function test_studio_is_open_to_active_users_but_admin_area_is_not(): void
    {
        $this->get('/')->assertOk()->assertSee('studio-root');

        $customer = User::where('email', 'user@fabrikai.shop')->first();

        // Xưởng: customer dùng được.
        $this->actingAs($customer)->getJson('/api/latest')->assertOk();
        // Quản trị: customer vẫn bị chặn.
        $this->actingAs($customer)->getJson('/api/settings-vue/data')->assertForbidden();
        $this->actingAs($customer)->postJson('/api/models', ['name' => 'x'])->assertForbidden();

        // Bị khoá thì mất quyền vào xưởng, kể cả admin.
        $blocked = $this->admin();
        $blocked->forceFill(['is_active' => false])->save();
        $this->actingAs($blocked->fresh())->getJson('/api/latest')->assertForbidden();

        $this->actingAs($this->admin())->get('/')->assertOk();
    }

    public function test_root_serves_the_spa_shell(): void
    {
        // SPA gốc; dữ liệu thư viện vẫn admin-only qua /api/library/*.
        $this->actingAs($this->admin())->get('/')->assertOk()->assertSee('studio-root');
    }

    public function test_studio_generate_image_and_video(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        // 2D image (cost 1).
        $image = $this->postJson('/api/generate', [
            'prompt' => 'photo of a blue evening gown',
            'history_id' => null,
        ])->assertOk()->json();

        $gen = Generation::find($image['items'][0]['generation_id']);
        $this->assertSame('pending', $gen->status);
        $this->getJson('/api/generations/'.$gen->id)->assertOk();
        $this->assertSame('completed', $gen->fresh()->status);
        $this->assertSame('image', $gen->type);
        $this->assertNotNull($gen->fresh()->media_url);
        $this->assertSame(999, $user->fresh()->credits_balance);
        // Creation metadata recorded (real elapsed time + info used).
        $this->assertNotNull($gen->fresh()->elapsed_ms);
        $this->assertSame('image', ($gen->fresh()->meta['type'] ?? null));

        // Video (cost 10) — chạy ở chế độ DEMO (không cấu hình khoá provider).
        //
        // Quy ước của module: không có khoá = DEMO MODE chạy được end-to-end, cả ảnh
        // (ImageAIService dùng samples/*.jpg) lẫn video (VideoAIService dùng samples/studio-catwalk.mp4).
        // Đây là contract gốc của module (giữ nguyên từ commit đầu tiên) nên test phải giữ 'completed'.
        //
        // N9 — lỗi THẬT đã vá: file demo video CHƯA TỪNG tồn tại trong repo (không có trong git
        // history) nên generation báo 'completed' với media_url 404. Nay đã ship file demo thật
        // (4,5s · 640×640 · 168 KB) + assert bên dưới khoá chặt để nó không thể mất lại.
        set_setting('api_qwen_key', '');
        set_setting('api_qwen_edit_key', '');
        \App\Models\StudioApiKey::query()->delete();

        $this->assertFileExists(
            public_path('samples/studio-catwalk.mp4'),
            'File demo video phải tồn tại — thiếu nó thì generation báo completed với media_url 404 (lỗi N9).'
        );

        $video = $this->postJson('/api/video', [
            'prompt' => 'catwalk video of a blue evening gown',
            'base_image' => $gen->media_url,
            'camera' => '360 degree rotating camera shot',
            'history_id' => null,
        ])->assertOk()->json();

        $vgen = Generation::find($video['generation_id']);
        $this->assertSame('pending', $vgen->status);
        $this->getJson('/api/generations/'.$vgen->id)->assertOk();

        $settled = $vgen->fresh();
        $this->assertSame('completed', $settled->status);
        $this->assertSame('video', $settled->type);
        $this->assertNotNull($settled->media_url);
        // media_url phải trỏ tới file THẬT SỰ tồn tại (chính là lỗi N9 trước đây).
        $this->assertFileExists(public_path(ltrim((string) $settled->media_url, '/')));
        $this->assertNotNull($settled->elapsed_ms);
        $this->assertSame('video', ($settled->meta['type'] ?? null));
        $this->assertSame(989, $user->fresh()->credits_balance);
    }

    public function test_studio_refgen_tryon_creates_generation_with_garment_prompt(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // Chip "Thử đồ" trong card Ảnh mới từ ảnh mẫu: gửi 1 ảnh trang phục + tryon=true ->
        // 1 generation mode='refgen' (model sinh ảnh, KHÔNG edit), prompt chứa WEARING THE EXACT GARMENT.
        $fixtureRel = 'studio/test-garment-shopflow.png';
        ob_start();
        imagepng(imagecreatetruecolor(32, 32));
        Storage::disk('public')->put($fixtureRel, (string) ob_get_clean());
        try {
            $r = $this->postJson('/api/refgen', [
                'image' => '/storage/'.$fixtureRel,
                'prompt' => 'mặc trang phục lên người mẫu, pose đứng',
                'variants' => 1,
                'tryon' => true,
                'body_height' => 8,
            ])->assertOk();

            $items = $r->json('items');
            $this->assertCount(1, $items);
            $this->assertTrue((bool) $r->json('tryon'));
            $gen = Generation::find($items[0]['generation_id']);
            $this->assertNotNull($gen);
            $this->assertSame('refgen', $gen->meta['mode'] ?? null);
            $this->assertStringContainsString('WEARING THE EXACT GARMENT', (string) $gen->prompt);
            $this->assertStringContainsString('IDENTICAL garment', (string) $gen->prompt);
            $this->assertStringContainsString('tall statuesque model', (string) $gen->prompt);
            $this->assertStringNotContainsString('hair color', (string) $gen->prompt);
            $this->assertSame(1, $gen->credits_cost);
            $this->getJson('/api/generations/'.$gen->id)->assertOk();

            // FacePreset không có ảnh -> fallback về description trong DB.
            $fp = FacePreset::create([
                'name' => 'Test Face', 'description' => 'young woman with round face and wispy bangs', 'ethnicity' => 'Vietnamese', 'image' => null, 'sort' => 0, 'enabled' => true,
            ]);
            $f = $this->postJson('/api/refgen', [
                'image' => '/storage/'.$fixtureRel,
                'prompt' => 'pose đứng tự nhiên',
                'variants' => 1,
                'tryon' => true,
                'face_model_id' => 'fp'.$fp->id,
            ])->assertOk();
            $fgen = Generation::find($f->json('items.0.generation_id'));
            $this->assertStringContainsString('Model face: young woman with round face and wispy bangs', (string) $fgen->prompt);
            $this->assertSame('fp'.$fp->id, $f->json('face_model_id'));

            // PosePreset không có ảnh -> fallback về skeleton (description).
            $pp = PosePreset::create([
                'name' => 'Test Pose', 'description' => 'standing with one hand on hip, full body head to toe', 'image' => null, 'sort' => 0, 'enabled' => true,
            ]);
            $pf = $this->postJson('/api/refgen', [
                'image' => '/storage/'.$fixtureRel,
                'prompt' => 'mặc trang phục lên người mẫu',
                'variants' => 1,
                'tryon' => true,
                'pose_id' => 'pp'.$pp->id,
            ])->assertOk();
            $pgen = Generation::find($pf->json('items.0.generation_id'));
            $this->assertStringContainsString('Model pose: standing with one hand on hip, full body head to toe', (string) $pgen->prompt);

            // Tryon + Nền Studio: background_prompt được chuẩn hóa rồi chèn vào prompt tryon.
            $tb = $this->postJson('/api/refgen', [
                'image' => '/storage/'.$fixtureRel,
                'prompt' => 'mặc trang phục lên người mẫu',
                'variants' => 1,
                'tryon' => true,
                'background_prompt' => 'keep the subject unchanged; replace the background with a pure-white seamless studio backdrop, even soft diffused lighting',
            ])->assertOk();
            $tbgen = Generation::find($tb->json('items.0.generation_id'));
            $this->assertStringContainsString('Background: a pure-white seamless studio backdrop, even soft diffused lighting', (string) $tbgen->prompt);
            $this->assertStringNotContainsString('keep the subject unchanged', (string) $tbgen->prompt);

            // Không gửi tryon -> refgen thường.
            $c = $this->postJson('/api/refgen', [
                'image' => '/storage/'.$fixtureRel,
                'prompt' => '',
                'variants' => 1,
                'background_prompt' => 'replace the background with a pure-white seamless studio backdrop',
                'angle_prompt' => 'shoot from a straight-on front view, eye-level camera',
            ])->assertOk();
            $this->assertNull($c->json('tryon'));
            $cg = Generation::find($c->json('items.0.generation_id'));
            $this->assertSame('refgen', $cg->meta['mode'] ?? null);
            $this->assertStringContainsString('Create a brand-new image based on the provided reference image', (string) $cg->prompt);
            $this->assertStringContainsString('Background: replace the background with a pure-white seamless studio backdrop', (string) $cg->prompt);
            $this->assertStringContainsString('Camera angle: shoot from a straight-on front view, eye-level camera', (string) $cg->prompt);
            $this->assertStringNotContainsString('WEARING THE EXACT GARMENT', (string) $cg->prompt);
        } finally {
            Storage::disk('public')->delete($fixtureRel);
        }
    }

    public function test_studio_generation_resolution_ratio_duration(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $img = $this->postJson('/api/generate', ['prompt' => 'a silk gown', 'resolution' => '2K', 'ratio' => '9:16'])->assertOk();
        $gen = Generation::find($img->json('items.0.generation_id'));
        $this->assertSame('2K', $gen->resolution);
        $this->assertSame('9:16', $gen->ratio);

        $admin->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'src',
            'media_url' => '/storage/studio/test.jpg', 'credits_cost' => 1,
        ]);
        $vid = $this->postJson('/api/video', [
            'prompt' => 'walk', 'base_image' => '/storage/studio/test.jpg',
            'camera' => 'runway', 'resolution' => '1080', 'duration' => '15',
        ])->assertOk();
        $vg = Generation::find($vid->json('generation_id'));
        $this->assertSame('1080', $vg->resolution);
        $this->assertSame('15', $vg->duration);
    }

    public function test_studio_latest_endpoint(): void
    {
        $this->actingAs($this->admin())->getJson('/api/latest')->assertOk()->assertJsonStructure(['items' => []]);
    }

    // (test_studio_pattern_and_tryon_endpoints đã bị GỠ 2026-09-17: cả 2 endpoint /api/pattern và
    //  /api/tryon bị xoá cùng card Pattern Maker và Try-On. Pattern nay làm qua "Tạo ảnh"/"Ghép ảnh";
    //  try-on còn đường chính thức trong Fitting Room — chế độ "Thử đồ" của RefImageCard → /api/refgen.)

    public function test_studio_compose_rejects_tryon_mode(): void
    {
        $this->actingAs($this->admin());

        // Chế độ "Thử đồ ảo" (mode='tryon') đã bị xóa khỏi card Ghép ảnh -> backend từ chối 422.
        $this->postJson('/api/compose', [
            'images' => ['/storage/studio/a.jpg', '/storage/studio/b.jpg'],
            'prompt' => 'mặc trang phục lên người mẫu',
            'mode' => 'tryon',
        ])->assertStatus(422);

        $c = $this->postJson('/api/compose', [
            'images' => ['/storage/studio/a.jpg', '/storage/studio/b.jpg'],
            'prompt' => 'giữ nền, đặt cô gái vào studio',
            'mode' => 'compose',
            'variants' => 2,
        ])->assertOk();
        $this->assertCount(2, $c->json('items'));

        // Regression: chọn 3 biến thể phải tạo ĐỦ 3 generation (không chỉ 2).
        $c3 = $this->postJson('/api/compose', [
            'images' => ['/storage/studio/a.jpg', '/storage/studio/b.jpg'],
            'prompt' => 'giữ nền, đặt cô gái vào studio',
            'mode' => 'compose',
            'variants' => 3,
        ])->assertOk();
        $this->assertCount(3, $c3->json('items'));
    }

    public function test_studio_outfit_settings_save_and_reload(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->getJson('/api/outfit-settings')->assertOk()
            ->assertJson(['style' => '', 'ornament_level' => 0, 'creative_level' => 8, 'presets' => []]);

        $this->postJson('/api/outfit-settings', [
            'style' => 'minimal chic',
            'ornament_level' => 2,
            'creative_level' => 7,
            'presets' => [
                ['name' => 'Sang trọng tối giản', 'style' => 'minimal', 'ornament' => 1, 'creative' => 8],
                ['name' => 'Bold', 'style' => 'bold', 'ornament' => 9, 'creative' => 6],
            ],
        ])->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('style', 'minimal chic')
            ->assertJsonPath('ornament_level', 2)
            ->assertJsonPath('creative_level', 7)
            ->assertJsonCount(2, 'presets');

        $this->getJson('/api/outfit-settings')->assertOk()
            ->assertJsonPath('style', 'minimal chic')
            ->assertJsonPath('ornament_level', 2)
            ->assertJsonPath('creative_level', 7)
            ->assertJsonCount(2, 'presets')
            ->assertJsonPath('presets.0.name', 'Sang trọng tối giản');

        $row = StudioOutfitSetting::where('user_id', $admin->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('minimal chic', $row->style);
        $this->assertCount(2, $row->presets);

        $this->postJson('/api/outfit-settings', [
            'style' => 'updated',
            'ornament_level' => 4,
            'creative_level' => 5,
            'presets' => [['name' => 'Chỉ còn một', 'style' => '', 'ornament' => 0, 'creative' => 8]],
        ])->assertOk();
        $this->assertSame(1, StudioOutfitSetting::where('user_id', $admin->id)->count());
        $this->assertSame('updated', $row->fresh()->style);

        // Preset thiếu name -> 422 (không 500).
        $this->postJson('/api/outfit-settings', [
            'style' => 'x', 'presets' => [['style' => 'no-name']],
        ])->assertStatus(422);

        // [CẬP NHẬT KỲ VỌNG 2026-09-17 · Đợt 0.1] `studio_outfit_settings` CÓ cột user_id ⇒
        // customer dùng được cài đặt Ghép Trang Phục CỦA CHÍNH MÌNH (trước đây bị 403 vì
        // toàn bộ /api/* nằm sau middleware admin). Bất biến thật là CÔ LẬP giữa các user —
        // đã có test riêng: StudioResourceOwnershipTest::test_outfit_settings_are_isolated_per_user.
        $customer = User::where('email', 'user@fabrikai.shop')->first();
        $this->actingAs($customer)->getJson('/api/outfit-settings')->assertOk()
            ->assertJson(['style' => '', 'ornament_level' => 0, 'creative_level' => 8, 'presets' => []]);
    }

    public function test_studio_preset_manager_and_references(): void
    {
        // [CẬP NHẬT KỲ VỌNG 2026-09-17 · Đợt 0.1] bảng `presets` là TOÀN CỤC (không user_id):
        //   - ĐỌC  (GET /api/presets)  → customer ĐƯỢC đọc, vì UI cần preset để dựng prompt
        //   - GHI  (POST/PUT/DELETE)   → vẫn ADMIN-ONLY, vì sửa là ảnh hưởng MỌI người dùng.
        $customer = User::where('email', 'user@fabrikai.shop')->first();
        $this->actingAs($customer)->getJson('/api/presets')->assertOk()->assertJsonStructure(['items']);
        $this->actingAs($customer)->postJson('/api/presets', [
            'category' => 'style', 'ui_label' => 'Customer không được ghi', 'prompt_injection' => 'x',
        ])->assertForbidden();
        $this->assertDatabaseMissing('presets', ['ui_label' => 'Customer không được ghi']);

        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/presets')->assertOk()->assertJsonStructure(['items']);

        $this->actingAs($admin)->postJson('/api/presets', [
            'category' => 'style',
            'ui_label' => 'Test Key',
            'prompt_injection' => 'test value, elegant',
            'sort_order' => 99,
        ])->assertOk();

        $this->assertDatabaseHas('presets', ['ui_label' => 'Test Key', 'category' => 'style']);
    }

    public function test_studio_settings_api_is_admin_only(): void
    {
        $this->getJson('/api/settings-vue/data')->assertStatus(401);

        $customer = User::where('email', 'user@fabrikai.shop')->first();
        $this->actingAs($customer)->getJson('/api/settings-vue/data')->assertForbidden();

        $this->actingAs($this->admin())->getJson('/api/settings-vue/data')->assertOk();
    }

    public function test_studio_update_settings_and_api_key(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->post('/api/settings', [
            'image_credits' => 3,
            'video_credits' => 20,
            'max_generations' => 60,
            'image_provider' => 'qwen',
            'prompt_provider' => 'gemini',
            'vision_provider' => 'gemini',
            'prompt_model' => 'gemini-2.5-flash',
            'image_model' => 'flux-1.1-schnell',
            'wan_model' => 'wan2.7-image-pro',
            'qwen_model' => 'qwen-image-plus',
            'qwen_edit_model' => 'qwen-image-edit',
            'video_model' => 'wan2.5-t2v',
            'vision_model' => 'qwen-vl-plus',
            'dashscope_base' => 'https://dashscope-intl.aliyuncs.com',
            'processing' => 'queue',
            'image_resolution' => '1K',
            'video_resolution' => '1080',
            'image_ratio' => '9:16',
            'video_duration' => '15',
        ])->assertSessionHas('success');

        $this->assertSame('3', setting('studio_image_credits'));
        $this->assertSame('20', setting('studio_video_credits'));
        $this->assertSame('qwen', setting('studio_image_provider'));
        $this->assertSame('gemini-2.5-flash', setting('studio_prompt_model'));
        $this->assertSame('qwen-image-plus', setting('studio_qwen_model'));
        $this->assertSame('https://dashscope-intl.aliyuncs.com', setting('studio_dashscope_base'));
        $this->assertSame('https://token-plan.ap-southeast-1.maas.aliyuncs.com', setting('studio_dashscope_token_plan_base'));
        $this->assertSame('queue', setting('studio_processing'));
        $this->assertSame('1K', setting('studio_image_resolution'));
        $this->assertSame('1080', setting('studio_video_resolution'));
        $this->assertSame('9:16', setting('studio_image_ratio'));
        $this->assertSame('15', setting('studio_video_duration'));

        // Form API key cũ (/studio/api) đã bỏ — dùng API key registry /api/keys.
        $this->post('/api/keys', [
            'provider' => 'gemini', 'label' => 'gemini-main', 'value' => 'AIzaTestKey',
        ])->assertRedirect();

        $this->assertSame('AIzaTestKey', studio_api_key('gemini'));
        $this->assertNull(studio_api_key('wan'));
    }

    public function test_studio_suggest_from_image(): void
    {
        $this->actingAs($this->admin());

        $response = $this->post('/api/suggest', [
            'image' => UploadedFile::fake()->image('ref.jpg', 400, 500),
        ])->assertOk();

        $response->assertJsonStructure([
            'preset_ids', 'styles', 'image_prompt_en', 'video_prompt_en', 'creative_level', 'adherence',
            'detail_level', 'garment_type', 'embellishment', 'color_palette', 'detail_notes',
        ]);
        $data = $response->json();
        $this->assertNotEmpty($data['image_prompt_en']);
        $this->assertNotEmpty($data['video_prompt_en']);
        $this->assertSame(6, (int) $data['creative_level']);
        $this->assertSame(5, (int) $data['adherence']);
        $this->assertSame(8, (int) $data['detail_level']);
    }

    public function test_studio_presets_camera_lens_video_scene(): void
    {
        $camera = Preset::category('camera')->get();
        $this->assertGreaterThanOrEqual(8, $camera->count(), 'Phải có ít nhất 8 góc máy ảnh.');
        $this->assertNotEmpty($camera->first()->note, 'Góc máy phải có chú giải (note).');
        $this->assertStringContainsString('eye', strtolower($camera->first()->prompt_injection));

        $lens = Preset::category('lens')->get();
        $this->assertGreaterThanOrEqual(7, $lens->count(), 'Phải có ít nhất 7 tiêu cự ống kính.');
        $this->assertNotEmpty($lens->first()->note);

        $video = Preset::category('video_scene')->get();
        $this->assertGreaterThanOrEqual(8, $video->count(), 'Phải có ít nhất 8 kịch bản quay.');
        $this->assertNotEmpty($video->first()->note);
        $this->assertStringContainsString('runway', strtolower($video->first()->prompt_injection));

        $pose = Preset::category('pose')->get();
        $this->assertGreaterThanOrEqual(12, $pose->count(), 'Phải có ít nhất 12 dáng đứng.');
        $this->assertNotEmpty($pose->first()->note);
        $this->assertStringContainsString('pose', strtolower($pose->first()->prompt_injection));
    }

    public function test_studio_qwen_multimodal_model_resolution(): void
    {
        $this->assertSame('qwen3.8-flash', studio_vision_model('qwen'));
        $this->assertTrue(is_qwen_vision_capable('qwen3.8-flash'));
        $this->assertTrue(is_qwen_vision_capable('qwen3.8-max'));
        $this->assertTrue(is_qwen_vision_capable('qwen-vl-max'));

        $this->assertFalse(is_qwen_vision_capable('qwen-image-3.0-pro'));
        $this->assertFalse(is_qwen_vision_capable('qwen-image-edit'));
        $this->assertFalse(is_qwen_vision_capable('wanx2.1-imageedit'));

        $models = studio_qwen_vision_models();
        $this->assertSame('qwen3.8-flash', $models[0]);
        $this->assertTrue(in_array('qwen3.8-max', $models, true));
        $this->assertTrue(in_array('qwen-vl-max', $models, true));

        set_setting('studio_qwen_vision_model', 'qwen-image-3.0-pro');
        $this->assertSame('qwen3.8-flash', studio_vision_model('qwen'));
        set_setting('studio_qwen_vision_model', '');

        $text = studio_qwen_text_models();
        $this->assertSame('qwen3.8-flash', $text[0]);
        $this->assertTrue(in_array('qwen3.8-max', $text, true));

        set_setting('studio_qwen_vision_model', 'qwen3.8-max');
        $this->assertSame('qwen3.8-max', studio_vision_model('qwen'));
        $this->assertSame('qwen3.8-max', studio_qwen_vision_models()[0]);
        set_setting('studio_qwen_vision_model', '');

        set_setting('studio_qwen_vision_models', 'qwen3.8-max,qwen3.8-flash,qwen-vl-plus');
        $this->assertSame(['qwen3.8-max', 'qwen3.8-flash', 'qwen-vl-plus', 'qwen-vl-max'], studio_qwen_vision_models());
        set_setting('studio_qwen_vision_models', '');
        set_setting('studio_qwen_text_models', 'qwen3.8-max,qwen-turbo');
        $this->assertSame(['qwen3.8-max', 'qwen-turbo'], studio_qwen_text_models());
        set_setting('studio_qwen_text_models', '');
    }

    public function test_studio_api_qwen_edit_key(): void
    {
        $this->actingAs($this->admin());

        // studio_api_key('qwen_edit') resolves the dedicated (encrypted) setting.
        set_setting('api_qwen_edit_key', Crypt::encryptString('sk-edit-123456'));
        $this->assertSame('sk-edit-123456', studio_api_key('qwen_edit'));
    }

    public function test_studio_qwen_credentials_rotation(): void
    {
        $this->actingAs($this->admin());

        set_setting('api_qwen_key', Crypt::encryptString('sk-sp-plan-123456'));
        set_setting('api_qwen_edit_key', Crypt::encryptString('sk-ws-paygo-123456'));

        $gen = studio_qwen_credentials('image');
        $this->assertSame('sk-ws-paygo-123456', $gen[0]);
        $this->assertSame('sk-sp-plan-123456', $gen[1]);
        $prompt = studio_qwen_credentials('prompt');
        $this->assertSame('sk-sp-plan-123456', $prompt[0]);

        $edit = studio_qwen_credentials('edit');
        $this->assertSame('sk-ws-paygo-123456', $edit[0]);
        $this->assertSame('sk-sp-plan-123456', $edit[1]);

        set_setting('api_qwen_key', '');
        set_setting('api_qwen_edit_key', '');
        $this->assertSame([], studio_qwen_credentials('image'));
    }

    public function test_studio_usage_stats(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $admin->generations()->create(['type' => 'image', 'status' => 'completed', 'credits_cost' => 3]);
        $admin->generations()->create(['type' => 'video', 'status' => 'completed', 'credits_cost' => 10]);

        $u = studio_usage($admin);
        $this->assertSame(13, $u['used_total']);
        $this->assertSame(13, $u['used_today']);
        $this->assertSame($admin->fresh()->credits_balance, $u['balance']);

        set_setting('studio_provider_quota_resets_at', '09-04 20:57:00 UTC');
        $this->assertSame('09-04 20:57:00 UTC', studio_usage($admin)['quota_resets_at']);
    }

    public function test_studio_dashscope_base_url_routing(): void
    {
        $this->assertSame('https://dashscope-intl.aliyuncs.com', dashscope_base_url('sk-abcdef123456'));
        $this->assertSame('https://token-plan.ap-southeast-1.maas.aliyuncs.com', dashscope_base_url('sk-sp-abcdef123456'));

        set_setting('studio_dashscope_base', 'https://dashscope.aliyuncs.com');
        $this->assertSame('https://dashscope.aliyuncs.com', dashscope_base_url('sk-abc'));

        set_setting('studio_dashscope_base', 'https://token-plan.ap-southeast-1.maas.aliyuncs.com');
        $this->assertSame('https://dashscope-intl.aliyuncs.com', dashscope_base_url('sk-ws-abcdef'));
        $this->assertSame('https://dashscope-intl.aliyuncs.com', dashscope_base_url('sk-abcdef'));
        $this->assertSame('https://token-plan.ap-southeast-1.maas.aliyuncs.com', dashscope_base_url('sk-sp-abcdef'));
    }

    public function test_studio_show_heals_stuck_processing_generation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $gen = $admin->generations()->create([
            'type' => 'image', 'status' => 'processing', 'prompt' => 'x', 'credits_cost' => 1,
        ]);
        DB::table('generations')->where('id', $gen->id)
            ->update(['updated_at' => now()->subMinutes(20)]);

        $this->getJson('/api/generations/'.$gen->id)->assertOk();
        $this->assertSame('failed', $gen->fresh()->status);
        $this->assertStringContainsString('Hết thời gian xử lý', $gen->fresh()->error);
        $this->assertSame(1001, $admin->fresh()->credits_balance);
    }

    public function test_studio_cancel_and_delete_generation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $gen = $admin->generations()->create([
            'type' => 'image', 'status' => 'pending', 'prompt' => 'x', 'credits_cost' => 5,
        ]);
        $this->postJson('/api/generations/'.$gen->id.'/cancel')->assertOk();
        $this->assertSame('cancelled', $gen->fresh()->status);
        $this->assertSame(1005, $admin->fresh()->credits_balance);

        $g2 = $admin->generations()->create(['type' => 'image', 'status' => 'completed', 'prompt' => 'x']);
        $this->deleteJson('/api/generations/'.$g2->id)->assertOk();
        $this->assertNull(Generation::find($g2->id));
    }
}
