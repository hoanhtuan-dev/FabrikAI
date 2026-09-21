<?php

namespace Tests\Feature;

use App\Ai\Agents\SamplePromptAgent;
use App\Ai\RegistryProviders;
use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CẦU NỐI MODEL REGISTRY → LARAVEL AI SDK (2026-09-22).
 *
 * Khoá ba bất biến, vì đây là chỗ dễ hỏng âm thầm nhất khi ghép SDK vào một hệ đã có sẵn phần quản trị:
 *   1. THỨ TỰ và NGUỒN: danh sách provider của SDK phải theo ĐÚNG thứ tự ưu tiên mà Cài đặt quyết định
 *      (Model Registry + Nhóm công việc), không phải theo thứ tự tình cờ của config tệp.
 *   2. MỘT ENTRY MỖI KHOÁ: dự án luân chuyển nhiều khoá cho cùng một nhà cung cấp; gộp chúng thành một
 *      entry là mất tính năng failover mà không ai thấy.
 *   3. ĐỊA CHỈ + DRIVER đúng: Qwen đi đường openai-compatible với host suy từ khoá; Gemini đi driver
 *      gemini. Sai driver thì lỗi chỉ lộ ra lúc chạy thật (tốn tiền, khó truy).
 */
class AiSdkBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    /** Khai một nhà cung cấp TỰ KHAI (protocol openai) + N khoá + một model cho một nhóm. */
    private function provider(string $slug, string $base, string $modelId, array $keys = ['sk-one'], string $group = DesignAgentService::SEARCH_GROUP): void
    {
        StudioProvider::create([
            'slug' => $slug, 'name' => strtoupper($slug), 'protocol' => 'openai',
            'base_url' => $base, 'auth_style' => 'bearer', 'api_key_ref' => $slug,
            'priority' => 9, 'enabled' => true,
        ]);

        foreach ($keys as $i => $key) {
            StudioApiKey::create([
                'provider' => $slug, 'label' => $slug.'-'.$i, 'value' => $key,
                'kind' => null, 'scopes' => ['*'], 'priority' => 50 - $i, 'enabled' => true,
            ]);
        }

        StudioModel::create([
            'group' => $group, 'name' => $modelId, 'provider' => $slug,
            'model_id' => $modelId, 'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_'.$group.'_model', $slug.':'.$modelId);
    }

    public function test_it_registers_a_provider_from_the_registry_with_the_right_driver_url_and_key(): void
    {
        $this->provider('paygo', 'https://maas.example.com/compatible-mode/v1', 'qwen3.8-omni-flash', ['sk-abc']);

        $rows = app(RegistryProviders::class)->forGroup(DesignAgentService::SEARCH_GROUP);

        $this->assertCount(1, $rows, 'Một nhà cung cấp một khoá ⇒ đúng một entry.');
        $this->assertSame('paygo:qwen3.8-omni-flash', $rows[0]['label']);

        $config = config('ai.providers.'.$rows[0]['name']);
        $this->assertSame('openai-compatible', $config['driver'], 'Nhà cung cấp tự khai phải đi driver tổng quát.');
        $this->assertSame('https://maas.example.com/compatible-mode/v1', $config['url']);
        $this->assertSame('sk-abc', $config['key'], 'Khoá phải lấy từ bảng API key, không phải từ config tệp.');
        $this->assertSame('qwen3.8-omni-flash', $config['models']['text']['default']);
    }

    public function test_every_key_becomes_its_own_entry_so_failover_can_rotate_keys(): void
    {
        $this->provider('paygo', 'https://maas.example.com/v1', 'm', ['sk-one', 'sk-two', 'sk-three']);

        $rows = app(RegistryProviders::class)->forGroup(DesignAgentService::SEARCH_GROUP);

        $this->assertCount(3, $rows, 'Ba khoá phải thành BA entry — gộp lại là mất tính năng luân chuyển khoá.');
        $keys = array_map(fn ($r) => config('ai.providers.'.$r['name'])['key'], $rows);
        $this->assertSame(['sk-one', 'sk-two', 'sk-three'], $keys, 'Thứ tự khoá phải theo priority trong Cài đặt.');
    }

    public function test_failover_args_are_a_provider_to_model_map(): void
    {
        $this->provider('solo', 'https://solo.example/v1', 'model-a', ['sk-a'], DesignAgentService::VISION_GROUP);

        $args = app(RegistryProviders::class)->failoverArgs(DesignAgentService::VISION_GROUP);

        // HÌNH DẠNG NÀY LÀ HỢP ĐỒNG VỚI SDK, không phải lựa chọn của ta: prompt() khai ?string $model
        // (model KHÔNG nhận mảng), còn tham số provider nhận BẢN ĐỒ tên => model. Truyền hai mảng song
        // song là TypeError — đúng lỗi đã gặp thật khi chạy --live lần đầu trên production.
        $this->assertNotEmpty($args['providers']);
        foreach ($args['providers'] as $name => $model) {
            $this->assertIsString($name, 'Khoá phải là TÊN provider.');
            $this->assertIsString($model, 'Giá trị phải là MODEL của provider đó.');
            $this->assertNotSame('', $model);
        }
        $this->assertSame(count($args['providers']), count($args['labels']));
    }

    public function test_a_group_with_no_usable_provider_yields_an_empty_list_instead_of_a_broken_entry(): void
    {
        // Có model trong registry nhưng KHÔNG có khoá ⇒ không được đăng ký một provider sẽ hỏng lúc chạy.
        StudioModel::create([
            'group' => DesignAgentService::SEARCH_GROUP, 'name' => 'm', 'provider' => 'ghost',
            'model_id' => 'ghost-model', 'api_key_ref' => 'ghost', 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_'.DesignAgentService::SEARCH_GROUP.'_model', 'ghost:ghost-model');

        $rows = app(RegistryProviders::class)->forGroup(DesignAgentService::SEARCH_GROUP);

        $this->assertSame([], $rows, 'Thiếu khoá ⇒ danh sách RỖNG để nơi gọi nói thật, không phải entry hỏng.');
    }

    public function test_the_structured_agent_returns_schema_shaped_output(): void
    {
        SamplePromptAgent::fake([
            ['prompt_vi' => 'Áo linen tay dài', 'prompt_en' => 'long sleeve linen shirt', 'negative_prompt' => 'no logo', 'note' => 'bán chạy'],
        ]);

        $response = (new SamplePromptAgent(['sample' => ['name' => 'Áo linen']]))->prompt('viết prompt');

        $data = $response->structured;
        $this->assertSame('Áo linen tay dài', $data['prompt_vi']);
        $this->assertSame('long sleeve linen shirt', $data['prompt_en']);
        $this->assertSame('no logo', $data['negative_prompt']);
        $this->assertSame('bán chạy', $data['note']);
    }

    public function test_the_agent_schema_matches_what_the_existing_flow_reads(): void
    {
        // Bất biến quan trọng nhất khi thay động cơ: ĐẦU RA PHẢI GIỮ NGUYÊN HÌNH DẠNG, nếu không thì
        // mọi thứ đọc $json['prompt_vi'] ở đường cũ sẽ im lặng nhận chuỗi rỗng.
        // SDK truyền ĐÚNG lớp này cho schema() (xem Laravel\Ai\Providers\Concerns\GeneratesText) —
        // dùng cùng nó để phép kiểm phản ánh đúng thứ chạy thật, không phải một lớp tương đương tự chế.
        $schema = (new SamplePromptAgent)->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);

        foreach (['prompt_vi', 'prompt_en', 'negative_prompt', 'note'] as $field) {
            $this->assertArrayHasKey($field, $schema, 'Thiếu trường '.$field.' là đường cũ đọc ra rỗng.');
        }
    }
    public function test_it_falls_back_to_the_base_group_when_the_role_group_is_empty(): void
    {
        // Nhóm VAI bỏ trống là chuyện BÌNH THƯỜNG (agent rơi về nhóm nền). Cầu nối phải đi cùng chuỗi đó,
        // nếu không nó trả RỖNG cho một hệ vẫn chạy được và lệnh kiểm tra sẽ nói sai với người dùng.
        $this->provider('base', 'https://base.example/v1', 'base-model', ['sk-base'], DesignAgentService::REASON_GROUP);

        $rows = app(RegistryProviders::class)->forGroup(DesignAgentService::SEARCH_GROUP, [DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP]);

        $this->assertNotEmpty($rows, 'Nhóm tìm kiếm bỏ trống ⇒ phải rơi về nhóm nền, không được trả rỗng.');
        $this->assertSame('base:base-model', $rows[0]['label']);
    }

    public function test_the_role_chains_match_the_ones_the_service_uses(): void
    {
        $bridge = app(RegistryProviders::class);

        $this->assertSame(
            [DesignAgentService::SEARCH_GROUP, DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP],
            $bridge->chainFor('search'),
        );
        $this->assertSame([DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP], $bridge->chainFor('reason'));
        $this->assertSame([DesignAgentService::VISION_GROUP, 'vision'], $bridge->chainFor('vision'));
    }
}

