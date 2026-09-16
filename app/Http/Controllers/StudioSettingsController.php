<?php

namespace App\Http\Controllers;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * JSON API for the Studio Settings SPA (Vue).
 *
 * Modeled after the DeepSeek Harness Models page: ONE snapshot endpoint joins the
 * three wire domains the page needs — the provider directory (built-in + custom
 * routes with their live/dormant state), the key registry (value-free rows), and
 * the model registry — so the page can never render a state resolution disagrees
 * with. Mutations are small, per-entity JSON endpoints; secrets stay write-only
 * (never echoed back, only a presence badge and a short prefix on test).
 */
class StudioSettingsController extends Controller
{
    /**
     * GET /studio/settings-vue/data — the joined snapshot.
     */
    public function data(): JsonResponse
    {
        $registry = studio_provider_registry();

        // Key counts per provider slug — powers the configured/missing dot on each row.
        $keyCounts = StudioApiKey::query()->where('enabled', true)
            ->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider');

        $providers = [];
        foreach ($registry as $slug => $meta) {
            $n = (int) ($keyCounts[$slug] ?? 0);
            $providers[] = [
                'slug' => $slug,
                'name' => $meta['name'],
                'protocol' => $meta['protocol'],
                'base_url' => $meta['base_url'],
                'auth_style' => $meta['auth_style'],
                'hint' => $meta['hint'],
                'custom' => $meta['custom'],
                'enabled' => $meta['enabled'],
                'key_count' => $n,
                'configured' => $n > 0,
            ];
        }

        return response()->json([
            'providers' => $providers,
            'api_keys' => $this->keyRows(),
            'models' => $this->modelRows(),
            // Task groups: mỗi card/tính năng một nhóm — models của nhóm + default hiện hành.
            // Đây là cầu nối giữa các tab: tab Models đăng ký model vào nhóm, tab Cấu hình
            // nhóm chọn default, cards /studio đọc đúng danh sách của nhóm mình.
            'task_groups' => $this->taskGroupRows(),
            'config' => [
                'image_provider' => setting('studio_image_provider', 'flux'),
                'image_model' => setting('studio_image_model', config('studio.image_model')),
                'qwen_model' => setting('studio_qwen_model', ''),
                'video_model' => setting('studio_video_model', config('studio.video_model')),
                'vision_provider' => setting('studio_vision_provider', 'gemini'),
                'prompt_provider' => setting('studio_prompt_provider', 'gemini'),
                'processing' => setting('studio_processing', config('studio.processing')),
                'image_credits' => (int) setting('studio_image_credits', config('studio.image_credits', 1)),
                'video_credits' => (int) setting('studio_video_credits', config('studio.video_credits', 10)),
            ],
            'usage' => studio_usage(auth()->user()),
        ]);
    }

    /**
     * POST /studio/settings-vue/task-defaults — gán default model cho một nhóm công việc.
     * value = "provider:model" hoặc "" (xóa default → về legacy/auto theo priority).
     */
    public function updateTaskDefault(Request $request): JsonResponse
    {
        $groups = studio_task_groups();
        $data = $request->validate([
            'group' => ['required', 'string', Rule::in(array_keys($groups))],
            'value' => ['nullable', 'string', 'max:160'],
        ]);

        $value = trim((string) ($data['value'] ?? ''));
        if ($value !== '' && (! str_contains($value, ':') || strlen($value) > 160)) {
            return response()->json(['message' => 'Giá trị phải dạng provider:model.'], 422);
        }

        set_setting('studio_task_'.$data['group'].'_model', $value);

        return response()->json(['ok' => true, 'group' => $data['group'], 'value' => $value]);
    }

    /**
     * Task-group rows for the snapshot: label + ordered models + current default
     * (default mới nếu đã gán; ngược lại model đầu danh sách kế thừa legacy).
     */
    protected function taskGroupRows(): array
    {
        $rows = [];
        foreach (studio_task_groups() as $group => $meta) {
            $rows[$group] = [
                'label' => $meta['label'],
                'default' => studio_task_group_default($group),
                'assigned' => (string) setting('studio_task_'.$group.'_model', ''),
                'models' => studio_task_group_models($group),
            ];
        }
        return $rows;
    }

    /**
     * GET /studio/settings-vue/models — model registry alone (for pickers).
     */
    public function models(): JsonResponse
    {
        return response()->json(['models' => $this->modelRows()]);
    }

    // ── API Keys ────────────────────────────────────────────────────────────

    public function storeKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:60'],
            'label' => ['required', 'string', 'max:120'],
            'value' => ['required', 'string', 'max:500'],
            'kind' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // DSH-style key hygiene: trim, then refuse empty / whitespace-only values.
        $value = trim((string) $data['value']);
        if ($value === '') {
            return response()->json(['message' => 'Key không được để trống.'], 422);
        }

        $key = StudioApiKey::create([
            'provider' => $data['provider'],
            'label' => $data['label'],
            'value' => $value, // encryption enforced by StudioApiKey::setValueAttribute()
            'kind' => $data['kind'] ?? null,
            'scopes' => ['*'],
            'priority' => (int) ($data['priority'] ?? 5),
            'enabled' => true,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'key' => $this->mapKey($key)], 201);
    }

    public function updateKey(Request $request, StudioApiKey $key): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:60'],
            'value' => ['nullable', 'string', 'max:500'],
            'kind' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $fill = [
            'provider' => $data['provider'],
            'label' => $data['label'],
            'kind' => $data['kind'] ?? null,
            'priority' => (int) ($data['priority'] ?? 0),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'note' => $data['note'] ?? null,
        ];

        // Write-only secret: an empty value keeps the stored key.
        if (! empty($data['value'])) {
            $fill['value'] = trim((string) $data['value']);
        }

        $key->update($fill);

        return response()->json(['ok' => true, 'key' => $this->mapKey($key->fresh())]);
    }

    public function deleteKey(StudioApiKey $key): JsonResponse
    {
        $key->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /studio/settings-vue/keys/{key}/test — verify a key resolves and show
     * a short prefix only (never the full secret).
     */
    public function testKey(StudioApiKey $key): JsonResponse
    {
        $value = studio_api_key_value($key);

        return response()->json([
            'ok' => (bool) $value,
            'provider' => $key->provider,
            'key_prefix' => $value ? substr($value, 0, 8).'…' : null,
            'note' => $value ? 'OK — key giải mã được và sẵn sàng dùng.' : 'Key rỗng hoặc giải mã thất bại.',
        ]);
    }

    // ── Custom Providers ────────────────────────────────────────────────────

    public function storeProvider(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:120'],
            'protocol' => ['required', 'string', 'in:openai,dashscope,gemini'],
            'base_url' => ['required', 'string', 'max:255', 'regex:/^https?:\/\/[^\/]+/'],
            'auth_style' => ['nullable', 'string', 'in:bearer,x-goog-api-key'],
            'api_key_ref' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset(studio_provider_catalog()[$data['slug']])) {
            return response()->json(['message' => 'Slug trùng provider tích hợp ('.$data['slug'].'). Chọn slug khác.'], 422);
        }
        if (StudioProvider::where('slug', $data['slug'])->exists()) {
            return response()->json(['message' => 'Slug đã tồn tại.'], 422);
        }

        $p = StudioProvider::create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'protocol' => $data['protocol'],
            'base_url' => rtrim((string) $data['base_url'], '/'),
            'auth_style' => $data['auth_style'] ?? 'bearer',
            'api_key_ref' => $data['api_key_ref'] ?: $data['slug'],
            'enabled' => true,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'provider' => $this->mapProvider($p)], 201);
    }

    public function updateProvider(Request $request, StudioProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'protocol' => ['required', 'string', 'in:openai,dashscope,gemini'],
            'base_url' => ['required', 'string', 'max:255', 'regex:/^https?:\/\/[^\/]+/'],
            'auth_style' => ['nullable', 'string', 'in:bearer,x-goog-api-key'],
            'api_key_ref' => ['nullable', 'string', 'max:60'],
            'enabled' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // The Provider ID (slug) stays fixed — it is the settings key every model
        // row and every logged generation references (same rule as DSH).
        $provider->update([
            'name' => $data['name'],
            'protocol' => $data['protocol'],
            'base_url' => rtrim((string) $data['base_url'], '/'),
            'auth_style' => $data['auth_style'] ?? 'bearer',
            'api_key_ref' => $data['api_key_ref'] ?: $provider->slug,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'provider' => $this->mapProvider($provider->fresh())]);
    }

    public function deleteProvider(StudioProvider $provider): JsonResponse
    {
        $provider->delete();
        return response()->json(['ok' => true]);
    }

    // ── Models ──────────────────────────────────────────────────────────────

    public function storeModel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'in:image,edit,video,swap,vision,prompt,translate,inference,text'],
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:60'],
            'model_id' => ['required', 'string', 'max:255'],
            'api_key_ref' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $m = StudioModel::create([
            'group' => $data['group'],
            'name' => $data['name'],
            'provider' => $data['provider'],
            'model_id' => $data['model_id'],
            'api_key_ref' => $data['api_key_ref'] ?: $data['provider'],
            'priority' => (int) ($data['priority'] ?? 5),
            'enabled' => $data['enabled'] ?? true,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'model' => $this->mapModel($m)], 201);
    }

    public function updateModel(Request $request, StudioModel $model): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'in:image,edit,video,swap,vision,prompt,translate,inference,text'],
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:60'],
            'model_id' => ['required', 'string', 'max:255'],
            'api_key_ref' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $model->update([
            'group' => $data['group'],
            'name' => $data['name'],
            'provider' => $data['provider'],
            'model_id' => $data['model_id'],
            'api_key_ref' => $data['api_key_ref'] ?: $data['provider'],
            'priority' => (int) ($data['priority'] ?? 0),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'model' => $this->mapModel($model->fresh())]);
    }

    public function deleteModel(StudioModel $model): JsonResponse
    {
        $model->delete();
        return response()->json(['ok' => true]);
    }

    // ── Config (small typed updates from the General tab) ───────────────────

    public function updateConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image_provider' => ['nullable', 'string', 'max:60'],
            'image_model' => ['nullable', 'string', 'max:255'],
            'qwen_model' => ['nullable', 'string', 'max:255'],
            'video_model' => ['nullable', 'string', 'max:255'],
            'vision_provider' => ['nullable', 'string', 'max:60'],
            'prompt_provider' => ['nullable', 'string', 'max:60'],
            'processing' => ['nullable', 'string', 'in:sync,queue'],
            'image_credits' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'video_credits' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $map = [
            'image_provider' => 'studio_image_provider',
            'image_model' => 'studio_image_model',
            'qwen_model' => 'studio_qwen_model',
            'video_model' => 'studio_video_model',
            'vision_provider' => 'studio_vision_provider',
            'prompt_provider' => 'studio_prompt_provider',
            'processing' => 'studio_processing',
            'image_credits' => 'studio_image_credits',
            'video_credits' => 'studio_video_credits',
        ];
        foreach ($map as $in => $settingKey) {
            if (isset($data[$in])) {
                set_setting($settingKey, (string) $data[$in]);
            }
        }

        return response()->json(['ok' => true]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    protected function keyRows()
    {
        return StudioApiKey::orderBy('provider')->orderByDesc('priority')->orderBy('id')->get()
            ->map(fn ($k) => $this->mapKey($k))->values();
    }

    protected function modelRows()
    {
        return StudioModel::orderByDesc('priority')->orderBy('id')->get()
            ->map(fn ($m) => $this->mapModel($m))->values();
    }

    protected function mapKey(StudioApiKey $k): array
    {
        return [
            'id' => $k->id,
            'provider' => $k->provider,
            'label' => $k->label,
            'kind' => $k->kind,
            'priority' => $k->priority,
            'enabled' => (bool) $k->enabled,
            'note' => $k->note,
            'has_value' => true, // value is hidden at the model layer; presence only
            'created_at' => $k->created_at?->format('d/m/Y'),
        ];
    }

    protected function mapProvider(StudioProvider $p): array
    {
        $n = (int) StudioApiKey::where('provider', $p->api_key_ref)->where('enabled', true)->count();
        $nSlug = (int) StudioApiKey::where('provider', $p->slug)->where('enabled', true)->count();

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'protocol' => $p->protocol,
            'base_url' => $p->base_url,
            'auth_style' => $p->auth_style,
            'api_key_ref' => $p->api_key_ref,
            'enabled' => (bool) $p->enabled,
            'note' => $p->note,
            'custom' => true,
            'key_count' => max($n, $nSlug),
            'configured' => max($n, $nSlug) > 0,
        ];
    }

    protected function mapModel(StudioModel $m): array
    {
        return [
            'id' => $m->id,
            'group' => $m->group,
            'name' => $m->name,
            'provider' => $m->provider,
            'model_id' => $m->model_id,
            'api_key_ref' => $m->api_key_ref,
            'priority' => $m->priority,
            'enabled' => (bool) $m->enabled,
            'note' => $m->note,
        ];
    }
}
