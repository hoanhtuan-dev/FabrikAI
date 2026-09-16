<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    /**
     * Single-page product manager: list + create/edit live in ONE Vue SPA.
     * index / create / edit all render the same minimal view; the only
     * difference is the boot `mode` (list | create | edit) + optional product.
     */
    public function index(Request $request)
    {
        return $this->renderSpa($request, new Product(), 'list');
    }

    public function create(Request $request)
    {
        return $this->renderSpa($request, new Product(), 'create');
    }

    public function edit(Request $request, Product $product)
    {
        $product->load('variants');

        return $this->renderSpa($request, $product, 'edit');
    }

    /**
     * JSON feed for the SPA list (search / filter / paginate without reload).
     */
    public function data(Request $request)
    {
        return response()->json($this->listPayload($request));
    }

    /**
     * JSON payload of a single product for the inline editor.
     */
    public function payload(Product $product)
    {
        $product->load('variants');

        return response()->json($this->productPayload($product));
    }

    protected function renderSpa(Request $request, Product $product, string $mode)
    {
        $categories = Category::active()->orderBy('sort_order')->with('children')->get();

        $boot = [
            'mode' => $mode,
            'product' => $product->exists ? $this->productPayload($product) : null,
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'children' => $c->children->map(fn ($ch) => ['id' => $ch->id, 'name' => $ch->name])->values()->all(),
            ])->values()->all(),
            'ai' => [
                'enabled' => product_ai_enabled(),
                'providers' => product_ai_providers(),
                'provider' => product_ai_providers()[0] ?? 'qwen',
                'model' => product_ai_qwen_text_models()[0] ?? 'qwen3.8-flash',
                'gemini_model' => product_ai_gemini_text_model(),
                'deepseek_model' => product_ai_deepseek_model(),
                // True when at least one provider has a usable API key — the UI uses
                // this to explain why it fell back to offline suggestions.
                'has_keys' => (bool) (studio_api_key('qwen') || studio_api_key('dashscope') || studio_api_key('gemini') || studio_api_key('deepseek')),
            ],
            'filters' => [
                'q' => (string) $request->input('q'),
                'category_id' => (string) $request->input('category_id'),
                'status' => (string) $request->input('status'),
            ],
            'products' => $this->listPayload($request),
        ];

        return view('admin.products.index', compact('boot'));
    }

    protected function listPayload(Request $request): array
    {
        $query = Product::with('category', 'variants')->latest();

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $products = $query->paginate(15);

        return [
            'data' => collect($products->items())->map(fn (Product $p) => $this->rowPayload($p))->values()->all(),
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'from' => $products->firstItem(),
            'to' => $products->lastItem(),
        ];
    }

    protected function rowPayload(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'category' => $p->category?->name,
            'image' => $p->image_url,
            'price' => (float) $p->min_price,
            'compare_price' => $p->in_sale ? (float) $p->compare_price : null,
            'stock' => (int) $p->total_stock,
            'variant_count' => $p->variants->count(),
            'featured' => (bool) $p->featured,
            'is_active' => (bool) $p->is_active,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $product = Product::create($this->fillData($data));
        $this->saveImages($request, $product);
        $this->syncVariants($request, $product);

        return $this->savedResponse($request, 'Đã tạo sản phẩm.');
    }

    /**
     * Deep-AI content + SEO suggestions (qwen configurable).
     *
     * Hỗ trợ 2 chế độ:
     *  - Gợi ý toàn bộ (mặc định): sinh toàn bộ nội dung + SEO từ ảnh/text.
     *  - Tinh chỉnh theo mục tiêu (`target` = all|name|names|description|seo):
     *    AI luôn nhìn TOÀN BỘ dữ liệu form hiện tại + hồ sơ thương hiệu
     *    (trang /admin/pages/about, được cache tạo 1 lần tái sử dụng).
     */
    public function aiSuggest(Request $request)
    {
        // Form Vue có thể gửi chuỗi rỗng ("") cho các trường số khi chưa nhập —
        // chuẩn hoá thành null TRƯỚC khi validate, nếu không Laravel trả 422
        // ('' không thoả nullable|numeric) khiến mọi lần gợi ý/tinh chỉnh AI lỗi.
        $input = $request->all();
        foreach (['price', 'compare_price', 'cost_price', 'stock'] as $f) {
            if (array_key_exists($f, $input) && ($input[$f] === '' || $input[$f] === null)) {
                $input[$f] = null;
            }
        }

        $data = validator($input, [
            'target' => ['nullable', 'string', 'in:all,name,names,description,desc_variants,seo'],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'string', 'max:500'],
            'hint' => ['nullable', 'string', 'max:1000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string'],
            'image_mime' => ['nullable', 'string', 'max:80'],
            'force' => ['nullable', 'boolean'],
        ])->validate();

        $imagePath = null;
        if (! empty($data['image_url'])) {
            $imagePath = $this->resolveImagePath($data['image_url']);
        } elseif (! empty($data['image_base64'])) {
            // Ảnh vừa upload ở form (chưa lưu) → giải mã base64 ra file tạm để AI đọc được.
            $imagePath = $this->storeBase64Image($data['image_base64'], (string) ($data['image_mime'] ?? 'image/jpeg'));
        }

        // Run INLINE (synchronous) with a hard total budget inside ProductAIService:
        // bounded attempts + per-call timeout (scaled by output size) + wall-clock
        // deadline guarantee a result well under the gateway 504 limit. Đầu ra dài
        // (desc_variants ~4000 token) cần thêm thời gian nên trần PHP nới lên 150s.
        @set_time_limit(150);

        try {
            /** @var \App\Services\ProductAIService $service */
            $service = app(\App\Services\ProductAIService::class);
            $target = $data['target'] ?? null;

            // Tinh chỉnh theo mục tiêu: luôn kèm dữ liệu toàn cục + hồ sơ thương hiệu.
            // (desc_variants PHẢI ở đây — nếu rơi xuống nhánh generate() bên dưới,
            // kết quả trả về schema đầy đủ không có key "variants" → UI báo
            // "AI không tạo được phương án nào".)
            if (in_array($target, ['name', 'names', 'description', 'desc_variants', 'seo'], true)) {
                $imageAnalysis = null;
                if ($imagePath && is_file($imagePath)) {
                    $imageAnalysis = $service->analyzeImage($imagePath, (bool) ($data['force'] ?? false));
                }
                $result = $service->refine($data, $imageAnalysis);
            } elseif ($imagePath && is_file($imagePath)) {
                $result = $service->generateFromImage($data, $imagePath, (bool) ($data['force'] ?? false));
            } else {
                $result = $service->generate($data, null);
            }
            $result['source'] ??= 'stub';
            $result['model'] = $result['model'] ?? (string) studio_config('qwen_prompt_model', 'qwen3.8-flash');
            $result['provider'] = $result['provider'] ?? (string) studio_config('prompt_provider', 'qwen');

            return response()->json(['status' => 'done', 'data' => $result]);
        } catch (\Throwable $e) {
            report($e);

            // Không lộ lỗi kỹ thuật gốc ra UI — chỉ hiện thông báo bình thường.
            return response()->json([
                'status' => 'error',
                'error' => 'AI gặp lỗi kỹ thuật — vui lòng thử lại sau ít phút.',
            ], 500);
        } finally {
            // Dọn file tạm sinh từ base64 (không phải file gốc trong storage).
            if (! empty($data['image_base64']) && $imagePath && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    /**
     * Giải mã ảnh base64 (do Vue gửi từ file vừa chọn) ra một file tạm để vision đọc.
     */
    protected function storeBase64Image(string $base64, string $mime): ?string
    {
        $bin = base64_decode($base64, true);
        if ($bin === false || $bin === '') {
            return null;
        }

        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            default => 'jpg',
        };

        $tmp = tempnam(sys_get_temp_dir(), 'pai');
        $path = $tmp.'.'.$ext;
        @unlink($tmp);
        if (file_put_contents($path, $bin) === false) {
            return null;
        }

        return $path;
    }

    public function aiSuggestPoll(Request $request)
    {
        $token = (string) $request->input('token');
        $result = \Illuminate\Support\Facades\Cache::get('product_ai:'.$token);
        if ($result) {
            \Illuminate\Support\Facades\Cache::forget('product_ai:'.$token);

            return response()->json(['status' => 'done', 'data' => $result]);
        }

        return response()->json(['status' => 'processing']);
    }

    protected function resolveImagePath(string $url): ?string
    {
        // Containment dùng chung (S5): image_url từ request có thể chứa traversal
        // (/../.env → is_file khớp → bytes nhạy cảm bị gửi lên AI provider).
        return studio_safe_public_file((string) parse_url($url, PHP_URL_PATH));
    }

    /**
     * Studio library image feed for the product image picker. Returns the
     * admin's latest generations + public studio assets.
     */
    public function studioImages(Request $request)
    {
        $images = [];

        // Latest generations — media_url is stored as /storage/{path}; expose an
        // ABSOLUTE url so asset_image() returns it unchanged and the AI image
        // resolver can locate the file on disk.
        $gens = auth()->user()->generations()->whereNotNull('media_url')->latest()->limit(60)->get();
        foreach ($gens as $g) {
            $media = (string) $g->media_url;
            $url = str_starts_with($media, 'http') ? $media : asset($media);
            $images[] = ['id' => 'gen-'.$g->id, 'url' => $url, 'type' => 'generation', 'label' => $g->prompt];
        }

        // Public studio assets (uploaded images).
        $assetsDir = public_path('studio/images/assets');
        if (is_dir($assetsDir)) {
            foreach (glob($assetsDir.'/*.{png,jpg,jpeg,webp}', GLOB_BRACE) as $f) {
                $name = basename($f);
                $images[] = ['id' => 'asset-'.$name, 'url' => asset('studio/images/assets/'.$name), 'type' => 'asset', 'label' => $name];
            }
        }

        return response()->json(['images' => $images]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validated($request);

        $product->update($this->fillData($data));
        $this->saveImages($request, $product, true);
        $this->syncVariants($request, $product);

        return $this->savedResponse($request, 'Đã cập nhật sản phẩm.');
    }

    public function destroy(Request $request, Product $product)
    {
        $product->delete();

        if ($request->expectsJson()) {
            return response()->json(['status' => 'ok', 'message' => 'Đã xóa sản phẩm.']);
        }

        return redirect()->route('admin.products.index')->with('success', 'Đã xóa sản phẩm.');
    }

    public function toggleActive(Request $request, Product $product)
    {
        $product->update(['is_active' => ! $product->is_active]);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'ok', 'is_active' => (bool) $product->is_active]);
        }

        return back()->with('success', 'Đã cập nhật trạng thái.');
    }

    protected function savedResponse(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'ok', 'message' => $message]);
        }

        return redirect()->route('admin.products.index')->with('success', $message);
    }

    protected function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'brand' => $product->brand,
            'category_id' => $product->category_id,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'price' => (float) $product->price,
            'compare_price' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'cost_price' => $product->cost_price !== null ? (float) $product->cost_price : null,
            'stock' => (int) $product->stock,
            'featured' => (bool) $product->featured,
            'is_active' => (bool) $product->is_active,
            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'tags' => (array) $product->tags,
            'image' => $product->image_url,
            'gallery' => collect((array) $product->gallery)->map(fn ($p) => [
                'value' => $p,
                'url' => asset_image($p),
            ])->values()->all(),
            'variants' => $product->variants->map(fn ($v) => [
                'name' => $v->name,
                'sku' => $v->sku,
                'price' => (float) $v->price,
                'compare_price' => $v->compare_price !== null ? (float) $v->compare_price : null,
                'stock' => (int) $v->stock,
            ])->values()->all(),
        ];
    }

    protected function validated(Request $request): array
    {
        // Chuẩn hoá: Laravel nullable|numeric không chấp nhận chuỗi rỗng "".
        // Form Vue gửi "" khi chưa nhập → convert sang null trước validate.
        $input = $request->all();
        foreach (['compare_price', 'cost_price', 'stock'] as $f) {
            if (array_key_exists($f, $input) && ($input[$f] === '' || $input[$f] === null)) {
                $input[$f] = null;
            }
        }

        return validator($input, [
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:120'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:4096'],
            'gallery.*' => ['nullable', 'image', 'max:4096'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ])->validate();
    }

    protected function fillData(array $data): array
    {
        $fill = [
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.substr(uniqid(), -4),
            'sku' => $data['sku'] ?? null,
            'brand' => $data['brand'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'compare_price' => $data['compare_price'] ?? null,
            'cost_price' => $data['cost_price'] ?? null,
            'stock' => $data['stock'] ?? 0,
            'featured' => (bool) ($data['featured'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
        ];

        return $fill;
    }

    protected function saveImages(Request $request, Product $product, bool $replace = false): void
    {
        // Cover
        if ($request->boolean('remove_image')) {
            $product->update(['image' => null]);
        } elseif ($request->hasFile('image')) {
            $product->update(['image' => $request->file('image')->store('products', 'public')]);
        } elseif ($request->filled('cover_url')) {
            // Cover picked from the Studio library (absolute /storage/... URL).
            $product->update(['image' => $request->input('cover_url')]);
        }

        // Store freshly uploaded gallery files, keyed by submission index.
        $uploadPaths = [];
        if ($request->hasFile('gallery')) {
            foreach ($request->file('gallery') as $i => $file) {
                if ($file->isValid()) {
                    $uploadPaths[$i] = $file->store('products', 'public');
                }
            }
        }

        // SPA path: `gallery_managed=1` + `gallery_order[]` is the authoritative
        // FINAL order (existing paths / studio urls / `__upload__{i}` placeholders),
        // which lets the user drag-to-reorder and remove images freely.
        if ($request->boolean('gallery_managed')) {
            $gallery = [];
            foreach ((array) $request->input('gallery_order', []) as $token) {
                $token = (string) $token;
                if ($token === '') {
                    continue;
                }
                if (str_starts_with($token, '__upload__')) {
                    $i = (int) substr($token, strlen('__upload__'));
                    if (isset($uploadPaths[$i])) {
                        $gallery[] = $uploadPaths[$i];
                    }
                } else {
                    $gallery[] = $token;
                }
            }
            // Safety net: never drop an upload the client forgot to reference.
            foreach ($uploadPaths as $p) {
                if (! in_array($p, $gallery, true)) {
                    $gallery[] = $p;
                }
            }

            $product->update(['gallery' => array_values(array_unique(array_filter($gallery)))]);

            return;
        }

        // Legacy fallback (no gallery_managed): preserve + append.
        $gallery = array_values(array_filter((array) $product->gallery));
        $removed = array_values(array_filter((array) $request->input('gallery_remove', [])));
        if ($removed) {
            $gallery = array_values(array_diff($gallery, $removed));
        }
        foreach ($uploadPaths as $p) {
            $gallery[] = $p;
        }
        foreach (array_values(array_filter((array) $request->input('studio_gallery', []))) as $path) {
            if (is_string($path) && $path !== '' && ! in_array($path, $gallery, true)) {
                $gallery[] = $path;
            }
        }

        if (array_values((array) $product->gallery) !== $gallery) {
            $product->update(['gallery' => $gallery]);
        }
    }

    protected function syncVariants(Request $request, Product $product): void
    {
        // The SPA submits `sync_variants=1` so removing every row also clears
        // existing variants. Legacy form posts (no flag) keep the old behaviour:
        // only sync when at least one variant row is present.
        if (! $request->boolean('sync_variants') && ! $request->filled('variants')) {
            return;
        }

        $product->variants()->delete();

        foreach (array_filter($request->input('variants', [])) as $v) {
            if (empty($v['name'])) {
                continue;
            }

            $product->variants()->create([
                'name' => $v['name'],
                'sku' => $v['sku'] ?? null,
                'price' => ($v['price'] !== '' && $v['price'] !== null) ? $v['price'] : $product->price,
                'compare_price' => ($v['compare_price'] ?? '') !== '' ? $v['compare_price'] : null,
                'stock' => $v['stock'] ?? 0,
                'options' => $this->deriveOptions($v['name']) ?: null,
                'is_active' => true,
            ]);
        }
    }

    protected function deriveOptions(string $name): array
    {
        $parts = array_map('trim', explode('/', $name));

        return array_filter($parts, fn ($p) => $p !== '') ?: [];
    }
}
