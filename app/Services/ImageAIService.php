<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 2D image generation (Flux / Fal.ai / Replicate, or Wan / Qwen via DashScope).
 *
 * Stub: when no real provider key is configured we reuse a bundled, clean sample
 * fashion image so the flow looks real offline. For an edit (inpaint) we reuse the
 * source image itself so the result stays consistent with the selected item.
 */
class ImageAIService
{
    protected ?string $dashscopeError = null;
    /** Last HTTP status from an edit attempt (0 = exception). */
    protected int $dashscopeStatus = 0;

    /** Which provider/model actually produced the last successful image (may differ from the requested one). */
    protected ?string $lastProvider = null;
    protected ?string $lastModel = null;


    public function lastProvider(): ?string
    {
        return $this->lastProvider;
    }

    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    protected function falKey(): ?string
    {
        return studio_api_key('fal');
    }

    protected function provider(): string
    {
        return (string) studio_config('image_provider', 'flux');
    }

    protected function providerKey(): ?string
    {
        return match ($this->provider()) {
            'gemini' => studio_api_key('gemini'),
            'wan' => studio_api_key('wan') ?: studio_api_key('dashscope'),
            'qwen' => studio_api_key('qwen') ?: studio_api_key('dashscope'),
            default => studio_api_key('fal') ?: studio_api_key('replicate'),
        };
    }

    public function generate(string $prompt, ?string $baseImage = null, ?string $maskImage = null, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $providerOverride = null, ?string $modelOverride = null, ?string $negativePrompt = null, array $refImages = [], ?string $mode = null, ?int $seed = null): string
    {
        $dashscopeKey = studio_api_key('dashscope');

        // Tạo ẢNH MỚI từ ảnh tham chiếu (i2i — mode='refgen', card "Tạo ảnh mới từ ảnh mẫu"):
        // KHÔNG phải edit — model sinh ảnh (vd qwen-image-3.0-pro) nhận ảnh tham chiếu + prompt
        // và tạo một bức ảnh HOÀN TOÀN MỚI giống ảnh mẫu (không ép về kích thước nguồn, không mask).
        if ($mode === 'refgen' && $baseImage) {
            $refUrl = $this->generateFromReference($prompt, $baseImage, $providerOverride, $modelOverride, $faceRef);
            if ($refUrl) {
                return $refUrl;
            }
            throw new \RuntimeException($this->dashscopeError ?: 'Không tạo được ảnh mới từ ảnh tham chiếu.');
        }

        // Inpaint: when a source (base) image is supplied, use the dedicated Qwen image-edit model
        // WITH that image as input so the change applies to it (real editing), not a fresh text2image.
        if ($baseImage && (studio_api_key('qwen_edit') || $this->providerKey() || $dashscopeKey)) {
            // A requested model that is itself edit-capable (e.g. qwen-image-3.0-pro picked in the
            // "Sửa ảnh" card) wins over the configured Qwen Edit model; anything else (text/vision
            // models, unknown ids) keeps the configured edit model so editing never breaks.
            $configuredEdit = (string) studio_config('qwen_edit_model', 'qwen-image-edit');
            $editModel = ($modelOverride && $this->isImageEditCapableModel($modelOverride))
                ? $modelOverride
                : $configuredEdit;
            $triedModels = [$editModel];
            $edited = $this->editImage($prompt, $baseImage, $editModel, $faceRef, null, $maskImage, $refImages);
            // Model được chọn (vd qwen-image-3.0-pro) thất bại ở MỌI key (hết hạn mức, 403/404…) →
            // tự fallback sang model Qwen Edit cấu hình — đúng cơ chế "tự chuyển sang model kế tiếp"
            // của Tạo ảnh 2D. Hạn mức DashScope tính THEO MODEL nên model edit chuyên dụng
            // (qwen-image-edit…) thường vẫn còn hạn mức khi model sinh ảnh đã hết.
            if (! $edited && $editModel !== $configuredEdit && $this->isImageEditCapableModel($configuredEdit)) {
                logger()->info('Inpaint: selected edit model failed, falling back to configured Qwen Edit model', ['from' => $editModel, 'to' => $configuredEdit]);
                $triedModels[] = $configuredEdit;
                $edited = $this->editImage($prompt, $baseImage, $configuredEdit, $faceRef, null, $maskImage, $refImages);
            }
            if ($edited) {
                // Model edit đôi khi trả ảnh tỷ lệ/kích thước hơi khác ảnh gốc — chuẩn hóa
                // về ĐÚNG kích thước ảnh nguồn để kết quả khớp khung hình ban đầu.
                $edited = $this->fitToSourceSize($edited, $baseImage) ?: $edited;
                // "Gộp lại": edit theo vùng (có mask) → composite để phần NGOÀI mask lấy 100%
                // ảnh gốc, chỉ vùng TRONG mask lấy kết quả AI (biên hòa mượt) — phần còn lại
                // của ảnh không bao giờ bị model đổi nhẹ.
                if ($maskImage) {
                    // Fallback tái tạo nền cục bộ CHỈ cho XÓA ("REMOVAL") — Thay vùng không
                    // smear khi AI no-op (giữ nguyên để user biết AI chưa tạo).
                    $eraseFallback = str_contains($prompt, 'REMOVAL');
                    $edited = $this->compositeMaskedEdit($edited, $baseImage, $maskImage, $eraseFallback) ?: $edited;
                }
                return $edited;
            }
            // Inpaint must NOT silently fall through to text2image — that produces a brand-new image
            // instead of editing the source. Surface the real error so the user can fix the edit model.
            logger()->warning('Inpaint failed: edit model returned no result (no text2image fallback)', [
                'models_tried' => $triedModels,
                'err' => $this->dashscopeError,
            ]);
            $msg = $this->dashscopeError ?: 'Không thể chỉnh sửa ảnh (model edit không trả kết quả). Kiểm tra model “Qwen Edit” trong Cài đặt và khoá “Qwen Edit” trong Quản lý API.';
            if (count($triedModels) > 1 || ($triedModels[0] ?? '') !== $configuredEdit) {
                $msg .= ' (Đã thử: '.implode(' → ', $triedModels).')';
            }
            throw new \RuntimeException($msg);
        }

        // Unified, priority-driven model list: the requested (override) model first, then the default
        // settings model, then the registered models of the group by their saved priority. The key is
        // chosen by the same registered priority (never by key type). We call each in order until one
        // returns an image — this is the single mechanism for "Tạo Ảnh 2D", and it can never disagree
        // with the settings "check" because both use the same candidates list.
        $candidates = collect(studio_model_candidates('image'))->values();
        if ($providerOverride && $modelOverride) {
            $candidates = collect([['provider' => $providerOverride, 'model' => $modelOverride]])
                ->merge($candidates)
                ->unique(function ($c) {
                    return ($c['provider'] ?? '').':'.($c['model'] ?? '');
                })
                ->values();
        }

        $triedReal = false;
        foreach ($candidates as $c) {
            $provider = (string) ($c['provider'] ?? '');
            $model = (string) ($c['model'] ?? '');
            if (! $provider || ! $model) {
                continue;
            }

            // Try the candidate's keys in registered-priority order (ignoring key type); if the
            // top-priority key fails (e.g. a plan key routed to a host without this model), the next
            // key is tried before moving to the next candidate.
            $keys = studio_candidate_key($c, 'image');
            if (! $keys) {
                continue;
            }
            $triedReal = true;

            foreach ($keys as $key) {
                $url = $this->attemptProvider($provider, $model, $prompt, $key, $resolution, $ratio, $faceRef, $negativePrompt, $seed);
                if ($url) {
                    $this->lastProvider = $provider;
                    if (! $this->lastModel) {
                        $this->lastModel = $model;
                    }
                    return $url;
                }
            }
        }

        // A real provider was attempted but every one failed — surface the error rather than a stub.
        if ($triedReal) {
            throw new \RuntimeException($this->providerErrorMessage());
        }

        // No real key configured -> stub (reuse the source image for edits, sample otherwise).
        return $this->copySample($prompt, $baseImage);
    }

    /**
     * Call ONE (provider, model) candidate with its resolved key. Returns the image URL or null.
     */
    protected function attemptProvider(string $provider, string $model, string $prompt, string $key, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $negativePrompt = null, ?int $seed = null): ?string
    {
        if ($provider === 'gemini') {
            return $this->tryGeminiImage($prompt, $key, $resolution, $ratio, $model);
        }

        if (in_array($provider, ['qwen', 'wan', 'dashscope'], true)) {
            return $this->tryDashscope($prompt, $model, $key, $resolution, $ratio, $faceRef, $negativePrompt, $seed);
        }

        // User-declared custom provider routes (Settings → Custom Providers) — the profile
        // carries its own protocol + base URL + auth style. Built-ins never reach this branch,
        // so existing routing is untouched.
        $custom = function_exists('studio_custom_provider') ? studio_custom_provider($provider) : null;
        if ($custom) {
            return $this->tryCustomProvider($prompt, $model, $custom);
        }

        // 'fal' / 'replicate' are not wired into this service (no Fal client), so skip them.
        return null;
    }

    /**
     * Generate through one user-declared custom provider profile. Supports the two
     * image-capable wire protocols: dashscope (multimodal generation) and gemini
     * (generateContent). openai-protocol routes are chat-only and cannot return
     * images, so they are skipped for generation (still valid for text/inference).
     */
    protected function tryCustomProvider(string $prompt, string $model, array $provider): ?string
    {
        if (! in_array($provider['protocol'] ?? '', ['dashscope', 'gemini'], true)) {
            return null;
        }

        $extra = [];
        $parameters = [];
        if ($resolution) {
            // DashScope uses size like 1024x1024; approximate 1K/2K on the long edge.
            $size = $resolution === '2K' ? 2048 : 1024;
            $ratioMap = ['1:1' => [1, 1], '4:3' => [4, 3], '3:4' => [3, 4], '16:9' => [16, 9], '9:16' => [9, 16], '4:5' => [4, 5], '21:9' => [21, 9], '19:6' => [19, 6]];
            if ($ratio && isset($ratioMap[$ratio])) {
                [$rw, $rh] = $ratioMap[$ratio];
                $long = $size;
                $w = $rw >= $rh ? $long : (int) round($long * $rw / $rh);
                $h = $rw >= $rh ? (int) round($long * $rh / $rw) : $long;
                $parameters['size'] = $w.'x'.$h;
            }
        }
        if ($parameters) {
            $extra['parameters'] = $parameters;
        }

        $body = studio_custom_provider_call($provider, $model, $prompt, $extra);
        if (! is_array($body)) {
            return null;
        }

        $url = data_get($body, 'output.choices.0.message.images.0.url')
            ?: data_get($body, 'output.task_url')
            ?: data_get($body, 'candidates.0.content.parts.0.inlineData.data');

        // Gemini inline data → persist to storage and return the public URL.
        if ($url && ! str_starts_with((string) $url, 'http') && ! str_starts_with((string) $url, '/')) {
            $name = 'studio/gen-'.Str::uuid().'.png';
            \Illuminate\Support\Facades\Storage::disk('public')->put($name, base64_decode((string) $url, true) ?: '');
            return '/storage/'.$name;
        }

        return $url ? (string) $url : null;
    }

    protected function providerErrorMessage(): string
    {
        $msg = $this->dashscopeError ?: 'Không thể gọi nhà cung cấp AI. Kiểm tra key / model / độ phân giải trong Cài đặt.';
        $lower = strtolower((string) $msg);

        if (str_contains($lower, 'allocationquota') || str_contains($lower, 'throttling') || str_contains($msg, '(429)')) {
            $msg = 'Hạn mức tài khoản QwenCloud đã hết (Throttling.AllocationQuota). '
                .'Vào https://home.qwencloud.com → kiểm tra / gia hạn hạn mức — quota sẽ reset theo chu kỳ (resets theo thông báo).';
        } elseif (str_contains($lower, 'model not exist') || str_contains($lower, 'invalidparameter')
            || str_contains($lower, 'model_not_supported')) {
            $msg = 'Model ảnh không tồn tại trên host QwenCloud hiện tại. Token/Coding Plan có bộ model tạo ảnh riêng '
                .'(thường là wan2.7-image / happyhorse-1.1-t2v); Pay-As-You-Go dùng qwen-image-3.0-pro/qwen-image. '
                .'Đổi lại “Ảnh Qwen” / base URL trong Cài đặt cho đúng loại key.';
        } elseif (str_contains($lower, 'invalidapikey') || str_contains($msg, '(401)')) {
            $msg = 'Khoá API không hợp lệ (InvalidApiKey / 401). Với tạo ảnh & chỉnh sửa ảnh, hãy dùng key '
                .'Pay-As-You-Go (bắt đầu bằng sk-… hoặc sk-ws-…), KHÔNG dùng key Token/Coding Plan (sk-sp-…) vì gói plan '
                .'chỉ hỗ trợ model text/code. Tạo key mới tại Model Studio → API-KEY rồi nhập vào Quản lý API.';
        } elseif (str_contains($lower, 'unpurchased') || str_contains($lower, 'eligible')) {
            $msg = 'Khóa QwenCloud hợp lệ, nhưng model ảnh chưa được kích hoạt/mua trên tài khoản (AccessDenied.Unpurchased). '
                .'Vào https://home.qwencloud.com → Model Center → bật / mua một model Qwen-Image (Qwen-Image, Qwen-Image-Max, Qwen-Image-Plus, Qwen-Image-3.0). '
                .'Tài khoản này hiện chỉ có model giọng nói (ASR/TTS). Sau khi bật, chọn lại “Ảnh Qwen” trong Cài đặt.';
        }

        return $msg;
    }

    protected function tryGeminiImage(string $prompt, string $key, ?string $resolution = null, ?string $ratio = null, ?string $modelOverride = null): ?string
    {
        try {
            $url = $this->callGeminiImage($prompt, $key, $ratio, $modelOverride);
            if ($url) {
                $this->lastProvider = 'gemini';
                $this->lastModel = $modelOverride ?: (string) studio_config('gemini_image_model', 'gemini-2.5-flash-image');
                return $url;
            }
            $this->dashscopeError = 'Gemini không trả về ảnh. Kiểm tra model ảnh (Cài đặt → Ảnh Gemini) và hạn mức.';
        } catch (\Throwable $e) {
            $this->dashscopeError = $e->getMessage();
            logger()->error('Gemini image generation failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Gemini image generation via generateContent with responseModalities IMAGE.
     * The image is returned as a base64 inlineData part.
     */
    protected function callGeminiImage(string $prompt, string $key, ?string $ratio = null, ?string $modelOverride = null): ?string
    {
        $model = $modelOverride ?: (string) studio_config('gemini_image_model', 'gemini-2.5-flash-image');

        if (! $this->isGeminiImageModel($model)) {
            throw new \RuntimeException('Model “'.$model.'” không phải model tạo ảnh. Trong Cài đặt → “Ảnh Gemini”, hãy dùng gemini-2.5-flash-image (hoặc gemini-2.0-flash-preview-image-generation / imagen-4.0-generate-001).');
        }

        $config = ['responseModalities' => ['TEXT', 'IMAGE']];
        $aspect = $this->geminiAspectRatio($ratio);
        if ($aspect) {
            $config['imageConfig'] = ['aspectRatio' => $aspect];
        }

        $resp = $this->geminiGenerate($model, $prompt, $key, $config);

        // Some image models don't support the aspect-ratio config — retry without it.
        if (! $resp->successful() && isset($config['imageConfig'])
            && $resp->status() === 400 && str_contains(strtolower((string) $resp->body()), 'aspect ratio')) {
            unset($config['imageConfig']);
            $resp = $this->geminiGenerate($model, $prompt, $key, $config);
        }

        if (! $resp->successful()) {
            $msg = 'Gemini ('.$resp->status().'): '.Str::limit((string) $resp->body(), 240);
            if ($resp->status() === 404 || str_contains(strtolower((string) $resp->body()), 'not found')) {
                $msg .= ' — Model ảnh không đúng. Model Gemini hợp lệ: gemini-2.5-flash-image (hoặc gemini-2.0-flash-preview-image-generation / imagen-4.0-generate-001). Đổi trong Cài đặt → “Ảnh Gemini”.';
            }
            throw new \RuntimeException($msg);
        }

        $parts = collect(data_get($resp->json(), 'candidates.0.content.parts', []));

        foreach ($parts as $part) {
            $inline = $part['inlineData'] ?? null;
            if (! is_array($inline) || empty($inline['data'])) {
                continue;
            }

            $data = base64_decode((string) $inline['data'], true);
            if ($data === false) {
                continue;
            }

            $mime = $inline['mimeType'] ?? 'image/png';
            $ext = str_contains($mime, 'jpeg') ? 'jpg' : (str_contains($mime, 'webp') ? 'webp' : 'png');
            $name = Str::uuid().'.'.$ext;
            Storage::disk('public')->put('studio/'.$name, $data);

            return '/storage/studio/'.$name;
        }

        return null;
    }

    /**
     * Gemini image edit via generateContent with MULTIPLE parts (image + mask + text).
     * Gemini 2.5 Flash Image supports inpainting when given a base image + mask + instruction.
     * Returns the edited image URL or null.
     */
    protected function geminiImageEdit(string $prompt, string $imageUrl, string $maskUrl, string $key, ?string $modelOverride = null): ?string
    {
        try {
            $model = $modelOverride ?: (string) studio_config('gemini_image_model', 'gemini-2.5-flash-image');

            // Image: downscale + JPEG (chấp nhận được cho ảnh nền), strip data-URI prefix —
            // Gemini inline_data.data expects RAW base64, NOT a "data:image/...;base64," string.
            $raw = $this->resolveImageBinary($imageUrl);
            if (! $raw) {
                logger()->warning('Gemini edit: cannot read source image', ['url' => $imageUrl]);
                return null;
            }
            $tmpPath = storage_path('app/studio-gemini-src-'.Str::uuid().'.bin');
            @file_put_contents($tmpPath, $raw);
            [$imgB64, $imgMime] = $this->downscaleImageBase64($tmpPath, 1600);
            @unlink($tmpPath);
            if ($imgB64 === '') {
                logger()->warning('Gemini edit: image decode failed');
                return null;
            }

            // Mask: must stay LOSSLESS (binary black/white) → raw PNG bytes, no JPEG re-encode.
            $maskBytes = $this->resolveImageBinary($maskUrl);
            if (! $maskBytes) {
                logger()->warning('Gemini edit: cannot read mask image', ['url' => $maskUrl]);
                return null;
            }

            // Gemini mask convention is INVERTED vs our/Qwen's: WHITE = editable region,
            // BLACK = preserved. Our mask is BLACK=edit on WHITE=keep → invert pixel values.
            $maskImg = studio_image_decode($maskBytes);
            if (! $maskImg) {
                logger()->warning('Gemini edit: mask decode failed');
                return null;
            }

            // Base image dimensions AFTER downscale — Gemini requires the mask to be the SAME size
            // as the base image; if they differ (crop > 1600 shrank the base but not the mask),
            // resample the mask to match exactly.
            $baseCheck = studio_image_decode(base64_decode($imgB64, true));
            $bw = $baseCheck ? imagesx($baseCheck) : imagesx($maskImg);
            $bh = $baseCheck ? imagesy($baseCheck) : imagesy($maskImg);
            if ($baseCheck) { imagedestroy($baseCheck); }

            $mw = imagesx($maskImg); $mh = imagesy($maskImg);
            if ($mw !== $bw || $mh !== $bh) {
                $tmp = imagecreatetruecolor($bw, $bh);
                imagecopyresampled($tmp, $maskImg, 0, 0, 0, 0, $bw, $bh, $mw, $mh);
                imagedestroy($maskImg);
                $maskImg = $tmp;
                $mw = $bw; $mh = $bh;
            }

            $inv = imagecreatetruecolor($mw, $mh);
            for ($y = 0; $y < $mh; $y++) {
                for ($x = 0; $x < $mw; $x++) {
                    $c = imagecolorat($maskImg, $x, $y);
                    $lum = (($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF);
                    // Lum < 384 (~128 avg): dark → becomes white (editable). Else black (kept).
                    $v = $lum < 384 ? 255 : 0;
                    imagesetpixel($inv, $x, $y, imagecolorallocate($inv, $v, $v, $v));
                }
            }
            ob_start();
            imagepng($inv);
            $maskB64 = base64_encode((string) ob_get_clean());
            imagedestroy($maskImg); imagedestroy($inv);
            if ($maskB64 === '') {
                logger()->warning('Gemini edit: mask encode failed');
                return null;
            }

            // Text hướng dẫn rõ ràng cho Gemini: WHITE = chỉ được sửa vùng trắng.
            $gemPrompt = $prompt."\n\nA mask image is provided (same size as the base image). In the mask, the WHITE region is the ONLY area you may edit — change only the white region and keep every pixel outside it EXACTLY identical to the original image.";

            $parts = [
                ['inlineData' => ['mimeType' => $imgMime, 'data' => $imgB64]],
                ['inlineData' => ['mimeType' => 'image/png', 'data' => $maskB64]],
                ['text' => $gemPrompt],
            ];

            $resp = Http::withHeaders(['x-goog-api-key' => $key])->timeout(180)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
                ]);

            if (! $resp->successful()) {
                logger()->warning('Gemini edit failed', ['status' => $resp->status(), 'body' => Str::limit((string) $resp->body(), 400)]);
                return null;
            }

            $partsOut = collect(data_get($resp->json(), 'candidates.0.content.parts', []));
            foreach ($partsOut as $part) {
                $inline = $part['inlineData'] ?? null;
                if (! is_array($inline) || empty($inline['data'])) { continue; }
                $data = base64_decode((string) $inline['data'], true);
                if ($data === false) { continue; }
                $mime = $inline['mimeType'] ?? 'image/png';
                $ext = str_contains($mime, 'jpeg') ? 'jpg' : (str_contains($mime, 'webp') ? 'webp' : 'png');
                $name = Str::uuid().'.'.$ext;
                Storage::disk('public')->put('studio/'.$name, $data);
                return '/storage/studio/'.$name;
            }

            logger()->warning('Gemini edit: no image in response');
            return null;
        } catch (\Throwable $e) {
            logger()->warning('Gemini edit threw: '.$e->getMessage());
            return null;
        }
    }

    protected function geminiGenerate(string $model, string $prompt, string $key, array $config): Response
    {
        return Http::withHeaders(['x-goog-api-key' => $key])->timeout(180)
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => $config,
            ]);
    }

    protected function isGeminiImageModel(string $model): bool
    {
        return str_contains($model, 'image') || str_contains($model, 'imagen');
    }

    protected function geminiAspectRatio(?string $ratio): ?string
    {
        return match ($ratio) {
            '1:1', '3:4', '4:3', '9:16', '16:9', '21:9' => $ratio,
            '4:5' => '3:4',
            '19:6' => '21:9',
            default => null,
        };
    }

    protected function tryDashscope(string $prompt, string $model, string $key, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $negativePrompt = null, ?int $seed = null): ?string
    {
        try {
            $url = $this->callDashscope($prompt, $model, $key, $resolution, $ratio, $faceRef, $negativePrompt, $seed);
            if ($url) {
                $this->lastModel = $model;
                return $url;
            }
            $this->dashscopeError = 'Nhà cung cấp AI không trả về ảnh. Kiểm tra model / độ phân giải.';
        } catch (\Throwable $e) {
            $this->dashscopeError = $e->getMessage();
            capture_provider_quota_reset($e->getMessage());
            logger()->error('DashScope '.$model.' failed: '.$e->getMessage());
        }

        return null;
    }

    protected function copySample(string $prompt, ?string $preferred): string
    {
        $path = $this->resolveSamplePath($preferred);
        $contents = $path ? @file_get_contents($path) : null;
        $name = Str::uuid().'.jpg';

        Storage::disk('public')->put('studio/'.$name, $contents ?: $this->placeholder());

        return '/storage/studio/'.$name;
    }

    protected function resolveSamplePath(?string $preferred): ?string
    {
        if ($preferred && str_starts_with($preferred, '/storage/')) {
            // Containment chống traversal (/storage/../../.env → null) — S1.
            $p = studio_safe_public_file((string) parse_url($preferred, PHP_URL_PATH));
            if ($p) {
                return $p;
            }
        }

        $files = array_values(array_filter(glob(public_path('samples/2aOboQq*.jpg')) ?: [], 'is_file'));

        return $files ? $files[array_rand($files)] : null;
    }

    /**
     * QwenCloud keys are bound to a base URL by key type. A "sk-sp-…" key (Token /
     * Coding Plan) cannot be used on the classic pay-as-you-go DashScope host, so
     * auto-switch to the plan host unless the admin set a custom base URL.
     */
    protected function dashscopeBase(string $key): string
    {
        return dashscope_base_url($key);
    }

    protected function callDashscope(string $prompt, string $model, string $key, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $negativePrompt = null, ?int $seed = null): ?string
    {
        // qwen-image / qwen-image-plus are async-only (submit a task, then poll).
        if (in_array($model, ['qwen-image', 'qwen-image-plus'], true)) {
            return $this->callDashscopeAsync($prompt, $model, $key, $resolution, $ratio, $negativePrompt, $seed);
        }

        $base = $base = rtrim($this->dashscopeBase($key), '/').'/api/v1';
        $size = $this->sizeFor($resolution, $ratio);
        $resp = Http::withToken($key)
            ->timeout(180)
            ->post($base.'/services/aigc/multimodal-generation/generation', [
                'model' => $model,
                'input' => ['messages' => [['role' => 'user', 'content' => [['text' => $prompt]]]]],
                'parameters' => array_filter([
                    'negative_prompt' => $negativePrompt ?? '',
                    'prompt_extend' => true,
                    'watermark' => false,
                    'size' => $size,
                    'seed' => $seed,
                ], fn ($v) => $v !== null),
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('DashScope ('.$resp->status().'): '.Str::limit((string) $resp->body(), 240));
        }

        $url = collect(data_get($resp->json(), 'output.choices.0.message.content', []))
            ->pluck('image')->first();

        return $url ? $this->storeRemoteImage($url) : null;
    }

    protected function dashscopeContent(string $prompt, ?string $faceRef): array
    {
        $parts = [];
        // Face reference injection ("Khuôn mặt mẫu") was removed — generation no longer pins a face image.
        $parts[] = ['text' => $prompt];

        return $parts;
    }

    protected function callDashscopeAsync(string $prompt, string $model, string $key, ?string $resolution = null, ?string $ratio = null, ?string $negativePrompt = null, ?int $seed = null): ?string
    {
        $base = $base = rtrim($this->dashscopeBase($key), '/').'/api/v1';
        $size = $this->sizeFor($resolution, $ratio);

        $submit = Http::withToken($key)->withHeaders(['X-DashScope-Async' => 'enable'])->timeout(60)
            ->post($base.'/services/aigc/text2image/image-synthesis', [
                'model' => $model,
                'input' => ['prompt' => $prompt],
                'parameters' => array_filter(['negative_prompt' => $negativePrompt ?? '', 'size' => $size, 'n' => 1, 'prompt_extend' => true, 'watermark' => false, 'seed' => $seed], fn ($v) => $v !== null),
            ]);

        if (! $submit->successful()) {
            throw new \RuntimeException('DashScope ('.$submit->status().'): '.Str::limit((string) $submit->body(), 240));
        }

        $taskId = data_get($submit->json(), 'output.task_id');
        if (! $taskId) {
            throw new \RuntimeException('DashScope không trả về task_id.');
        }

        $deadline = microtime(true) + 180;

        while (microtime(true) < $deadline) {
            sleep(4);

            $q = Http::withToken($key)->timeout(30)->get($base.'/tasks/'.$taskId);

            if (! $q->successful()) {
                throw new \RuntimeException('DashScope ('.$q->status().'): '.Str::limit((string) $q->body(), 240));
            }

            $status = data_get($q->json(), 'output.task_status');

            if ($status === 'SUCCEEDED') {
                $url = data_get($q->json(), 'output.results.0.url');
                if (! $url) {
                    throw new \RuntimeException('DashScope hoàn tất nhưng không trả ảnh.');
                }

                return $this->storeRemoteImage($url);
            }

            if ($status === 'FAILED') {
                throw new \RuntimeException('DashScope: '.(string) data_get($q->json(), 'output.message', 'Tạo ảnh thất bại.'));
            }
        }

        throw new \RuntimeException('Hết thời gian chờ tạo ảnh (task '.$taskId.').');
    }

    /**
     * Re-edit a generated image so the model's face matches the reference face
     * (best-effort via the qwen-edit image model). Returns null on failure so the
     * original image is kept.
     *
     * Độ trung thực trang phục (tryon/swap): ảnh NGUỒN gửi tới model edit phải giữ được
     * vân vải/đường may/hoa tiết in. Trước đây hàm này cán mọi ảnh về 768px + JPEG Q85,
     * làm mờ/làm trơn chi tiết trước khi model kịp thấy — mâu thuẫn với prompt "preserve
     * EXACT colors, prints, patterns, fabric".
     *
     * Nay:
     *  - $max cao hơn (mặc định 2560 cho ảnh edit — Qwen Edit chấp nhận ảnh lớn), chỉ thu
     *    nhỏ thực sự khi cạnh dài vượt ngưỡng.
     *  - $quality JPEG nâng lên 95 (ảnh edit cần chi tiết; ảnh preview nhỏ vẫn dùng 85).
     *  - $keepPng: nếu nguồn là PNG (có chi tiết sắc nét / trong suốt), giữ NGUYÊN định
     *    dạng PNG lossless thay vì ép JPEG (nén mất dữ liệu, sai lệch màu/vân).
     */
    protected function downscaleImageBase64(string $path, int $max = 768, int $quality = 85, bool $keepPng = false): array
    {
        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return ['', 'image/jpeg'];
        }
        $isPng = str_starts_with(strtolower((string) mime_content_type($path) ?: ''), 'image/png')
            || in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), ['png'], true);

        // Ảnh PNG + keepPng: chỉ resample (nếu quá lớn) rồi giữ PNG lossless — KHÔNG ép JPEG,
        // preserves alpha + chi tiết sắc nét (ren, thêu, đính đá, họa tiết in nhỏ).
        if ($keepPng && $isPng) {
            $img = studio_image_decode($raw);
            if (! $img) {
                return ['', 'image/png'];
            }
            $w = imagesx($img);
            $h = imagesy($img);
            if ($w > $max || $h > $max) {
                $scale = min($max / $w, $max / $h);
                $nw = max(1, (int) ($w * $scale));
                $nh = max(1, (int) ($h * $scale));
                $tmp = imagecreatetruecolor($nw, $nh);
                imagealphablending($tmp, false);
                imagesavealpha($tmp, true);
                imagecopyresampled($tmp, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $tmp;
            }
            ob_start();
            imagepng($img);
            $data = (string) ob_get_clean();
            imagedestroy($img);

            return [base64_encode($data), 'image/png'];
        }

        $img = studio_image_decode($raw);
        if (! $img) {
            return ['', 'image/jpeg'];
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > $max || $h > $max) {
            $scale = min($max / $w, $max / $h);
            $nw = max(1, (int) ($w * $scale));
            $nh = max(1, (int) ($h * $scale));
            $tmp = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($tmp, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $tmp;
        }
        ob_start();
        imagejpeg($img, null, $quality);
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return [base64_encode($data), 'image/jpeg'];
    }

    /**
     * Inpaint using the Qwen image-edit model with the source image as input.
     * Returns null on failure so the caller falls back to normal generation.
     */
    /**
     * Return the source image as a base64 data URI so the edit model is guaranteed to receive it
     * (a URL the provider can't fetch makes the model fall back to text2image -> creates a new image).
     *
     * Độ trung thực: ảnh NGUỒN (base/pose ref) đi qua đây phải giữ chi tiết cao — dùng ngưỡng
     * 2560px + giữ PNG lossless + JPEG Q95. $forEdit=true cho mọi ảnh gửi tới model edit
     * (tryon/swap/inpaint); $forEdit=false cho ảnh preview/thumbnail nhỏ.
     */
    protected function imageDataUri(string $url, bool $forEdit = false): ?string
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        // URL route /studio/image/{path} (studio_image_url) → {path} nằm trong storage/app/public
        if (str_starts_with($path, 'studio/image/')) {
            $path = substr($path, strlen('studio/image/'));
        }
        // Containment chống traversal — chỉ đọc file trong public/ hoặc storage/app/public (S1).
        $file = studio_safe_public_file($path);
        if (! $file) {
            return null;
        }
        // Ảnh nguồn edit: ngưỡng 2560 (không 1600), JPEG Q95 (không 85), giữ PNG lossless.
        // Ảnh preview/vision-QA nhỏ: giữ ngưỡng 1600 cũ, JPEG Q85 (rẻ, nhanh).
        if ($forEdit) {
            $max = (int) studio_config('edit_source_max', 2560);
            [$b64, $mime] = $this->downscaleImageBase64($file, $max, 95, true);
        } else {
            [$b64, $mime] = $this->downscaleImageBase64($file, 1600);
        }
        if ($b64 === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.$b64;
    }

    protected function editImage(string $prompt, string $imageUrl, ?string $modelOverride = null, ?string $faceRefUrl = null, ?string $poseRefUrl = null, ?string $maskImage = null, array $refImages = []): ?string
    {
        $model = $modelOverride ?: (string) studio_config('qwen_edit_model', 'qwen-image-edit');
        if (! $this->isImageEditCapableModel($model)) {
            $this->dashscopeError = 'Model “'.$model.'” có vẻ KHÔNG phải model chỉnh sửa ảnh. '
                .'Chọn model Qwen Edit chuyên dụng (vd: qwen-image-edit, qwen-image-edit-plus, qwen-image-3.0-pro…) trong Cài đặt — qwen3.8-flash/max là model chat đa phương thức (đọc ảnh/video) chứ KHÔNG sinh/chỉnh sửa ảnh, nên không dùng được ở đây.';
            logger()->warning('Edit model không phải model edit', ['model' => $model]);
            return null;
        }
        $source = $this->imageDataUri($imageUrl, true);
        if (! $source) {
            logger()->warning('Edit: cannot read source image', ['url' => $imageUrl]);
            return null;
        }
        $faceRef = $faceRefUrl ? $this->imageDataUri($faceRefUrl, true) : null;
        $poseRef = $poseRefUrl ? $this->imageDataUri($poseRefUrl, true) : null;
        // Region edit (xóa/thay vùng chọn): mask image kèm theo, vùng ĐEN = nơi được chỉnh sửa.
        if ($maskImage) {
            $prompt .= ' A mask image is provided (last image, same size as the base): its BLACK region is the exact area to edit — change ONLY that black region and keep every pixel outside it identical to the original image.';
        }

        // Edit (Inpaint) prioritises the Pay-As-You-Go credential (edit models usually live on the pay-go
        // host), then falls back to Token Plan — via studio_qwen_credentials('edit'). Pass the model so
        // keys scoped to this exact model id also qualify.
        $keys = studio_qwen_credentials('edit', $model);

        $last = null;
        foreach ($keys as $key) {
            $base = dashscope_base_url($key).'/api/v1';
            logger()->info('Edit attempt', ['model' => $model, 'key_prefix' => substr($key, 0, 8), 'base' => $base, 'face_ref' => (bool) $faceRef, 'pose_ref' => (bool) $poseRef]);

            // Content: optional reference images FIRST (face, then pose), then the design image to edit,
            // then the instruction. The prompt names which image is which.
            $content = [];
            if ($faceRef) { $content[] = ['image' => $faceRef]; }
            if ($poseRef) { $content[] = ['image' => $poseRef]; }
            // Compose (ghép nhiều ảnh): các ảnh tham chiếu bổ sung được thêm trước ảnh gốc.
            foreach ($refImages as $refUrl) {
                $ref = $this->imageDataUri((string) $refUrl, true);
                if ($ref) { $content[] = ['image' => $ref]; }
            }
            $content[] = ['image' => $source];
            if ($maskImage) {
                $maskUri = $this->imageDataUri($maskImage);
                if ($maskUri) { $content[] = ['image' => $maskUri]; }
            }
            $content[] = ['text' => $prompt];

            $editUrl = $this->postMultimodalEdit($model, $base, $key, $content);
            if ($editUrl) {
                $this->lastModel = $model;
                logger()->info('Edit succeeded', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                return $this->storeRemoteImage($editUrl);
            }

            // Compose refs (ghép nhiều ảnh): model không nhận nhiều ảnh → thử chỉ còn ảnh gốc.
            if ($refImages && $this->editModelRejectsMultiImage()) {
                logger()->info('Edit retry without compose refs', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                $retry = [['image' => $source]];
                if ($maskImage) {
                    $maskUri = $this->imageDataUri($maskImage);
                    if ($maskUri) { $retry[] = ['image' => $maskUri]; }
                }
                $retry[] = ['text' => $prompt];
                $editUrl = $this->postMultimodalEdit($model, $base, $key, $retry);
                if ($editUrl) {
                    $this->lastModel = $model;
                    logger()->info('Edit succeeded (no compose refs)', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                    return $this->storeRemoteImage($editUrl);
                }
            }

            // The image-edit model may not accept multiple reference images -> retry with fewer
            // references (first keep the face only, then drop all refs) so the swap still works.
            if (($faceRef || $poseRef) && $this->editModelRejectsMultiImage()) {
                if ($faceRef) {
                    logger()->info('Edit retry without pose ref', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                    $retry = [['image' => $faceRef], ['image' => $source], ['text' => $prompt]];
                    $editUrl = $this->postMultimodalEdit($model, $base, $key, $retry);
                    if ($editUrl) {
                        $this->lastModel = $model;
                        logger()->info('Edit succeeded (no pose ref)', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                        return $this->storeRemoteImage($editUrl);
                    }
                }
                if ($this->editModelRejectsMultiImage()) {
                    logger()->info('Edit retry without refs', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                    $editUrl = $this->postMultimodalEdit($model, $base, $key, [['image' => $source], ['text' => $prompt]]);
                    if ($editUrl) {
                        $this->lastModel = $model;
                        logger()->info('Edit succeeded (no refs)', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                        return $this->storeRemoteImage($editUrl);
                    }
                }
            }

            $last = $this->dashscopeError;
            $status = $this->dashscopeStatus;
            if ($status === 404) {
                $this->dashscopeError = 'Model “'.$model.'” không tồn tại trên host '.$base.' (404). '
                    .'Model Qwen-Edit thường CHỈ khả dụng trên host Pay-As-You-Go (key sk-…/sk-ws-…); host '
                    .'Token/Coding Plan (key sk-sp-…) thường không có model chỉnh sửa ảnh (chỉ có model tạo ảnh/văn bản). '
                    .'Dùng key Pay-As-You-Go cho Inpaint (Quản lý API → “Qwen Edit”), hoặc chọn model edit có trên gói.';
                break;
            }
            if ($status === 403) {
                $this->dashscopeError = 'Model “'.$model.'” chưa được mua/kích hoạt trên tài khoản (403 AccessDenied.Unpurchased). '
                    .'Bật/mua model Qwen-Image-Edit (vd qwen-image-edit, qwen-image-edit-plus) trong QwenCloud Model Center, '
                    .'hoặc dùng Gemini. Sau khi bật, chọn lại “Qwen Edit” trong Cài đặt.';
                break;
            }
            if ($status === 401) {
                continue; // invalid key -> try the next one
            }
            // Hết hạn mức / bị throttle trên key NÀY (429, Throttling.AllocationQuota, FreeTierOnly):
            // quota là theo TÀI KHOẢN — key khác (tài khoản khác) trong chuỗi có thể còn hạn mức,
            // giống cơ chế tạo ảnh 2D vẫn thử lần lượt mọi key. Chỉ dừng khi KHÔNG phải lỗi quota.
            if ($status === 429 || is_qwen_quota_error($last)) {
                logger()->warning('Edit quota/rate-limit on key, trying next key', ['model' => $model, 'status' => $status, 'key_prefix' => substr($key, 0, 8), 'err' => $last]);
                continue;
            }
            break; // lỗi host/model-level khác -> không đập các key còn lại
        }

        // Qwen Edit failed across all keys — try Gemini edit as fallback (multi-provider chain).
        // Also translate common billing/account errors into a friendly Vietnamese message so the
        // raw JSON (e.g. Arrearage/overdue payment) never leaks to the user.
        $lowErr = strtolower((string) $last);
        if (str_contains($lowErr, 'arrearage') || str_contains($lowErr, 'overdue')) {
            $last = $this->dashscopeError = 'Tài khoản QwenCloud hết hạn thanh toán (Arrearage) — nạp tiền/thanh toán công nợ tại https://home.qwencloud.com rồi thử lại.';
        } elseif (str_contains($lowErr, 'quota') || str_contains($lowErr, 'throttling')) {
            $last = $this->dashscopeError = 'Hạn mức tài khoản QwenCloud đã hết (Throttling/Quota) cho model “'.$model.'”. '
                .'Vào https://home.qwencloud.com gia hạn hạn mức rồi thử lại. '
                .'Lưu ý: hạn mức tính THEO MODEL — nếu đang dùng model sinh ảnh (vd qwen-image-3.0-pro) để chỉnh sửa, '
                .'hãy chọn model edit chuyên dụng khác trong card Sửa ảnh (vd qwen-image-edit, qwen-image-edit-plus).';
        }
        if ($maskImage && ($geminiKey = studio_api_key('gemini'))) {
            logger()->info('Edit: Qwen failed, falling back to Gemini edit');
            $geminiResult = $this->geminiImageEdit($prompt, $imageUrl, $maskImage, $geminiKey);
            if ($geminiResult) {
                $this->lastModel = 'gemini';
                $this->lastProvider = 'gemini';
                // geminiImageEdit already stored the result locally (/storage/...) — pass it through
                // directly; storeRemoteImage only works for http(s) URLs.
                return str_starts_with($geminiResult, '/storage/') ? $geminiResult : $this->storeRemoteImage($geminiResult);
            }
        }

        logger()->warning('Edit model ultimately failed', ['model' => $model, 'err' => $last]);

        return null;
    }

    /**
     * Tạo ẢNH MỚI từ ảnh tham chiếu (i2i — card "Tạo ảnh mới từ ảnh mẫu", mode='refgen').
     * Khác edit: KHÔNG ép kích thước ảnh nguồn, KHÔNG mask/composite — model sinh ảnh
     * (vd qwen-image-3.0-pro) nhận ảnh tham chiếu + prompt và tạo một bức ảnh mới giống mẫu.
     * Ưu tiên đúng model người dùng chọn (qwen-image-3.0-pro), fallback theo candidates image.
     */
    protected function generateFromReference(string $prompt, string $imageUrl, ?string $providerOverride = null, ?string $modelOverride = null, ?string $faceRefUrl = null): ?string
    {
        $source = $this->imageDataUri($imageUrl);
        if (! $source) {
            logger()->warning('RefGen: cannot read source image', ['url' => $imageUrl]);

            return null;
        }

        // Kế thừa khuôn mặt mẫu (FacePreset): nếu có ảnh khuôn mặt, gửi nó làm ảnh tham chiếu ĐẦU TIÊN
        // (trước ảnh trang phục) để model sinh ảnh tạo khuôn mặt người mẫu giống mẫu đã chọn.
        $faceUri = $faceRefUrl ? $this->imageDataUri($faceRefUrl) : null;

        $candidates = collect(studio_model_candidates('image'))->values();
        if ($providerOverride && $modelOverride) {
            $candidates = collect([['provider' => $providerOverride, 'model' => $modelOverride]])
                ->merge($candidates)
                ->unique(fn ($c) => ($c['provider'] ?? '').':'.($c['model'] ?? ''))
                ->values();
        }

        $last = null;
        foreach ($candidates as $c) {
            $provider = (string) ($c['provider'] ?? '');
            $model = (string) ($c['model'] ?? '');
            if (! in_array($provider, ['qwen', 'wan', 'dashscope'], true)) {
                continue; // nhánh i2i chỉ hỗ trợ DashScope-family (qwen-image-3.0-pro…)
            }

            foreach (studio_candidate_key($c, 'image') as $key) {
                $base = dashscope_base_url($key).'/api/v1';
                logger()->info('RefGen attempt', ['model' => $model, 'key_prefix' => substr($key, 0, 8), 'base' => $base, 'face_ref' => (bool) $faceUri]);

                // Content: face ref (nếu có) trước, rồi ảnh trang phục (source), rồi prompt.
                $content = [];
                if ($faceUri) { $content[] = ['image' => $faceUri]; }
                $content[] = ['image' => $source];
                $content[] = ['text' => $prompt];

                $url = $this->postMultimodalEdit($model, $base, $key, $content);
                if ($url) {
                    $this->lastProvider = $provider;
                    $this->lastModel = $model;
                    logger()->info('RefGen succeeded', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);

                    return $this->storeRemoteImage($url);
                }

                $last = $this->dashscopeError;
                $status = $this->dashscopeStatus;
                if ($status === 429 || is_qwen_quota_error($last)) {
                    continue; // quota/throttle → thử key kế tiếp
                }
                if ($status === 401) {
                    continue; // key lỗi → thử key kế tiếp
                }
                // Model không nhận nhiều ảnh (face+source) → thử lại chỉ với ảnh trang phục.
                if ($faceUri && $this->editModelRejectsMultiImage()) {
                    logger()->info('RefGen retry without face ref', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                    $url = $this->postMultimodalEdit($model, $base, $key, [['image' => $source], ['text' => $prompt]]);
                    if ($url) {
                        $this->lastProvider = $provider;
                        $this->lastModel = $model;
                        logger()->info('RefGen succeeded (no face ref)', ['model' => $model, 'key_prefix' => substr($key, 0, 8)]);
                        return $this->storeRemoteImage($url);
                    }
                }
                break; // 404/403/khác → không đập các key còn lại
            }
        }

        $this->dashscopeError = $this->dashscopeError ?: ($last ?: 'Không tạo được ảnh mới từ ảnh tham chiếu.');

        return null;
    }

    /** POST a multimodal image-edit request. Returns the image URL or null (status/error kept). */
    protected function postMultimodalEdit(string $model, string $base, string $key, array $content): ?string
    {
        try {
            $resp = Http::withToken($key)->timeout(240)
                ->post($base.'/services/aigc/multimodal-generation/generation', [
                    'model' => $model,
                    'input' => ['messages' => [['role' => 'user', 'content' => $content]]],
                    'parameters' => ['watermark' => false],
                ]);

            $this->dashscopeStatus = $resp->status();
            if ($resp->successful()) {
                $editUrl = collect(data_get($resp->json(), 'output.choices.0.message.content', []))
                    ->pluck('image')->first();
                if ($editUrl) {
                    return $editUrl;
                }
                $this->dashscopeError = 'model returned no image in content';
                return null;
            }
            $body = Str::limit((string) $resp->body(), 240);
            $this->dashscopeError = 'HTTP '.$resp->status().': '.$body;
            logger()->warning('Edit model failed', ['model' => $model, 'status' => $resp->status(), 'body' => $body, 'key_prefix' => substr($key, 0, 8)]);
            return null;
        } catch (\Throwable $e) {
            $this->dashscopeStatus = 0;
            $this->dashscopeError = $e->getMessage();
            logger()->warning('Edit model threw', ['model' => $model, 'error' => $this->dashscopeError, 'key_prefix' => substr($key, 0, 8)]);
            return null;
        }
    }

    /** Whether the last edit failure looks like "too many / unsupported reference images". */
    protected function editModelRejectsMultiImage(): bool
    {
        $lower = strtolower((string) $this->dashscopeError);
        return $this->dashscopeStatus === 400
            || str_contains($lower, 'not support') || str_contains($lower, 'unsupported')
            || str_contains($lower, 'invalidparameter') || str_contains($lower, 'only one')
            || str_contains($lower, 'number of image') || str_contains($lower, 'too many');
    }

    /**
     * Whether a model is an image-edit / image-generation model (so we don't send image content to a
     * text/vision model). Allows Qwen image models (qwen-image-3.0-pro etc.) which also do editing.
     * Public so controllers building per-request edit-model options use the exact same gate.
     */
    public function isImageEditCapableModel(string $model): bool
    {
        $m = strtolower($model);
        return str_contains($m, 'edit') || str_contains($m, 'qwen-image') || str_contains($m, 'imagen')
            || str_contains($m, 'wanx') || str_contains($m, 'imageedit') || str_contains($m, '-i2v');
    }

    /**
     * CHỈ chấp nhận model EDIT (qwen-image-edit*) cho swap — KHÔNG dùng model sinh ảnh
     * (qwen-image-3.0-pro, qwen-image, wanx…) vì chúng 403 "AllocationQuota.FreeTierOnly" và chỉ
     * làm chậm swap (mỗi lần thử mất thêm 1 request thất bại) trong khi model edit kế tiếp mới chạy.
     */
    protected function isSwapEditModel(string $model): bool
    {
        return str_contains(strtolower($model), 'edit');
    }

    /**
     * Edit an image with a SPECIFIC model (used by "Thay Đổi Người Mẫu" swap). Retries a couple of
     * times on a 429 rate-limit so a busy model still produces a result.
     */
    public function swapEdit(string $prompt, string $imageUrl, ?string $modelOverride = null, ?string $faceRefUrl = null, ?string $poseRefUrl = null): ?string
    {
        // Multi-image fusion model (face + garment + pose in one call) only when explicitly configured
        // via studio.swap_fusion_model — on this account qwen-image-3.0-pro 403s (free quota exhausted),
        // so it must NOT be attempted blindly. The configured swap model is also filtered to only edit
        // capable models, so a wrong swap_model config no longer wastes an attempt.
        $fusion = (string) studio_config('swap_fusion_model', '');
        $models = array_values(array_unique(array_filter([
            $fusion ?: null,
            $modelOverride, 'qwen-image-edit-max', 'qwen-image-edit-plus', 'qwen-image-edit',
        ], fn ($m) => $m !== null && $m !== '' && $this->isSwapEditModel((string) $m))));

        // Rate-limits (429) can take ~10-30s to clear: retry the SAME model with a short bounded
        // backoff (3s then 8s), then move to the next model on other errors (or after giving up).
        $backoffs = [3, 8];
        foreach ($models as $model) {
            for ($attempt = 0; $attempt <= count($backoffs); $attempt++) {
                $url = $this->editImage($prompt, $imageUrl, $model, $faceRefUrl, $poseRefUrl);
                if ($url) {
                    logger()->info('Swap edit succeeded', ['model' => $model]);
                    return $url;
                }
                $rateLimited = str_contains(strtolower((string) $this->dashscopeError), '429')
                    || str_contains(strtolower((string) $this->dashscopeError), 'ratelimit');
                if (! $rateLimited) {
                    // Non-rate-limit error -> try the next model (e.g. model not available / not supported).
                    logger()->warning('Swap edit model failed, trying next', ['model' => $model, 'err' => $this->dashscopeError]);
                    break;
                }
                if ($attempt >= count($backoffs)) {
                    logger()->warning('swapEdit gave up on '.$model.' after repeated rate-limits', ['err' => $this->dashscopeError]);
                    break;
                }
                $wait = $backoffs[$attempt];
                logger()->warning('swapEdit rate-limited on '.$model.', backing off '.$wait.'s (attempt '.($attempt + 1).')');
                sleep($wait);
            }
        }
        return null;
    }

    /**
     * Dedicated face-swap pass: replace ONLY the face of the person in $imageUrl with the face from
     * $faceRefUrl. Kept separate from the try-on pass because a single combined call makes the edit
     * model ignore the face reference — with face-only as the sole instruction it actually applies.
     */
    public function swapFace(string $prompt, string $imageUrl, ?string $modelOverride = null, ?string $faceRefUrl = null): ?string
    {
        $models = array_values(array_unique(array_filter([
            $modelOverride, 'qwen-image-edit-max', 'qwen-image-edit-plus', 'qwen-image-edit',
        ], fn ($m) => $m !== null && $m !== '' && $this->isSwapEditModel((string) $m))));

        $backoffs = [3, 8];
        foreach ($models as $model) {
            for ($attempt = 0; $attempt <= count($backoffs); $attempt++) {
                $url = $this->editImage($prompt, $imageUrl, $model, $faceRefUrl);
                if ($url) {
                    logger()->info('Face-swap succeeded', ['model' => $model]);
                    return $url;
                }
                $rateLimited = str_contains(strtolower((string) $this->dashscopeError), '429')
                    || str_contains(strtolower((string) $this->dashscopeError), 'ratelimit');
                if (! $rateLimited) {
                    logger()->warning('Face-swap model failed, trying next', ['model' => $model, 'err' => $this->dashscopeError]);
                    break;
                }
                if ($attempt >= count($backoffs)) {
                    logger()->warning('Face-swap gave up after repeated rate-limits', ['model' => $model]);
                    break;
                }
                sleep($backoffs[$attempt]);
            }
        }
        return null;
    }

    protected function storeRemoteImage(string $url, ?bool $postprocess = null): ?string
    {
        // SSRF guard (S3): URL đến từ response của provider (hoặc host admin tự cấu hình)
        // — chỉ http/https, timeout rõ ràng, giới hạn redirect + kích thước, tùy chọn
        // allowlist host qua setting studio 'remote_image_hosts' (CSV, rỗng = không chặn).
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $allowedHosts = array_filter(array_map('trim', explode(',', (string) studio_config('remote_image_hosts', ''))));
        if ($allowedHosts) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $hostOk = false;
            foreach ($allowedHosts as $allowed) {
                $allowed = strtolower(ltrim($allowed, '.'));
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) { $hostOk = true; break; }
            }
            if (! $hostOk) {
                return null;
            }
        }
        try {
            $res = Http::timeout(30)
                ->withOptions(['connect_timeout' => 10, 'allow_redirects' => ['max' => 2, 'protocols' => ['http', 'https']]])
                ->get($url);
            if (! $res->successful()) {
                return null;
            }
            $contents = $res->body();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        if (! $contents || strlen($contents) > 52428800) { // cap 50 MiB
            return null;
        }

        // Độ trung thực: kết quả edit từ DashScope/Qwen đã sắc nét đúng mức model tạo ra. Trước đây
        // MỌI ảnh đi qua unsharp mask 0.22 (vòng lặp pixel-by-pixel thủ công) + re-encode PNG qua GD,
        // gây (1) chậm trên ảnh lớn, (2) halo khô quanh edge sắc, (3) lệch nhẹ màu do GD re-sample.
        // Cờ studio.edit_postprocess (mặc định FALSE) cho phép bật lại sharpen cũ khi muốn;
        // mặc định giờ lưu raw bytes thẳng từ API — giữ đúng từng pixel model trả về.
        if ($postprocess === null) {
            $postprocess = (bool) studio_config('edit_postprocess', false);
        }

        $bytes = $contents;
        if ($postprocess) {
            // Tăng nét cuối (unsharp mask nhẹ 0.22) + chuẩn hóa PNG LOSSLESS — cải thiện chất lượng
            // đầu ra, không blur/noise (chỉ sharpen chi tiết). Bỏ qua nếu ảnh quá lớn hoặc không đọc được.
            $img = studio_image_decode($contents);
            if ($img) {
                $w = imagesx($img); $h = imagesy($img);
                if ($w * $h <= 24000000 && function_exists('imagefilter')) {
                    $blur = imagecreatetruecolor($w, $h);
                    imagecopy($blur, $img, 0, 0, 0, 0, $w, $h);
                    @imagefilter($blur, IMG_FILTER_GAUSSIAN_BLUR);
                    $amt = 0.22;
                    for ($y = 0; $y < $h; $y++) {
                        for ($x = 0; $x < $w; $x++) {
                            $c = imagecolorat($img, $x, $y); $b = imagecolorat($blur, $x, $y);
                            $cr = ($c >> 16) & 0xFF; $cg = ($c >> 8) & 0xFF; $cb = $c & 0xFF;
                            $br = ($b >> 16) & 0xFF; $bg = ($b >> 8) & 0xFF; $bb = $b & 0xFF;
                            imagesetpixel($img, $x, $y, imagecolorallocate($img,
                                (int) max(0, min(255, $cr + $amt * ($cr - $br))),
                                (int) max(0, min(255, $cg + $amt * ($cg - $bg))),
                                (int) max(0, min(255, $cb + $amt * ($cb - $bb)))));
                        }
                    }
                    imagedestroy($blur);
                }
                ob_start(); imagepng($img); $bytes = (string) ob_get_clean();
                imagedestroy($img);
            }
        }

        // Lưu nguyên định dạng model trả về (thường PNG/JPEG từ DashScope). Ưu tiên giữ đúng
        // extension theo content-type để trình duyệt/cache xử lý đúng; fallback .png cho text2image cũ.
        $ext = 'png';
        $sniff = substr($contents, 0, 3);
        if ($sniff === "\xFF\xD8\xFF") { $ext = 'jpg'; }
        elseif (str_starts_with($contents, "\x89PNG")) { $ext = 'png'; }
        $name = Str::uuid().'.'.$ext;
        Storage::disk('public')->put('studio/'.$name, $bytes);

        return '/storage/studio/'.$name;
    }

    /**
     * Đảm bảo ảnh kết quả edit có ĐÚNG kích thước (w×h) của ảnh nguồn — model edit đôi khi
     * trả tỷ lệ khác nhẹ. Resample về đúng kích thước nguồn; trả về URL mới hoặc URL cũ nếu
     * kích thước đã khớp / thất bại.
     */
    protected function fitToSourceSize(string $editedUrl, string $sourceUrl): ?string
    {
        try {
            $edited = studio_image_decode((string) $this->resolveImageBinary($editedUrl));
            $source = studio_image_decode((string) $this->resolveImageBinary($sourceUrl));
            if (! $edited || ! $source) {
                if ($edited) { imagedestroy($edited); }
                if ($source) { imagedestroy($source); }
                return null;
            }
            $sw = imagesx($source); $sh = imagesy($source);
            $ew = imagesx($edited); $eh = imagesy($edited);
            if ($ew === $sw && $eh === $sh) {
                imagedestroy($edited); imagedestroy($source);
                return $editedUrl;
            }
            $out = imagecreatetruecolor($sw, $sh);
            // Nếu tỉ lệ chỉ chênh nhỏ (< 1.5%) → STRETCH nhẹ (giữ toàn bộ ảnh, 1 lần resample,
            // không phóng to + crop gây nhòe/mất chi tiết). Chênh lớn mới cover-crop để không méo.
            $ratioDiff = abs(($ew / $eh) - ($sw / $sh)) / ($sw / $sh);
            if ($ratioDiff < 0.015) {
                imagecopyresampled($out, $edited, 0, 0, 0, 0, $sw, $sh, $ew, $eh);
            } else {
                // COVER-CROP: scale edited phủ kín ảnh gốc (giữ tỷ lệ) rồi crop giữa.
                $scale = max($sw / $ew, $sh / $eh);
                $cw = (int) round($ew * $scale); $ch = (int) round($eh * $scale);
                $tmp = imagecreatetruecolor($cw, $ch);
                imagecopyresampled($tmp, $edited, 0, 0, 0, 0, $cw, $ch, $ew, $eh);
                imagecopy($out, $tmp, 0, 0, (int) (($cw - $sw) / 2), (int) (($ch - $sh) / 2), $sw, $sh);
                imagedestroy($tmp);
            }
            imagedestroy($edited); imagedestroy($source);
            ob_start(); imagepng($out); $bytes = (string) ob_get_clean();
            imagedestroy($out);
            $name = 'studio/fit-'.Str::uuid().'.png';
            Storage::disk('public')->put($name, $bytes);
            return '/storage/'.$name;
        } catch (\Throwable $e) {
            logger()->warning('fitToSourceSize failed: '.$e->getMessage());
            return null;
        }
    }

    /**
     * "Gộp lại" (merge) — composite theo mask: kết quả cuối = ẢNH GỐC ở mọi pixel NGOÀI vùng
     * mask, chỉ lấy kết quả AI TRONG vùng mask. Mask được blur để biên hòa trộn mượt.
     * Đây là bước khép kín chuỗi "tách nền → xóa vật thể → gộp lại": phần ngoài vùng chọn
     * luôn giữ nguyên 100%, không bị model edit làm đổi nhẹ.
     */
    protected function compositeMaskedEdit(string $editedUrl, string $sourceUrl, string $maskUrl, bool $eraseFallback = false): ?string
    {
        try {
            $edited = studio_image_decode((string) $this->resolveImageBinary($editedUrl));
            $source = studio_image_decode((string) $this->resolveImageBinary($sourceUrl));
            $mask = studio_image_decode((string) $this->resolveImageBinary($maskUrl));
            if (! $edited || ! $source || ! $mask) {
                if ($edited) { imagedestroy($edited); }
                if ($source) { imagedestroy($source); }
                if ($mask) { imagedestroy($mask); }
                return null;
            }
            $w = imagesx($source); $h = imagesy($source);
            if (imagesx($edited) !== $w || imagesy($edited) !== $h) {
                imagedestroy($edited); imagedestroy($source); imagedestroy($mask);
                return null; // kích thước chưa khớp → bỏ composite, giữ kết quả edit
            }
            // Bounding box vùng đen của mask (trước blur) — dùng cho fallback chống vùng đen.
            $bz0 = $w; $bz1 = -1; $bt0 = $h; $bt1 = -1;
            for ($y = 0; $y < $h; $y += 3) {
                for ($x = 0; $x < $w; $x += 3) {
                    $mc = imagecolorat($mask, $x, $y);
                    if (((($mc >> 16) & 0xFF) + (($mc >> 8) & 0xFF) + ($mc & 0xFF)) < 96) {
                        if ($x < $bz0) { $bz0 = $x; } if ($x > $bz1) { $bz1 = $x; }
                        if ($y < $bt0) { $bt0 = $y; } if ($y > $bt1) { $bt1 = $y; }
                    }
                }
            }
            $hasRegion = $bz1 >= 0 && $bt1 >= 0 && ($bz1 - $bz0) > 4 && ($bt1 - $bt0) > 4;
            $cx = (int) (($bz0 + $bz1) / 2); $cy = (int) (($bt0 + $bt1) / 2);

            // Feather mềm theo KHOẢNG CÁCH tới mép vùng đen (mask nhị phân): trong vùng = lấy
            // kết quả AI, ra ngoài feather = trộn mượt về ảnh gốc → biên hòa tự nhiên, không mép
            // cứng, không bị cắt xén (vùng đã giãn pad). KHÔNG Gaussian blur (pad mép = đen).
            $featherW = (int) max(4, round(min($w, $h) * 0.03));
            $out = imagecreatetruecolor($w, $h);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $dx = max($bz0 - $x, 0, $x - $bz1);
                    $dy = max($bt0 - $y, 0, $y - $bt1);
                    $dist = sqrt($dx * $dx + $dy * $dy);
                    // alpha: 1 trong vùng đen (lấy kết quả AI) → 0 ngoài feather (giữ ảnh gốc)
                    $alpha = $dist >= $featherW ? 0.0 : max(0.0, min(1.0, 1.0 - $dist / $featherW));
                    $sc = imagecolorat($source, $x, $y);
                    $ec = imagecolorat($edited, $x, $y);
                    $r = (int) round((($ec >> 16) & 0xFF) * $alpha + (($sc >> 16) & 0xFF) * (1 - $alpha));
                    $g = (int) round((($ec >> 8) & 0xFF) * $alpha + (($sc >> 8) & 0xFF) * (1 - $alpha));
                    $b = (int) round(($ec & 0xFF) * $alpha + ($sc & 0xFF) * (1 - $alpha));
                    imagesetpixel($out, $x, $y, imagecolorallocate($out, $r, $g, $b));
                }
            }
            // Fallback: nếu AI trả vùng ĐEN (kết quả vùng gần đen mà ảnh gốc sáng) HOẶC AI
            // KHÔNG HỀ SỬA (vùng gần như y nguyên — "không thể xóa") → bỏ kết quả AI, tái tạo
            // nền cục bộ từ cạnh viền để LUÔN có thay đổi nhìn thấy được.
            if ($hasRegion) {
                $diff = 0.0; $n = 0;
                $gx = max(1, intdiv($bz1 - $bz0, 6)); $gy = max(1, intdiv($bt1 - $bt0, 6));
                for ($yy = $bt0; $yy <= $bt1; $yy += $gy) {
                    for ($xx = $bz0; $xx <= $bz1; $xx += $gx) {
                        $a = imagecolorat($out, $xx, $yy); $b = imagecolorat($source, $xx, $yy);
                        $diff += abs((($a >> 16) & 255) - (($b >> 16) & 255)) + abs((($a >> 8) & 255) - (($b >> 8) & 255)) + abs(($a & 255) - ($b & 255));
                        $n += 3;
                    }
                }
                $meanDiff = $n ? $diff / $n : 0.0;
                $ec = imagecolorat($out, $cx, $cy);
                $elum = (($ec >> 16) & 0xFF) + (($ec >> 8) & 0xFF) + ($ec & 0xFF);
                $sc = imagecolorat($source, $cx, $cy);
                $slum = (($sc >> 16) & 0xFF) + (($sc >> 8) & 0xFF) + ($sc & 0xFF);
                if (($eraseFallback && $meanDiff < 3.0) || ($elum < 100 && $slum > 190)) {
                    imagedestroy($edited); imagedestroy($source); imagedestroy($mask); imagedestroy($out);
                    $fb = imagecreatetruecolor($w, $h);
                    imagecopy($fb, $source, 0, 0, 0, 0, $w, $h);
                    $this->reconstructRegion($fb, $bz0, $bt0, $bz1 - $bz0 + 1, $bt1 - $bt0 + 1);
                    ob_start(); imagepng($fb); $bytes = (string) ob_get_clean();
                    imagedestroy($fb);
                    $name = 'studio/composite-'.Str::uuid().'.png';
                    Storage::disk('public')->put($name, $bytes);
                    return '/storage/'.$name;
                }
            }
            imagedestroy($edited); imagedestroy($source); imagedestroy($mask);
            ob_start(); imagepng($out); $bytes = (string) ob_get_clean();
            imagedestroy($out);
            $name = 'studio/composite-'.Str::uuid().'.png';
            Storage::disk('public')->put($name, $bytes);
            return '/storage/'.$name;
        } catch (\Throwable $e) {
            logger()->warning('compositeMaskedEdit failed: '.$e->getMessage());
            return null;
        }
    }

    /**
     * Tái tạo nền cục bộ (border-stretch) — dùng khi AI trả vùng đen hoặc chế độ stub:
     * mỗi pixel trong vùng = nội suy tuyến tính nền trái↔phải và trên↔dưới (guard đen).
     */
    protected function reconstructRegion(\GdImage $img, int $px, int $py, int $pw, int $ph): void
    {
        $w = imagesx($img); $h = imagesy($img);
        $x0 = max(0, $px); $x1 = min($w - 1, $px + max(1, $pw) - 1);
        $y0 = max(0, $py); $y1 = min($h - 1, $py + max(1, $ph) - 1);
        if ($x0 > $x1 || $y0 > $y1) { return; }
        $lx = max(0, $px - 1); $rx = min($w - 1, $px + max(1, $pw));
        $ty = max(0, $py - 1); $by = min($h - 1, $py + max(1, $ph));
        $dark = function (int $c): bool { return ((($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF)) < 72; };
        $spanX = max(1, $x1 - $x0); $spanY = max(1, $y1 - $y0);
        for ($y = $y0; $y <= $y1; $y++) {
            $lc = imagecolorat($img, $lx, $y); $rc = imagecolorat($img, $rx, $y);
            if ($dark($lc) && ! $dark($rc)) { $lc = $rc; }
            elseif ($dark($rc) && ! $dark($lc)) { $rc = $lc; }
            $lr = ($lc >> 16) & 0xFF; $lg = ($lc >> 8) & 0xFF; $lb = $lc & 0xFF;
            $rr = ($rc >> 16) & 0xFF; $rg = ($rc >> 8) & 0xFF; $rb = $rc & 0xFF;
            for ($x = $x0; $x <= $x1; $x++) {
                $tc = imagecolorat($img, $x, $ty); $bc = imagecolorat($img, $x, $by);
                if ($dark($tc) && ! $dark($bc)) { $tc = $bc; }
                elseif ($dark($bc) && ! $dark($tc)) { $bc = $tc; }
                $fy = ($y - $y0) / $spanY; $fx = ($x - $x0) / $spanX;
                // nền ngang tại x
                $hr = $lr + ($rr - $lr) * $fx; $hg = $lg + ($rg - $lg) * $fx; $hb = $lb + ($rb - $lb) * $fx;
                // nền dọc tại y
                $tr = ($tc >> 16) & 0xFF; $tg = ($tc >> 8) & 0xFF; $tb = $tc & 0xFF;
                $br = ($bc >> 16) & 0xFF; $bg = ($bc >> 8) & 0xFF; $bb = $bc & 0xFF;
                $vr = $tr + ($br - $tr) * $fy; $vg = $tg + ($bg - $tg) * $fy; $vb = $tb + ($bb - $tb) * $fy;
                imagesetpixel($img, $x, $y, imagecolorallocate($img,
                    (int) round(($hr + $vr) / 2),
                    (int) round(($hg + $vg) / 2),
                    (int) round(($hb + $vb) / 2)));
            }
        }
    }

    /**
     * DEEP REDESIGN (region): AI đã sửa trên CROP → paste lại vào ẢNH GỐC đúng vị trí (crop_x/crop_y).
     * Crop có feather ở composite nên biên vùng mượt sẵn; ngữ cảnh ngoài vùng trùng khớp ảnh gốc.
     */
    protected function pasteRegionEdit(string $editedCropUrl, array $meta): ?string
    {
        try {
            $src = studio_image_decode((string) $this->resolveImageBinary((string) ($meta['source'] ?? '')));
            $crop = studio_image_decode((string) $this->resolveImageBinary($editedCropUrl));
            if (! $src || ! $crop) {
                if ($src) { imagedestroy($src); }
                if ($crop) { imagedestroy($crop); }
                return null;
            }
            $cx = (int) $meta['crop_x']; $cy = (int) $meta['crop_y'];
            $cw = (int) $meta['crop_w']; $ch = (int) $meta['crop_h'];
            // Nếu model trả crop tỷ lệ khác → cover-crop về đúng crop_w x crop_h (không méo).
            $ew = imagesx($crop); $eh = imagesy($crop);
            if ($ew !== $cw || $eh !== $ch) {
                $scale = max($cw / $ew, $ch / $eh);
                $tw = (int) round($ew * $scale); $th = (int) round($eh * $scale);
                $tmp = imagecreatetruecolor($tw, $th);
                imagecopyresampled($tmp, $crop, 0, 0, 0, 0, $tw, $th, $ew, $eh);
                $nw = imagecreatetruecolor($cw, $ch);
                imagecopy($nw, $tmp, 0, 0, (int) (($tw - $cw) / 2), (int) (($th - $ch) / 2), $cw, $ch);
                imagedestroy($tmp); imagedestroy($crop); $crop = $nw;
            }
            imagecopy($src, $crop, $cx, $cy, 0, 0, $cw, $ch);
            imagedestroy($crop);
            ob_start(); imagepng($src); $bytes = (string) ob_get_clean();
            imagedestroy($src);
            $name = 'studio/region-'.Str::uuid().'.png';
            Storage::disk('public')->put($name, $bytes);
            return '/storage/'.$name;
        } catch (\Throwable $e) {
            logger()->warning('pasteRegionEdit failed: '.$e->getMessage());
            return null;
        }
    }

    /**
     * Đọc binary ảnh cục bộ từ URL /storage/... (giống cách imageDataUri resolve file).
     */
    protected function resolveImageBinary(string $url): ?string
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        // Containment chống traversal — chỉ đọc file trong public/ hoặc storage/app/public (S1).
        $file = studio_safe_public_file($path);
        if ($file) {
            return (string) file_get_contents($file);
        }
        return null;
    }

    /**
     * Xóa nền → trong suốt: dùng mask (TRẮNG=chủ thể giữ, ĐEN=nền) để gán alpha cho ảnh kết quả.
     */
    public function applyTransparentBackground(string $imageUrl, string $maskUrl): ?string
    {
        $imgBin = $this->resolveImageBinary($imageUrl);
        $maskBin = $this->resolveImageBinary($maskUrl);
        if ($imgBin === null || $maskBin === null) return null;
        $img = studio_image_decode($imgBin);
        $mask = studio_image_decode($maskBin);
        if (! $img || ! $mask) return null;

        $w = imagesx($img); $h = imagesy($img);
        $mw = imagesx($mask); $mh = imagesy($mask);
        if ($mw !== $w || $mh !== $h) {
            $resized = imagecreatetruecolor($w, $h);
            imagecopyresampled($resized, $mask, 0, 0, 0, 0, $w, $h, $mw, $mh);
            imagedestroy($mask);
            $mask = $resized;
        }

        // Decontaminate viền trắng: blur mask để mép mềm, rồi "co" vùng đục vào trong ~1-2px
        // (mép ngoài chủ thể do model hoà trộn với nền trắng sẽ bị cắt bỏ thay vì giữ lại viền trắng).
        $soft = imagecreatetruecolor($w, $h);
        imagecopy($soft, $mask, 0, 0, 0, 0, $w, $h);
        @imagefilter($soft, IMG_FILTER_GAUSSIAN_BLUR);
        imagedestroy($mask);
        $mask = $soft;

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $m = imagecolorat($mask, $x, $y);
                $mr = ($m >> 16) & 0xFF; // TRẮNG=giữ (đục), ĐEN=nền (trong suốt)
                // Co mép: mr<200 → trong suốt (bỏ viền trắng); 200..255 → mờ dần sang đục.
                $mr2 = $mr < 200 ? 0 : (int) round(($mr - 200) * 255 / 55);
                $alpha = 127 - (int) round($mr2 * 127 / 255);
                $p = imagecolorat($img, $x, $y);
                $r = ($p >> 16) & 0xFF; $g = ($p >> 8) & 0xFF; $b = $p & 0xFF;
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $alpha));
            }
        }
        imagedestroy($img); imagedestroy($mask);

        ob_start(); imagepng($out); $bytes = ob_get_clean();
        imagedestroy($out);
        $name = 'studio/transparent-'.Str::uuid().'.png';
        Storage::disk('public')->put($name, $bytes);
        return Storage::disk('public')->url($name);
    }

    /**
     * Xóa nền tự động khi KHÔNG có mask lasso: model đã thay nền thành trắng tinh.
     * Flood-fill từ 4 cạnh để tìm vùng nền trắng (nối với viền ảnh), tạo mask rồi cắt alpha.
     */
    public function applyAutoTransparentBackground(string $imageUrl): ?string
    {
        $imgBin = $this->resolveImageBinary($imageUrl);
        if ($imgBin === null) return null;
        $img = studio_image_decode($imgBin);
        if (! $img) return null;
        $w = imagesx($img); $h = imagesy($img);

        // Flood-fill nền trắng (gần trắng, nối với 4 cạnh ảnh).
        $visited = array_fill(0, $w * $h, 0);
        $stack = [];
        $push = function ($x, $y) use (&$stack, &$visited, $img, $w, $h) {
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) return;
            $idx = $y * $w + $x;
            if ($visited[$idx]) return;
            $c = imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            if ($r >= 235 && $g >= 235 && $b >= 235) { $visited[$idx] = 1; $stack[] = $idx; }
        };
        for ($x = 0; $x < $w; $x++) { $push($x, 0); $push($x, $h - 1); }
        for ($y = 0; $y < $h; $y++) { $push(0, $y); $push($w - 1, $y); }
        while ($stack) {
            $idx = array_pop($stack);
            $x = $idx % $w; $y = intdiv($idx, $w);
            $push($x, $y - 1); $push($x, $y + 1); $push($x - 1, $y); $push($x + 1, $y);
        }

        // Mask: TRẮNG = giữ (chủ thể), ĐEN = nền (xóa). Mặc định giữ toàn bộ, rồi ghi đen vùng nền.
        $mask = imagecreatetruecolor($w, $h);
        imagefilledrectangle($mask, 0, 0, $w - 1, $h - 1, imagecolorallocate($mask, 255, 255, 255));
        $black = imagecolorallocate($mask, 0, 0, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($visited[$y * $w + $x]) imagesetpixel($mask, $x, $y, $black);
            }
        }
        imagedestroy($img);

        ob_start(); imagepng($mask); $bytes = ob_get_clean();
        imagedestroy($mask);
        $name = 'studio/mask-'.Str::uuid().'.png';
        Storage::disk('public')->put($name, $bytes);

        // Dùng chung hậu kỳ decontaminate (blur + co mép) như khi có mask lasso.
        return $this->applyTransparentBackground($imageUrl, Storage::disk('public')->url($name));
    }

    protected function sizeFor(?string $resolution, ?string $ratio): string
    {
        // Qwen-Image only accepts a fixed set of sizes; map the ratio (and the extra
        // ratios) onto the nearest supported one so the provider call succeeds.
        return match ($ratio) {
            '16:9', '21:9', '19:6' => '1664*928',
            '4:3' => '1472*1104',
            '1:1' => '1328*1328',
            '3:4', '4:5' => '1104*1472',
            '9:16' => '928*1664',
            default => '1328*1328',
        };
    }

    protected function placeholder(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }
}
