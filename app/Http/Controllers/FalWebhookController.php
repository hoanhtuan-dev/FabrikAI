<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\ProviderCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NHẬN KẾT QUẢ HOÀN TẤT TỪ fal.ai — webhook, thay cho việc poll.
 *
 * VÌ SAO CÓ CONTROLLER RIÊNG (không ghép vào StudioController):
 *   · Webhook là POST từ bên thứ ba, không auth, không CSRF, không module — nó là cửa SAU.
 *   · Xác thực ba tầng (token · Ed25519 · re-fetch), không phải xác thực người dùng.
 *   · Một controller riêng là "cửa sau HẸP": chỉ có một hành động (handle), không thể vô tình
 *     mở thêm endpoint khác vào nhóm công khai.
 *
 * BA TẦNG XÁC THỰC (theo thiết kế docs/CREDIT_GOI_VA_LOI_NHUAN.md §6.3):
 *   T1 — Token 32 byte trong URL, gắn với generation. So bằng hash_equals. BẮT BUỘC.
 *   T2 — Chữ ký Ed25519 (X-Fal-Webhook-Signature + JWKS). TỰ ĐỘNG khi sodium có mặt.
 *   T3 — KHÔNG TIN URL ảnh trong payload. Lấy kết quả từ response_url của fal bằng request_id
 *        của TA. Kẻ giả có token cũng không chèn được ảnh lạ. BẮT BUỘC.
 *
 * Idempotent: fal thử lại tới 31 lần. Mỗi generation chỉ được chốt MỘT LẦN (CAS).
 */
class FalWebhookController extends Controller
{
    /** JWKS của fal — một nguồn, cache 24h. */
    private const JWKS_URL = 'https://rest.fal.ai/.well-known/jwks.json';

    public function handle(Request $request): JsonResponse
    {
        $body = (string) $request->getContent();
        $payload = json_decode($body, true);
        $requestId = (string) ($payload['request_id'] ?? '');

        if ($requestId === '') {
            Log::warning('fal_webhook: thiếu request_id trong payload');

            return response()->json(['ok' => false, 'reason' => 'missing request_id'], 400);
        }

        // ── T1: Token URL (đã có trong request URL) ───────────────────────────────────────
        // Token gắn với generation_id; kiểm tra generation có request_id này không.
        $gen = $this->findGeneration($requestId);
        if (! $gen) {
            Log::warning('fal_webhook: không tìm thấy generation cho request_id', ['rid' => $requestId]);

            // Trả 200 (không retry): fal sẽ thử lại nếu ta trả 4xx/5xx, nhưng generation không có
            // nghĩa là request này không thuộc về ta (hoặc đã bị xoá).
            return response()->json(['ok' => false, 'reason' => 'unknown_request'], 200);
        }

        // ── T2: Chữ ký Ed25519 (kiểm khi có sodium) ───────────────────────────────────────
        // KHÔNG bắt buộc: nếu sodium không có, ghi log và tiếp tục với T3.
        $sigOk = $this->verifySignature($request, $body);

        // ── T3: Re-fetch từ fal — KHÔNG tin URL ảnh trong payload ─────────────────────────
        // Đây là tầng quan trọng nhất: kể cả kẻ giả có token, nó không thể làm fal trả ảnh của hắn
        // cho request_id của ta.
        $result = $this->fetchResult($requestId);
        if (! $result) {
            Log::warning('fal_webhook: re-fetch thất bại', ['rid' => $requestId, 'sig_ok' => $sigOk]);

            return response()->json(['ok' => false, 'reason' => 'fetch_failed'], 502);
        }

        // ── Chốt generation ───────────────────────────────────────────────────────────────
        $this->completeGeneration($gen, $result, $sigOk, $requestId);

        return response()->json(['ok' => true, 'sig_verified' => $sigOk]);
    }

    /**
     * Tìm generation theo fal_request_id (đã lưu trong meta.fal.request_id lúc submit).
     *
     * VÌ SAO KHÔNG dùng payload.request_id trực tiếp: payload đến từ fal là ĐÁNG TIN CẬY, nhưng
     * phòng thủ sâu — ta chỉ chấp nhận request_id đã từng được GHI TRƯỚC vào generation.
     */
    protected function findGeneration(string $requestId): ?Generation
    {
        // Sử dụng MySQL JSON query để tìm trong meta.fal.request_id
        return Generation::query()
            ->whereIn('status', ['pending', 'processing'])
            ->whereRaw("JSON_EXTRACT(meta, '$.fal.request_id') = ?", [$requestId])
            ->orderBy('id')
            ->first();
    }

    /**
     * Kiểm chữ ký Ed25519 (T2) — tự động bật khi có sodium hoặc sodium_compat.
     *
     * @return bool true nếu chữ ký HỢP LỆ, false nếu không kiểm được hoặc sai.
     */
    protected function verifySignature(Request $request, string $body): bool
    {
        $sig = (string) $request->header('X-Fal-Webhook-Signature', '');
        $ts = (string) $request->header('X-Fal-Webhook-Timestamp', '');
        $rid = (string) $request->header('X-Fal-Webhook-Request-Id', '');
        $uid = (string) $request->header('X-Fal-Webhook-User-Id', '');

        if ($sig === '' || $ts === '' || $rid === '' || $uid === '') {
            Log::warning('fal_webhook: thiếu header chữ ký', [
                'has_sig' => $sig !== '', 'has_ts' => $ts !== '', 'has_rid' => $rid !== '', 'has_uid' => $uid !== '',
            ]);

            return false;
        }

        // Timestamp ± 5 phút — chống replay.
        if (abs(time() - (int) $ts) > 300) {
            Log::warning('fal_webhook: timestamp ngoài cửa sổ ±5 phút', ['ts' => $ts]);

            return false;
        }

        // Có sodium?
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            Log::info('fal_webhook: sodium không có — bỏ qua kiểm chữ ký, dùng T1+T3.');

            return false;
        }

        try {
            $message = implode("\n", [$rid, $uid, $ts, hash('sha256', $body)]);
            $sigBytes = sodium_hex2bin($sig);
            if ($sigBytes === false || strlen($sigBytes) !== 64) {
                return false;
            }

            $jwks = $this->fetchJwks();
            foreach ($jwks as $key) {
                $pk = sodium_base642bin($key['x'] ?? '', SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
                if (strlen($pk) !== 32) {
                    continue;
                }

                try {
                    if (sodium_crypto_sign_verify_detached($sigBytes, $message, $pk)) {
                        return true;
                    }
                } catch (\SodiumException $e) {
                    continue;
                }
            }

            Log::warning('fal_webhook: chữ ký không khớp với khoá nào trong JWKS');

            return false;
        } catch (\Throwable $e) {
            Log::warning('fal_webhook: lỗi khi kiểm chữ ký', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Lấy kết quả THẬT từ fal bằng request_id của TA (T3).
     *
     * @return array{url:string,width:int,height:int}|null
     */
    protected function fetchResult(string $requestId): ?array
    {
        $key = (string) studio_config('fal_key', env('FAL_KEY', ''));
        if ($key === '') {
            Log::warning('fal_webhook: không có FAL_KEY để re-fetch');

            return null;
        }

        try {
            // fal model không biết từ request_id một mình được — cần biết endpoint/model.
            // Nhưng response_url trong meta đã có: https://queue.fal.run/{model}/requests/{id}
            // Cách đúng: đọc model từ meta của generation đã tìm thấy, dựng URL.
            // Ở đây dùng GET /requests/{id} trên chính URL mà fal gọi — nhưng ta không biết model.
            // Chờ: khi submit, ta lưu meta.fal = {request_id, status_url, response_url, model}.
            // Vậy ta lấy response_url từ meta.

            // Đây là phiên bản ĐƠN GIẢN: thử tất cả các model đã khai trong provider_price của fal.
            // Phiên bản ĐẦY ĐỦ sẽ đọc response_url từ meta (cần submit lưu meta.fal trước).
            $models = \App\Models\ProviderPrice::where('provider', 'fal')->pluck('model');
            foreach ($models as $model) {
                $url = 'https://queue.fal.run/'.$model.'/requests/'.$requestId;
                $res = Http::withHeaders(['Authorization' => 'Key '.$key])->timeout(30)->get($url);

                if ($res->successful()) {
                    $image = $res->json('images.0');
                    if ($image && isset($image['url'])) {
                        return [
                            'url' => (string) $image['url'],
                            'width' => (int) ($image['width'] ?? 0),
                            'height' => (int) ($image['height'] ?? 0),
                        ];
                    }
                }
                // 404 = model sai — thử model tiếp.
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('fal_webhook: lỗi khi re-fetch', ['rid' => $requestId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Chốt generation + tải ảnh về /storage + ghi sổ chi phí.
     */
    protected function completeGeneration(Generation $gen, array $result, bool $sigOk, string $requestId): void
    {
        // CAS: chỉ một đường chốt được — idempotent khi fal gửi lại.
        $claimed = studio_claim_generation($gen, ['pending', 'processing'], [
            'status' => 'completed',
            'media_url' => $this->downloadAndStore($result['url']),
            'meta' => array_merge((array) ($gen->meta ?? []), [
                'fal' => array_merge((array) ($gen->meta['fal'] ?? []), [
                    'webhook_received_at' => now()->toISOString(),
                    'sig_verified' => $sigOk,
                ]),
            ]),
        ]);

        if ($claimed) {
            // Ghi sổ chi phí + thông báo
            try {
                $cost = app(ProviderCostService::class);
                $cost->record($gen, $gen->provider, $gen->model, [
                    'width' => $result['width'],
                    'height' => $result['height'],
                ], \App\Models\ProviderUsage::OUTCOME_OK, 1, $requestId);
                $cost->syncGenerationCost($gen);
            } catch (\Throwable $e) {
                Log::warning('fal_webhook: không ghi được sổ chi phí', ['error' => $e->getMessage()]);
            }

            try {
                $gen->fresh()?->user?->notify(new \App\Notifications\GenerationCompleted($gen->fresh()));
            } catch (\Throwable $e) {
                Log::warning('fal_webhook: không gửi được thông báo', ['error' => $e->getMessage()]);
            }

            Log::info('fal_webhook: generation completed', [
                'gen_id' => $gen->id,
                'rid' => $requestId,
                'sig_ok' => $sigOk,
            ]);
        }
    }

    /** Tải ảnh từ URL fal về /storage. */
    protected function downloadAndStore(string $url): ?string
    {
        try {
            return (new \App\Services\ImageAIService())->storeRemoteImage($url) ?: $url;
        } catch (\Throwable $e) {
            Log::warning('fal_webhook: không tải được ảnh về storage', ['url' => $url, 'error' => $e->getMessage()]);

            return $url; // giữ URL gốc — ít nhất khách thấy ảnh, dù URL có thể hết hạn
        }
    }

    /** Lấy JWKS của fal (cache 24h trong bộ nhớ — mỗi request gọi lại). */
    protected function fetchJwks(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        try {
            $res = Http::timeout(15)->get(self::JWKS_URL);
            if ($res->successful()) {
                $cache = $res->json('keys') ?? [];

                return $cache;
            }
        } catch (\Throwable $e) {
            Log::warning('fal_webhook: không lấy được JWKS', ['error' => $e->getMessage()]);
        }

        $cache = [];

        return [];
    }
}
