<?php

use App\Models\Setting;

if (! function_exists('setting')) {
    function setting($key, $default = null)
    {
        return Setting::get($key, $default);
    }
}

if (! function_exists('set_setting')) {
    function set_setting($key, $value): void
    {
        Setting::set($key, $value);
    }
}


if (! function_exists('normalize_vn')) {
    function normalize_vn(?string $value): string
    {
        $value = (string) $value;
        if (class_exists('\\Transliterator')) {
            $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        } else {
            $map = [
                'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a', 'ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
                'â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
                'è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e', 'ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
                'ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
                'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o', 'ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
                'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
                'ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u', 'ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
                'ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
                'đ'=>'d', 'Đ'=>'D',
                'À'=>'A','Á'=>'A','Ả'=>'A','Ã'=>'A','Ạ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ẳ'=>'A','Ẵ'=>'A','Ặ'=>'A',
                'Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ậ'=>'A',
                'È'=>'E','É'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ẹ'=>'E','Ê'=>'E','Ề'=>'E','Ế'=>'E','Ể'=>'E','Ễ'=>'E','Ệ'=>'E',
                'Ì'=>'I','Í'=>'I','Ỉ'=>'I','Ĩ'=>'I','Ị'=>'I',
                'Ò'=>'O','Ó'=>'O','Ỏ'=>'O','Õ'=>'O','Ọ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ổ'=>'O','Ỗ'=>'O','Ộ'=>'O',
                'Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ở'=>'O','Ỡ'=>'O','Ợ'=>'O',
                'Ù'=>'U','Ú'=>'U','Ủ'=>'U','Ũ'=>'U','Ụ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ử'=>'U','Ữ'=>'U','Ự'=>'U',
                'Ỳ'=>'Y','Ý'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y','Ỵ'=>'Y',
            ];
            $value = strtr($value, $map);
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim($value);
    }
}

if (! function_exists('asset_image')) {
    function asset_image(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        if (str_starts_with($path, 'samples/')) {
            return asset($path);
        }

        return asset('storage/'.$path);
    }
}

if (! function_exists('rel_image_url')) {
    /** Đường dẫn ảnh TƯƠNG ĐỐI (không kèm host) — dùng cho API để ảnh tải được từ mọi host. */
    function rel_image_url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        if (str_starts_with($path, 'samples/')) {
            return '/'.$path;
        }

        return '/storage/'.$path;
    }
}


if (! function_exists('studio_fail')) {
    /**
     * Build a generic client-facing error response while logging the real exception.
     *
     * Server-error catch blocks returned $e->getMessage() raw in 500 responses,
     * leaking SQL fragments, table/column names, and framework paths. This helper
     * returns a stable generic message, logs the detail server-side, and is
     * debug-aware (verbose only when APP_DEBUG). Use for SERVER errors (500);
     * 422 paths often throw user-authored validation messages and keep their text.
     */
    function studio_fail(\Throwable $e, string $context = '', int $status = 500, ?string $safeMessage = null): \Illuminate\Http\JsonResponse
    {
        // Mã tra cứu: khách đọc cho tổng đài, hỗ trợ grep thẳng trong storage/logs/laravel.log.
        $code = studio_error_code($e);
        \Illuminate\Support\Facades\Log::warning('studio_fail['.$code.']: '.$context, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]);
        $msg = $safeMessage ?? ($context !== '' ? $context.' thất bại.' : 'Lỗi hệ thống, vui lòng thử lại.');
        $payload = ['ok' => false, 'message' => $msg, 'error_code' => $code];
        if (config('app.debug')) {
            $payload['debug'] = $e->getMessage();
        }
        return response()->json($payload, $status);
    }
}

if (! function_exists('studio_error_code')) {
    /**
     * MÃ TRA CỨU LỖI cho người dùng đọc cho tổng đài (2026-09-23) — ví dụ L-8F3K.
     *
     * Vì sao cần: trước đây hỗ trợ phải hỏi khách "lỗi lúc mấy giờ, tài khoản nào" rồi tự dò
     * storage/logs/laravel.log. Nay mã này có mặt ở CẢ HAI phía — trên màn hình và trong log — nên
     * chỉ cần khách đọc 6 ký tự là tra được đúng dòng lỗi.
     *
     * Sinh từ nội dung + vị trí + thời điểm ⇒ mỗi LẦN lỗi có mã riêng (không lẫn các lần khác nhau).
     * Bảng chữ CỐ Ý bỏ các ký tự dễ đọc nhầm khi đọc qua điện thoại (0/O, 1/I).
     */
    function studio_error_code(\Throwable $e): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $hash = md5($e->getMessage().'|'.$e->getFile().':'.$e->getLine().'|'.microtime(true));
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $alphabet[hexdec(substr($hash, $i * 2, 2)) % strlen($alphabet)];
        }

        return 'L-'.$code;
    }
}

if (! function_exists('studio_image_decode')) {
    /**
     * Decode an image from a file path OR raw bytes with an OOM guard.
     *
     * The module had ~41 `@imagecreatefromstring(file_get_contents(...))` sites with
     * no size check — a maliciously large image (or a non-image file the GD still
     * tries to allocate for) could OOM a worker. This helper caps the input at a
     * generous 64 MiB (configurable) before calling imagecreatefromstring().
     *
     * @param  string  $dataOrPath  raw image bytes OR a local file path (auto-detected)
     * @param  int     $maxBytes    hard cap on the input size; 0 = 64 MiB default
     * @return \GdImage|false  GD resource, or false on reject/failure
     */
    function studio_image_decode($dataOrPath, int $maxBytes = 0)
    {
        $maxBytes = $maxBytes > 0 ? $maxBytes : 67108864; // 64 MiB
        $data = $dataOrPath;

        if (is_file($dataOrPath)) {
            $size = @filesize($dataOrPath);
            if ($size === false || $size > $maxBytes) {
                return false;
            }
            $data = @file_get_contents($dataOrPath);
            if ($data === false || strlen($data) > $maxBytes) {
                return false;
            }
        } elseif (strlen((string) $data) > $maxBytes) {
            return false;
        }

        // Cap SỐ PIXEL — PHẢI chạy TRƯỚC khi decode (T14/S2). Bản trước đặt kiểm tra này SAU
        // imagecreatefromstring() nên bitmap đã được cấp phát xong mới bị từ chối: đúng cái OOM
        // mà cap này sinh ra để chặn thì đã xảy ra rồi. Đọc kích thước từ header trước, nên ảnh
        // "bomb" (PNG/JPEG nén vài chục KB giải nén thành hàng trăm MP) bị loại khi chưa tốn RAM.
        // Mặc định 30 MP (~8192×3660) — ảnh 4K (16,7 MP) vẫn qua; 0 = tắt cap.
        $maxPixels = (int) (function_exists('studio_config') ? studio_config('image_max_pixels', 30000000) : 30000000);
        if ($maxPixels > 0) {
            $info = @getimagesizefromstring((string) $data);
            if (is_array($info) && ! empty($info[0]) && ! empty($info[1]) && ($info[0] * $info[1]) > $maxPixels) {
                \Illuminate\Support\Facades\Log::warning('studio_image_decode: từ chối ảnh vượt ngưỡng pixel (trước decode)', [
                    'w' => $info[0], 'h' => $info[1], 'max' => $maxPixels,
                ]);

                return false;
            }
        }

        // Decode thật bằng GD. (Đã sửa lỗi [critical]: dòng này từng đệ quy vào
        // chính studio_image_decode() → tràn stack/OOM giết worker tại 43 call-site.)
        $gd = @imagecreatefromstring((string) $data);
        if ($gd === false) {
            // M20: 43 call-site trước đây nhận false rồi trả null/"no mask"/"no description" ÂM THẦM,
            // không cách nào biết vì sao. Log lại kích thước + 12 byte đầu để chẩn đoán.
            \Illuminate\Support\Facades\Log::warning('studio_image_decode: decode thất bại', [
                'bytes' => strlen((string) $data),
                'magic' => bin2hex(substr((string) $data, 0, 12)),
            ]);

            return false;
        }

        // Chốt lại SAU decode cho định dạng không đọc được kích thước từ header (defense-in-depth).
        if ($maxPixels > 0 && imagesx($gd) * imagesy($gd) > $maxPixels) {
            \Illuminate\Support\Facades\Log::warning('studio_image_decode: từ chối ảnh vượt ngưỡng pixel (sau decode)', [
                'w' => imagesx($gd), 'h' => imagesy($gd), 'max' => $maxPixels,
            ]);
            unset($gd);

            return false;
        }

        return $gd;
    }
}

if (! function_exists('studio_safe_public_file')) {
    /**
     * Containment-checked local file lookup cho path kiểu /storage/... do người dùng cung cấp:
     * loại segment '..', chỉ cho ký tự [A-Za-z0-9/_.-], rồi bắt buộc realpath() của file
     * nằm TRONG public/ hoặc storage/app/public (symlink được resolve trước khi so).
     *
     * Nguồn dùng chung duy nhất — StudioController::safeLocalFile() và các resolver của
     * ImageAIService (resolveSamplePath/resolveImageBinary/imageDataUri) đều đi qua đây.
     */
    function studio_safe_public_file(string $path): ?string
    {
        $path = ltrim($path, '/');

        if ($path === '' || in_array('..', explode('/', $path), true)) {
            return null;
        }
        if (! preg_match('#^[A-Za-z0-9/_.\-]+$#', $path)) {
            return null;
        }

        $roots = array_filter([realpath(public_path()), realpath(storage_path('app/public'))]);
        $candidates = [
            public_path($path),
            storage_path('app/public/'.$path),
            storage_path('app/public/'.ltrim(str_replace('storage/', '', $path), '/')),
        ];

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real === false || ! is_file($real)) {
                continue;
            }
            foreach ($roots as $root) {
                if (str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
        }

        return null;
    }
}

if (! function_exists('auth_throttle_key')) {
    /**
     * Khoá đếm cho rate limiter đăng nhập: EMAIL (chuẩn hoá) + IP.
     *
     * Dùng CHUNG giữa AppServiceProvider (định nghĩa limiter 'login') và AuthController (xoá bộ đếm
     * khi đăng nhập thành công) — nếu hai bên tự tính khoá riêng thì việc xoá sẽ trượt và người dùng
     * gõ sai vài lần rồi gõ đúng vẫn bị phạt.
     */
    function auth_throttle_key(\Illuminate\Http\Request $request): string
    {
        return mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip();
    }
}

if (! function_exists('studio_is_unique_violation')) {
    /**
     * M03: nhận diện lỗi vi phạm ràng buộc UNIQUE của DB.
     *
     * Các luồng stylist dùng "check-then-act" (SELECT xem slug/key đã tồn tại chưa rồi mới INSERT)
     * — không atomic: hai request đồng thời cùng qua được vòng kiểm tra, request thứ hai ném
     * ConstraintViolation. DB ĐÃ có unique index cho slug/key (migration
     * 2026_09_03_000000_create_stylist_catalog_tables.php:13,23) nên không tạo bản ghi trùng,
     * nhưng trước đây lỗi này trả 500 chung chung thay vì 422 kèm hướng dẫn.
     */
    function studio_is_unique_violation(\Throwable $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'UNIQUE constraint failed')   // SQLite
            || str_contains($m, 'Duplicate entry')            // MySQL
            || str_contains($m, 'SQLSTATE[23000]')            // chuẩn chung
            || str_contains($m, 'Integrity constraint violation');
    }
}

if (! function_exists('studio_sanitize_error')) {
    /**
     * [Đợt 0.8 — 2026-09-17] Lọc chi tiết NHẠY CẢM khỏi thông điệp lỗi trả về client.
     *
     * Giữ phần diễn giải (dev vẫn thấy "InvalidApiKey", "timeout"...) nhưng xoá những thứ KHÔNG
     * bao giờ được lộ: đường dẫn tuyệt đối trên máy chủ và chi tiết SQLSTATE/SQL thô.
     */
    function studio_sanitize_error(string $message): string
    {
        $m = $message;

        // 1) Đường dẫn tuyệt đối (Unix) + dòng ":123" → chỉ giữ tên file.php.
        //    (Hosting là Linux; đường dẫn Windows không xuất hiện ở môi trường chạy thật.)
        $m = (string) preg_replace('#(/[A-Za-z0-9_.\-]+)+/([A-Za-z0-9_.\-]+\.php)(:\d+)?#', '$2', $m);

        // 2) Chuỗi SQLSTATE[...] (MySQL) → gom gọn, không lộ câu SQL thô.
        $m = (string) preg_replace('#SQLSTATE\[[A-Za-z0-9]+\][^;]*#', 'SQLSTATE[...]', $m);

        return $m;
    }
}

if (! function_exists('studio_generation_error')) {
    /**
     * Thông điệp lỗi ghi vào cột Generation.error (N8/N10). Cột này TRẢ CHO CLIENT qua
     * StudioController::show(), nên KHÔNG được chứa message thô của provider/DB.
     *
     * - Luôn log đầy đủ (class + message + file:line) ở server để còn chẩn đoán.
     * - APP_DEBUG=true  → vẫn trả chi tiết DIỄN GIẢI để dev dò, nhưng đã LỌC đường dẫn/SQL.
     *   (APP_DEBUG có thể bị bật quên trên hosting — không vì thế mà lộ cấu trúc máy chủ.)
     * - Production      → chỉ trả câu chung, không lộ chi tiết nội bộ.
     *
     * @param  string  $prefix  tiền tố giữ nguyên cho user (vd 'Render bị ngắt: ')
     */
    function studio_generation_error(\Throwable $e, string $prefix = ''): string
    {
        $code = studio_error_code($e);
        \Illuminate\Support\Facades\Log::error('Generation failed ['.$code.']'.($prefix !== '' ? ' — '.trim($prefix) : ''), [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ]);

        if (config('app.debug')) {
            return $prefix.studio_sanitize_error($e->getMessage()).' ['.class_basename($e).']';
        }

        return $prefix !== ''
            ? $prefix.'Vui lòng thử lại.'
            : 'Xử lý thất bại. Vui lòng thử lại, nếu vẫn lỗi hãy báo cho quản trị viên.';
    }
}

if (! function_exists('studio_ref_user_dir')) {
    /**
     * [0.1b — 2026-09-17] Thư mục ảnh nguồn RIÊNG của một user (đường dẫn tương đối trong disk 'public').
     *
     * Trước đây mọi ảnh tải lên đổ chung vào `studio/ref/` nên không thể phân quyền: endpoint liệt kê
     * và xoá buộc phải giữ ở nhóm ADMIN, người dùng thường không quản lý được thư viện của mình.
     */
    function studio_ref_user_dir(?int $userId): string
    {
        return 'studio/ref/u'.(int) $userId;
    }
}

if (! function_exists('studio_ref_owner_of')) {
    /**
     * Đọc chủ sở hữu từ đường dẫn tương đối.
     *
     * @return int|null userId, hoặc null nếu là KHO CHUNG — file phẳng ở `studio/ref/` (dữ liệu có
     *                  TRƯỚC khi tách theo user) hoặc tài nguyên dùng chung `studio/assets/`.
     */
    function studio_ref_owner_of(?string $rel): ?int
    {
        $rel = str_replace('\\', '/', (string) $rel);

        return preg_match('#^studio/ref/u(\d+)/#', $rel, $m) === 1 ? (int) $m[1] : null;
    }
}

if (! function_exists('studio_upload_visible_to')) {
    /**
     * [0.1b] User có được ĐỌC / XOÁ đường dẫn này không?
     *
     *   · Owner (admin) thì được với MỌI thứ (yêu cầu: "owner có thể quản lý tất cả").
     *   · Nằm trong thư mục riêng `studio/ref/u<id>/` thì chỉ chủ sở hữu.
     *   · Kho CHUNG (file phẳng cũ + `studio/assets/`) thì mọi người — giữ tương thích ngược:
     *     ảnh người dùng đã chèn vào dự án trước đây không được biến mất.
     */
    function studio_upload_visible_to(?string $rel, ?int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        $owner = studio_ref_owner_of($rel);

        return $owner === null || $owner === (int) $userId;
    }
}

if (! function_exists('studio_prompt_template')) {
    /**
     * [Đợt 1.7 — 2026-09-17] Resolve prompt theo KEY từ bảng prompt_templates.
     *
     * Chọn phiên bản CAO NHẤT đang active cho key; thay placeholder {key}; nếu không có hàng
     * nào thì trả $fallback (chuỗi hardcode cũ) — nên hành vi cũ được bảo toàn khi DB trống.
     * Đổi template trong DB ⇒ nội dung gửi provider đổi KHÔNG cần deploy.
     *
     * @param  string  $key  vd 'garment.lock'
     * @param  array<string, string>  $placeholders  vd ['name' => 'áo dài']
     */
    function studio_prompt_template(string $key, array $placeholders = [], ?string $fallback = null): string
    {
        $body = \App\Models\PromptTemplate::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->value('body');

        $body = $body !== null ? $body : ($fallback ?? '');

        foreach ($placeholders as $k => $v) {
            $body = str_replace('{'.$k.'}', (string) $v, $body);
        }

        return $body;
    }
}

if (! function_exists('studio_claim_generation')) {
    /**
     * CAS: chỉ request nào ĐỔI ĐƯỢC trạng thái khỏi $from mới "giành" được row.
     *
     * [M-c · M-d — 2026-09-17] Trước đây các đường đổi trạng thái cuối tự làm
     * `$generation->update([...])` VÔ ĐIỀU KIỆN, nên hai request đồng thời cùng "thắng":
     *  · cancel() đua với failStuck()/reconcileStuckCredits() ⇒ HOÀN CREDIT 2 LẦN;
     *  · job render xong sau khi user bấm Huỷ ⇒ "hồi sinh" row đã cancelled và hoàn tiền lần nữa.
     * Mọi side-effect (hoàn tiền, ghi media_url…) CHỈ được chạy khi hàm này trả true.
     *
     * @param  array<int, string>  $from        trạng thái được phép claim
     * @param  array<string, mixed>  $attributes  cột cần ghi khi claim thành công
     * @return bool  true = request NÀY giành được row
     */
    function studio_claim_generation(\App\Models\Generation $generation, array $from, array $attributes): bool
    {
        return \App\Models\Generation::where('id', $generation->id)
            ->whereIn('status', $from)
            ->update($attributes) > 0;
    }
}

if (! function_exists('studio_finalize_generation')) {
    /**
     * Đường DUY NHẤT được phép đặt TRẠNG THÁI CUỐI + HOÀN CREDIT cho một generation.
     *
     * Hoàn tiền nằm TRONG nhánh giành được row ⇒ không thể hoàn hai lần (kể cả khi request chạy
     * song song). 'completed' KHÔNG hoàn; 'failed'/'cancelled' hoàn đúng một lần.
     *
     * @param  array<int, string>  $from  mặc định cả 'pending' lẫn 'processing'
     * @return bool  true = đã claim (trạng thái + credit đã xử lý), false = người khác đã xử lý trước
     */
    function studio_finalize_generation(
        \App\Models\Generation $generation,
        string $to,
        array $from = ['pending', 'processing'],
        ?string $error = null,
        array $extra = []
    ): bool {
        $attributes = ['status' => $to] + $extra;
        if ($error !== null) {
            $attributes['error'] = $error;
        }

        if (! studio_claim_generation($generation, $from, $attributes)) {
            return false; // ai đó đã đổi trạng thái trước ⇒ KHÔNG hoàn tiền, KHÔNG ghi đè
        }

        if (in_array($to, ['failed', 'cancelled'], true) && (int) $generation->credits_cost > 0) {
            $user = $generation->user;
            if ($user) {
                app(\App\Services\CreditService::class)->apply($user, (int) $generation->credits_cost, 'refund', [
                    'reference_type' => 'generation',
                    'reference_id' => $generation->id,
                    'note' => 'Hoàn credit khi generation '.$to,
                ]);
            }
        }

        // Giữ model trong bộ nhớ khớp DB để caller không đọc lại trạng thái cũ.
        $generation->forceFill($attributes)->syncOriginal();

        return true;
    }
}

if (! function_exists('studio_fetch_remote_bytes')) {
    /**
     * Tải nội dung từ URL REMOTE với guard SSRF dùng chung cho toàn module (S3 · N1 · N4):
     * chỉ scheme http/https · timeout 30s + connect_timeout 10s · redirect ≤2 (chỉ http/https) ·
     * cap dung lượng (mặc định 50 MiB) · allowlist host tùy chọn qua setting studio
     * 'remote_image_hosts' (CSV, rỗng = không chặn host).
     *
     * Nguồn dùng chung DUY NHẤT: ImageAIService::storeRemoteImage(), StudioController
     * applySuperResolution()/applyFaceEnhance(), VideoAIService::callDashscopeVideo() đều đi qua đây.
     * Trước đây 3 đường sau tự gọi @file_get_contents() thô (SSRF + treo worker + đầy disk).
     *
     * @return string|null  bytes, hoặc null khi URL không hợp lệ/bị chặn/tải lỗi/vượt cap
     */
    function studio_fetch_remote_bytes(string $url, int $maxBytes = 52428800): ?string
    {
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
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                    $hostOk = true;
                    break;
                }
            }
            if (! $hostOk) {
                \Illuminate\Support\Facades\Log::warning('studio_fetch_remote_bytes: host ngoài allowlist', ['host' => $host]);

                return null;
            }
        }

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(30)
                ->withOptions(['connect_timeout' => 10, 'allow_redirects' => ['max' => 2, 'protocols' => ['http', 'https']]])
                ->get($url);
            if (! $res->successful()) {
                return null;
            }
            $contents = (string) $res->body();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($contents === '' || strlen($contents) > $maxBytes) {
            \Illuminate\Support\Facades\Log::warning('studio_fetch_remote_bytes: rỗng hoặc vượt cap', [
                'url' => $url, 'bytes' => strlen($contents), 'max' => $maxBytes,
            ]);

            return null;
        }

        return $contents;
    }
}

if (! function_exists('studio_config')) {
    function studio_config(string $key, $default = null)
    {
        // Prefer a DB setting override (set via the Studio admin), fall back to config.
        // Empty string from DB (= user cleared the field) → fall back to config default.
        $stored = setting('studio_'.$key);

        return ($stored !== null && $stored !== '') ? (string) $stored : config('studio.'.$key, $default);
    }
}

if (! function_exists('studio_suggest_config')) {
    /**
     * Đọc cấu hình RIÊNG của tính năng "💡 Gợi ý từ ảnh" — tách hoàn toàn khỏi
     * cấu hình Vision chung. Ưu tiên: DB (studio_suggest_<key>) -> config/studio.php
     * (studio.suggest.<key>) -> default.
     */
    function studio_suggest_config(string $key, $default = null)
    {
        $stored = setting('studio_suggest_'.$key);

        return $stored !== null ? (string) $stored : config('studio.suggest.'.$key, $default);
    }
}

if (! function_exists('studio_suggest_enabled')) {
    function studio_suggest_enabled(): bool
    {
        return filter_var(studio_suggest_config('enabled', true), FILTER_VALIDATE_BOOLEAN);
    }
}

if (! function_exists('studio_suggest_provider')) {
    function studio_suggest_provider(): string
    {
        $p = strtolower(trim((string) studio_suggest_config('provider', '')));

        // '' hoặc 'auto' = đi theo MODEL REGISTRY + luồng ưu tiên (candidate nhóm 'vision'):
        // qwen → custom → flux → deepseek → gemini. Đây là mặc định: trước đây hàm này cứng
        // ['gemini','qwen'] nên DeepSeek/custom provider KHÔNG BAO GIỜ được gọi.
        // Giá trị cụ thể (qwen, gemini, deepseek, slug custom…) = ép dùng provider đó trước
        // rồi mới tới provider còn lại (hành vi cũ, giữ để tương thích).
        return $p === 'auto' ? '' : $p;
    }
}

if (! function_exists('studio_suggest_gemini_model')) {
    function studio_suggest_gemini_model(): string
    {
        $m = strtolower(trim((string) studio_suggest_config('gemini_model', 'gemini-2.5-flash')));

        // Sai provider (qwen/…) -> dùng Gemini vision mặc định.
        return str_starts_with($m, 'gemini') ? $m : 'gemini-2.5-flash';
    }
}

if (! function_exists('studio_suggest_qwen_models')) {
    /**
     * Danh sách model Qwen VISION cho "Gợi ý từ ảnh" (theo thứ tự ưu tiên).
     * Ưu tiên 1: danh sách tùy biến (studio_suggest_qwen_models).
     * Ưu tiên 2: model chính (studio_suggest_qwen_model) + fallback qwen3.8-flash / qwen-vl-*.
     */
    function studio_suggest_qwen_models(): array
    {
        $custom = array_values(array_filter(
            array_map('trim', explode(',', (string) studio_suggest_config('qwen_models', ''))),
            fn ($m) => $m !== '' && is_qwen_vision_capable($m)
        ));

        $primary = trim((string) studio_suggest_config('qwen_model', 'qwen3.8-flash'));
        $defaults = array_filter([$primary, 'qwen3.8-flash', 'qwen-vl-max', 'qwen-vl-plus'], fn ($m) => $m !== '' && is_qwen_vision_capable($m));

        return array_values(array_unique(array_filter(array_merge($custom, $defaults))));
    }
}

if (! function_exists('studio_suggest_fallback')) {
    function studio_suggest_fallback(): bool
    {
        return filter_var(studio_suggest_config('fallback', true), FILTER_VALIDATE_BOOLEAN);
    }
}

if (! function_exists('studio_suggest_include_video')) {
    function studio_suggest_include_video(): bool
    {
        return filter_var(studio_suggest_config('include_video', true), FILTER_VALIDATE_BOOLEAN);
    }
}

if (! function_exists('studio_swap_model')) {
    function studio_swap_model(): string
    {
        // "Thay Đổi Người Mẫu" dùng CHUNG model edit với Inpaint (qwen_edit_model) khi chưa cấu hình
        // swap_model riêng — tránh fallback sang model không tồn tại (vd qwen-image-edit-plus-2025-12-15).
        $explicit = studio_config('swap_model', '');

        return $explicit !== '' ? (string) $explicit : (string) studio_config('qwen_edit_model', 'qwen-image-edit-max');
    }
}

if (! function_exists('studio_vision_image_url')) {
    function studio_vision_image_url(string $url): string
    {
        // API vision cần URL công khai (http/https) — chuyển /storage/... thành absolute URL.
        if (! str_starts_with($url, 'http')) {
            return url($url);
        }

        // M07: trước đây nhận MỌI chuỗi bắt đầu bằng 'http' rồi forward thẳng cho provider
        // (provider tự đi fetch) — kể cả URL nội bộ/không mong muốn. Nay chỉ cho host của chính
        // app hoặc host trong allowlist studio.remote_image_hosts (dùng CHUNG với guard SSRF S3/N1).
        // Đường bình thường không đổi: mọi ảnh swap đều là '/storage/studio/...' cục bộ
        // (editImage -> storeRemoteImage) nên nhánh này chỉ là fallback.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $ok = $appHost !== '' && $host === $appHost;

        if (! $ok) {
            foreach (array_filter(array_map('trim', explode(',', (string) studio_config('remote_image_hosts', '')))) as $allowed) {
                $allowed = strtolower(ltrim($allowed, '.'));
                if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                    $ok = true;
                    break;
                }
            }
        }

        if (! $ok) {
            \Illuminate\Support\Facades\Log::warning('studio_vision_image_url: chặn host ngoài allowlist', ['host' => $host]);

            $path = (string) parse_url($url, PHP_URL_PATH);

            return url($path !== '' ? $path : '/');
        }

        return $url;
    }
}

if (! function_exists('studio_image_url')) {
    function studio_image_url(?string $image): ?string
    {
        // Phục vụ ảnh qua route Laravel (không phụ thuộc symlink storage) — chống ảnh vỡ ở popup.
        if (! $image) { return null; }
        if (! str_starts_with($image, '/storage/')) { return $image; }
        // URL TƯƠNG ĐỐI (không dùng url() tuyệt đối) để ảnh luôn tải từ origin hiện tại —
        // tránh 404 khi APP_URL (localhost:8000) khác domain/port người dùng đang truy cập.
        return '/api/image/'.substr($image, strlen('/storage/'));
    }
}

if (! function_exists('studio_image_thumb_url')) {
    function studio_image_thumb_url(?string $image): ?string
    {
        // URL thumbnail (160px WebP) cho HIỂN THỊ selector — giảm ~50x dung lượng so với ảnh gốc.
        // Ảnh gốc vẫn dùng studio_image_url() cho AI vision / face_ref / pose_ref.
        // CHỈ tạo thumbnail cho path AN TOÀN (chữ-số / _ . -); path đặc biệt → fallback ảnh gốc,
        // tránh 500 do ValidatePathEncoding khi URL chứa ký tự không hợp lệ.
        if (! $image) { return null; }
        if (str_starts_with($image, '/storage/')) {
            $path = substr($image, strlen('/storage/'));
            return preg_match('#^[a-zA-Z0-9/_.\-]+$#', $path) ? '/api/image-thumb/'.$path : studio_image_url($image);
        }
        $path = ltrim((string) parse_url($image, PHP_URL_PATH), '/');
        // BUG PORT: nhận cả tiền tố MỚI '/api/image/' (app phát ra) lẫn tiền tố cũ '/studio/image/'
        // còn sót trong dữ liệu đã lưu (media_url của generation cũ).
        foreach (['api/image/', 'studio/image/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $p = substr($path, strlen($prefix));
                return preg_match('#^[a-zA-Z0-9/_.\-]+$#', $p) ? '/api/image-thumb/'.$p : ($image ?: null);
            }
        }
        return null;
    }
}

if (! function_exists('studio_vision_image_data_uri')) {
    /**
     * Chuyển /storage/... hoặc đường dẫn local thành base64 data-URI cho các call VISION.
     * Provider bên ngoài (DashScope/Qwen/Gemini) KHÔNG thể fetch URL localhost, nên luôn
     * gửi pixel inline — giống cách edit model nhận ảnh (imageDataUri).
     */
    function studio_vision_image_data_uri(string $url, int $max = 1600): ?string
    {
        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        // Containment dùng chung (S1/S6 residual): chặn traversal ra ngoài 2 root public.
        $file = studio_safe_public_file($path);
        if (! $file) {
            return null;
        }

        $img = studio_image_decode($file);
        if (! $img) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $max = max(64, min(4096, $max));
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
        imagejpeg($img, null, 85);
        $data = ob_get_clean();
        imagedestroy($img);

        return 'data:image/jpeg;base64,'.base64_encode((string) $data);
    }
}

if (! function_exists('studio_api_key')) {
    /**
     * Read a provider API key. Prefers the encrypted DB value managed from the
     * Studio API page; falls back to the env/config value.
     */
    function studio_api_keys_for(?string $provider = null, ?string $model_id = null, ?string $group = null)
    {
        // M17: lọc theo 'scopes' (mảng JSON) diễn ra TRONG BỘ NHỚ sau khi nạp hết key enabled.
        // Chấp nhận có chủ đích: bảng studio_api_keys chỉ có vài chục dòng (mỗi provider 1-3 key),
        // và đẩy điều kiện này xuống SQL sẽ cần JSON_CONTAINS (MySQL) hoặc json_each (SQLite) —
        // phức tạp + khác hành vi giữa 2 hệ, không tương xứng với lợi ích ở quy mô này.
        // Mở lại nếu số key vượt ~50 (khi đó nên thêm index đa trị cho scopes).
        $q = \App\Models\StudioApiKey::query()->where('enabled', true);
        if ($provider) $q->where('provider', $provider);
        $rows = $q->orderByDesc('priority')->orderBy('id')->get();
        if ($rows->isEmpty()) return collect();
        return $rows->filter(function ($k) use ($model_id, $group) {
            $sc = $k->scopes ?? ['*'];
            if (in_array('*', $sc, true)) return true;
            if ($model_id && in_array($model_id, $sc, true)) return true;
            if ($group && in_array($group, $sc, true)) return true;
            return false;
        })->values();
    }

    function studio_api_key_value($keyOrValue): ?string
    {
        $value = is_object($keyOrValue) ? $keyOrValue->value : $keyOrValue;
        if (! $value) return null;
        try { return \Illuminate\Support\Facades\Crypt::decryptString($value); }
        catch (\Throwable $e) { return $value; }
    }

    function studio_api_key(string $service): ?string
    {
        // Registered keys first (highest-priority enabled for this provider).
        $reg = \App\Models\StudioApiKey::where('provider', $service)->where('enabled', true)->orderByDesc('priority')->orderBy('id')->first();
        if ($reg) return studio_api_key_value($reg);

        $stored = setting('api_'.$service.'_key');

        if ($stored) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($stored);
            } catch (\Throwable $e) {
                return $stored;
            }
        }

        $configKeys = [
            'gemini' => 'gemini_key',
            'fal' => 'fal_key',
            'replicate' => 'replicate_token',
            'wan' => 'wan_key',
            'veo' => 'veo_key',
            'qwen' => 'qwen_key',
            'qwen_edit' => 'qwen_edit_key',
            'dashscope' => 'dashscope_key',
            'deepseek' => 'deepseek_key',
        ];

        $key = $configKeys[$service] ?? null;

        return $key ? config('studio.'.$key) ?: null : null;
    }
}

if (! function_exists('dashscope_base_url')) {
    /**
     * QwenCloud / DashScope hosts are separate per key type and must NOT be mixed.
     *  - sk-sp-…  (Token / Coding Plan) -> token-plan host (text/code models only)
     *  - sk-ws-… / sk-… (Pay-As-You-Go) -> dashscope-intl host (image/video generation)
     */
    function dashscope_base_url(string $key): string
    {
        if (str_starts_with($key, 'sk-sp-')) {
            return rtrim((string) studio_config('dashscope_token_plan_base', 'https://token-plan.ap-southeast-1.maas.aliyuncs.com'), '/');
        }

        $payGo = rtrim((string) studio_config('dashscope_base', 'https://dashscope-intl.aliyuncs.com'), '/');

        // A Pay-As-You-Go key (sk-… / sk-ws-…) must NEVER be sent to a Token/Coding Plan host.
        // If the admin left dashscope_base pointing at a plan host, correct it to the pay-go host.
        if (str_contains($payGo, 'token-plan.') || str_contains($payGo, 'coding-')) {
            $payGo = 'https://dashscope-intl.aliyuncs.com';
        }

        return $payGo;
    }
}

if (! function_exists('studio_provider_families')) {
    /**
     * MỌI nhóm provider hợp lệ trong luồng ưu tiên — NGUỒN DUY NHẤT.
     *
     * Thứ tự ở đây = thứ tự mặc định của luồng ('other' luôn cuối vì là nhóm hứng
     * provider ngoài luồng). Thêm nhóm mới (vd một hãng khác) chỉ cần thêm token ở
     * đây + gán 'family' cho provider trong studio_provider_catalog(); cả
     * studio_provider_default_flow(), validate ở Settings và UI đều theo.
     */
    function studio_provider_families(): array
    {
        return ['qwen', 'custom', 'flux', 'deepseek', 'gemini', 'other'];
    }
}

if (! function_exists('studio_provider_default_flow')) {
    /**
     * Luồng mặc định khi admin chưa cấu hình gì: mọi nhóm theo thứ tự chuẩn, TRỪ
     * 'other' (nhóm hứng, chỉ dùng khi được gán default riêng).
     */
    function studio_provider_default_flow(): array
    {
        return array_values(array_diff(studio_provider_families(), ['other']));
    }
}

if (! function_exists('studio_provider_priority_flow')) {
    /**
     * Luồng ưu tiên NHÓM provider (fallback chain) — DeepSeek Harness style.
     *
     * Mặc định: qwen → custom → flux → deepseek → gemini. Đọc từ setting DB (tab
     * "Luồng ưu tiên" của trang Cài đặt) → env STUDIO_PROVIDER_PRIORITY → config.
     * Token không nằm trong studio_provider_families() bị bỏ.
     */
    function studio_provider_priority_flow(): array
    {
        $fallback = implode(',', studio_provider_default_flow());
        $raw = (string) studio_config('provider_priority', (string) config('studio.provider_priority', $fallback));
        $valid = studio_provider_families();
        $tokens = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($t) => $t !== '' && in_array($t, $valid, true)
        ));

        return $tokens ?: studio_provider_default_flow();
    }
}

if (! function_exists('studio_provider_family')) {
    /**
     * Nhóm luồng ưu tiên của một provider: built-in tra catalog (khoá 'family'),
     * slug khai báo trong studio_providers → 'custom', còn lại → 'other'.
     * Custom map được memo tĩnh theo request (tránh query lặp khi xếp rank).
     */
    function studio_provider_family(string $provider): string
    {
        $catalog = studio_provider_catalog();
        if (isset($catalog[$provider])) {
            return $catalog[$provider]['family'] ?? 'other';
        }

        return in_array($provider, studio_custom_provider_slugs(), true) ? 'custom' : 'other';
    }
}

if (! function_exists('studio_custom_provider_map')) {
    /**
     * slug => priority của mọi custom provider, memo theo request. Dùng chung cho
     * studio_custom_provider_slugs() (nhận diện nhóm 'custom') và
     * studio_provider_priority() (xếp thứ tự các route CÙNG nhóm).
     *
     * ⚠️ Memo PHẢI xoá được: worker queue sống qua nhiều job, còn admin thêm provider
     * mới ở request khác — cache tĩnh không có đường invalidate sẽ giữ danh sách cũ.
     * StudioProvider model event gọi studio_custom_provider_map(true) ở mọi đường ghi.
     */
    function studio_custom_provider_map(bool $forget = false): array
    {
        static $map = null;

        if ($forget) {
            $map = null;
        }

        if ($map === null) {
            $map = [];
            try {
                try {
                    // Cột priority có thể chưa migrate ở deployment cũ — fallback bên dưới.
                    $rows = \App\Models\StudioProvider::query()->get(['slug', 'priority']);
                } catch (\Throwable $e) {
                    $rows = \App\Models\StudioProvider::query()->get(['slug']);
                }
                foreach ($rows as $row) {
                    $map[$row->slug] = (int) ($row->priority ?? 5);
                }
            } catch (\Throwable $e) {
                $map = [];
            }
        }

        return $map;
    }
}

if (! function_exists('studio_custom_provider_slugs')) {
    /**
     * Slug của mọi custom provider (bảng studio_providers). Xem studio_custom_provider_map().
     */
    function studio_custom_provider_slugs(bool $forget = false): array
    {
        return array_keys(studio_custom_provider_map($forget));
    }
}

if (! function_exists('studio_provider_priority')) {
    /**
     * Độ ưu tiên CỦA PROVIDER trong nội bộ nhóm (lớn hơn = thử trước). Built-in lấy từ
     * catalog, custom lấy từ cột studio_providers.priority. Những provider cùng rank
     * nhóm (ví dụ nhiều custom provider) được phân định bằng số này.
     */
    function studio_provider_priority(string $provider): int
    {
        $catalog = studio_provider_catalog();
        if (isset($catalog[$provider])) {
            return (int) ($catalog[$provider]['priority'] ?? 5);
        }

        return (int) (studio_custom_provider_map()[$provider] ?? 5);
    }
}

if (! function_exists('studio_provider_rank')) {
    /**
     * Số nguyên xếp hạng provider trong luồng ưu tiên (nhỏ hơn = dùng trước):
     * vị trí nhóm trong flow × 10. Nhóm không có trong flow (hoặc 'other') = 990,
     * đứng sau mọi nhóm đã cấu hình. Rank chỉ áp SAU default đã gán của nhóm công
     * việc — admin override vẫn luôn thắng.
     */
    function studio_provider_rank(string $provider): int
    {
        $family = studio_provider_family($provider);
        $flow = studio_provider_priority_flow();
        $idx = array_search($family, $flow, true);

        return $idx === false ? 990 : (int) ($idx * 10);
    }
}

if (! function_exists('studio_sort_by_provider_rank')) {
    /**
     * Xếp danh sách model theo luồng ưu tiên: rank provider tăng dần (qwen trước,
     * custom sau, rồi flux, gemini) → ưu tiên PROVIDER giảm dần (phân định các route
     * cùng nhóm, vd nhiều custom provider) → priority model giảm dần → id tăng.
     * Input/output: mảng các dòng registry (array) hoặc candidate.
     */
    function studio_sort_by_provider_rank(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $ra = studio_provider_rank((string) ($a['provider'] ?? ''));
            $rb = studio_provider_rank((string) ($b['provider'] ?? ''));
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            // Cùng nhóm (vd nhiều custom provider): ưu tiên CỦA PROVIDER quyết định
            // route nào thử trước, rồi mới tới ưu tiên của từng model.
            $qa = studio_provider_priority((string) ($a['provider'] ?? ''));
            $qb = studio_provider_priority((string) ($b['provider'] ?? ''));
            if ($qa !== $qb) {
                return $qb <=> $qa;
            }
            $pa = (int) ($a['priority'] ?? 0);
            $pb = (int) ($b['priority'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }

            return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });

        return $rows;
    }
}

if (! function_exists('studio_provider_catalog')) {
    /**
     * BUILT-IN provider directory (the part every deployment ships — the DeepSeek
     * Harness "configurable-provider directory" analog). Custom providers stored in
     * the studio_providers table are MERGED on top by studio_provider_registry().
     *
     * protocol: openai (Bearer + /chat/completions) | dashscope (Bearer + /api/v1
     * multimodal generation) | gemini (x-goog-api-key + generateContent).
     */
    function studio_provider_catalog(): array
    {
        // 'family' = NHÓM trong luồng ưu tiên (config studio.provider_priority, mặc định
        // qwen,custom,flux,gemini) — dùng để xếp rank provider khi chọn model (xem
        // studio_provider_rank). Custom providers (bảng studio_providers) luôn thuộc
        // nhóm 'custom'.
        return [
            'qwen' => ['name' => 'Qwen — QwenCloud (ảnh · video · suy luận)', 'protocol' => 'dashscope', 'family' => 'qwen', 'priority' => 10, 'hint' => 'QWEN_API_KEY · home.qwencloud.com/api-keys · ảnh qua dashscope-intl, chat qua compatible-mode/v1'],
            'qwen_edit' => ['name' => 'Qwen Edit — sửa ảnh / Inpaint / thử đồ', 'protocol' => 'dashscope', 'family' => 'qwen', 'priority' => 9, 'hint' => 'QWEN_EDIT_KEY · qwen-image-edit-2511 / qwen-image-edit'],
            'dashscope' => ['name' => 'DashScope — Wan/Qwen image & video (Alibaba)', 'protocol' => 'dashscope', 'family' => 'qwen', 'priority' => 8, 'hint' => 'DASHSCOPE_API_KEY (pay-go sk-… / plan sk-sp-…)'],
            'wan' => ['name' => 'Wan — video catwalk (Wan3.0)', 'protocol' => 'dashscope', 'family' => 'qwen', 'priority' => 7, 'hint' => 'WAN_API_KEY / DASHSCOPE_API_KEY'],
            'gemini' => ['name' => 'Gemini — suy luận / ảnh (tùy chọn)', 'protocol' => 'gemini', 'family' => 'gemini', 'priority' => 10, 'hint' => 'GEMINI_API_KEY (aistudio.google.com) — nhóm CUỐI trong luồng ưu tiên'],
            'veo' => ['name' => 'Google Veo — video', 'protocol' => 'gemini', 'family' => 'gemini', 'priority' => 9, 'hint' => 'GOOGLE_VEO_KEY'],
            'fal' => ['name' => 'Fal.ai — Flux (fallback ảnh)', 'protocol' => 'openai', 'family' => 'flux', 'priority' => 10, 'hint' => 'FAL_KEY (fal.ai/dashboard/keys) — queue.fal.run, auth "Key …"'],
            'replicate' => ['name' => 'Replicate — Flux (ảnh)', 'protocol' => 'openai', 'family' => 'flux', 'priority' => 5, 'hint' => 'REPLICATE_API_TOKEN (dùng qua custom provider để gọi trực tiếp)'],
            // base_url khai báo được vì DeepSeek dùng transport OpenAI-compatible chung
            // (chat/completions, ảnh qua image_url) — xem StyleSuggestService::suggestViaOpenAiVision().
            'deepseek' => ['name' => 'DeepSeek — ngôn ngữ / suy luận (đa phương thức)', 'protocol' => 'openai', 'family' => 'deepseek', 'priority' => 5, 'base_url' => 'https://api.deepseek.com', 'hint' => 'DEEPSEEK_API_KEY · deepseek-flash (đọc được ảnh) / deepseek-v4-pro · đứng trước Gemini trong luồng'],
        ];
    }
}

if (! function_exists('studio_provider_templates')) {
    /**
     * MẪU khai báo custom provider — chỉ là DỮ LIỆU điền sẵn form, không phải đường
     * code riêng cho từng hãng. Trước đây CKEY bị gắn cứng trong UI; nay nó chỉ là
     * một mục trong danh sách này, ngang hàng với OpenRouter/Together/Groq…
     *
     * Mỗi mục: label (tên hiển thị), docs (link tài liệu), protocol, base_url,
     * auth_style, api_key_ref (slug nhóm key gợi ý), note (ghi chú lưu vào registry),
     * tags (nhãn nhỏ trong UI), image_path (endpoint sinh ảnh của gateway, nếu có).
     *
     * Thêm gateway mới = thêm một mục ở đây; không cần sửa transport hay service.
     */
    function studio_provider_templates(): array
    {
        return [
            'openai-compatible' => [
                'label' => 'OpenAI-compatible (tổng quát)',
                'slug' => 'gateway',
                'protocol' => 'openai',
                'base_url' => 'https://api.example.com/v1',
                'auth_style' => 'bearer',
                'api_key_ref' => 'gateway',
                'note' => 'Gateway OpenAI-compatible: chat /chat/completions · ảnh /images/generations',
                'docs' => '',
                'tags' => ['chung'],
                'image_path' => '/images/generations',
            ],
            'ckey' => [
                'label' => 'CKEY — gateway Việt Nam (api.xah.io)',
                'slug' => 'ckey',
                'protocol' => 'openai',
                'base_url' => 'https://api.xah.io/v1',
                'auth_style' => 'bearer',
                'api_key_ref' => 'ckey',
                'note' => 'CKEY · ảnh /v1/images/generations · chat /v1/chat/completions · bảng giá VND',
                'docs' => 'https://ckey.vn/docs',
                'tags' => ['VN', 'ảnh', 'chat'],
                'image_path' => '/images/generations',
            ],
            'openrouter' => [
                'label' => 'OpenRouter',
                'slug' => 'openrouter',
                'protocol' => 'openai',
                'base_url' => 'https://openrouter.ai/api/v1',
                'auth_style' => 'bearer',
                'api_key_ref' => 'openrouter',
                'note' => 'OpenRouter · 300+ model qua một khoá · chat /chat/completions',
                'docs' => 'https://openrouter.ai/docs',
                'tags' => ['chat', 'vision'],
                'image_path' => null,
            ],
            'together' => [
                'label' => 'Together AI',
                'slug' => 'together',
                'protocol' => 'openai',
                'base_url' => 'https://api.together.xyz/v1',
                'auth_style' => 'bearer',
                'api_key_ref' => 'together',
                'note' => 'Together AI · FLUX/SDXL + LLM mở · ảnh /images/generations',
                'docs' => 'https://docs.together.ai',
                'tags' => ['ảnh', 'flux'],
                'image_path' => '/images/generations',
            ],
            'groq' => [
                'label' => 'Groq (suy luận nhanh)',
                'slug' => 'groq',
                'protocol' => 'openai',
                'base_url' => 'https://api.groq.com/openai/v1',
                'auth_style' => 'bearer',
                'api_key_ref' => 'groq',
                'note' => 'Groq LPU · chat cực nhanh cho prompt/translate',
                'docs' => 'https://console.groq.com/docs',
                'tags' => ['chat', 'nhanh'],
                'image_path' => null,
            ],
            'siliconflow' => [
                'label' => 'SiliconFlow',
                'slug' => 'siliconflow',
                'protocol' => 'openai',
                'base_url' => 'https://api.siliconflow.com/v1',
                'auth_style' => 'bearer',
                'api_key_ref' => 'siliconflow',
                'note' => 'SiliconFlow · Qwen/FLUX + LLM mở',
                'docs' => 'https://docs.siliconflow.com',
                'tags' => ['ảnh', 'qwen'],
                'image_path' => '/images/generations',
            ],
            'deepinfra' => [
                'label' => 'DeepInfra',
                'slug' => 'deepinfra',
                'protocol' => 'openai',
                'base_url' => 'https://api.deepinfra.com/v1/openai',
                'auth_style' => 'bearer',
                'api_key_ref' => 'deepinfra',
                'note' => 'DeepInfra · FLUX/Qwen image + LLM mở',
                'docs' => 'https://deepinfra.com/docs',
                'tags' => ['ảnh', 'chat'],
                'image_path' => null,
            ],
            'dashscope-intl' => [
                'label' => 'DashScope quốc tế (QwenCloud)',
                'slug' => 'dashscope_intl',
                'protocol' => 'dashscope',
                'base_url' => 'https://dashscope-intl.aliyuncs.com',
                'auth_style' => 'bearer',
                'api_key_ref' => 'dashscope_intl',
                'note' => 'DashScope international · multimodal-generation + /api/v1/tasks',
                'docs' => 'https://docs.qwencloud.com',
                'tags' => ['qwen', 'ảnh', 'video'],
                'image_path' => '/api/v1/services/aigc/multimodal-generation/generation',
            ],
            'custom-gemini' => [
                'label' => 'Gemini-compatible (x-goog-api-key)',
                'slug' => 'gemini_proxy',
                'protocol' => 'gemini',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'auth_style' => 'x-goog-api-key',
                'api_key_ref' => 'gemini_proxy',
                'note' => 'Gemini generateContent qua proxy/tài khoản riêng',
                'docs' => 'https://ai.google.dev/api',
                'tags' => ['gemini'],
                'image_path' => null,
            ],
        ];
    }
}
if (! function_exists('studio_provider_registry')) {
    /**
     * The FULL provider directory: built-in catalog + user-declared custom routes
     * (studio_providers rows), each tagged 'custom' => true/false. This is the single
     * list the Settings SPA renders — it can never disagree with what resolution uses.
     */
    function studio_provider_registry(): array
    {
        $registry = [];
        foreach (studio_provider_catalog() as $slug => $meta) {
            $registry[$slug] = [
                'slug' => $slug,
                'name' => $meta['name'],
                'protocol' => $meta['protocol'],
                // Built-in thường dùng transport viết tay (không cần base_url), nhưng provider
                // OpenAI-compatible khai báo được base_url trong catalog (vd deepseek).
                'base_url' => $meta['base_url'] ?? null,
                'auth_style' => $meta['protocol'] === 'gemini' ? 'x-goog-api-key' : 'bearer',
                'hint' => $meta['hint'] ?? null,
                'family' => $meta['family'] ?? 'other',
                'rank' => studio_provider_rank($slug),
                'priority' => studio_provider_priority($slug),
                'custom' => false,
                'enabled' => true,
            ];
        }

        try {
            foreach (\App\Models\StudioProvider::orderBy('id')->get() as $p) {
                $registry[$p->slug] = [
                    'slug' => $p->slug,
                    'name' => $p->name,
                    'protocol' => $p->protocol,
                    'base_url' => $p->base_url,
                    'auth_style' => $p->auth_style,
                    'hint' => $p->note,
                    'family' => 'custom',
                    'rank' => studio_provider_rank($p->slug),
                    'priority' => (int) ($p->priority ?? 5),
                    'id' => (int) $p->id,
                    'custom' => true,
                    'enabled' => (bool) $p->enabled,
                ];
            }
        } catch (\Throwable $e) {
            // Table not migrated yet — built-ins only, never a 500.
        }

        return $registry;
    }
}

if (! function_exists('studio_custom_provider')) {
    /**
     * One user-declared provider profile by route key, or null when the key names a
     * built-in (or nothing). This is how call sites branch: built-ins keep their
     * hand-written transport, custom routes go through studio_custom_provider_call().
     */
    function studio_custom_provider(string $slug): ?array
    {
        try {
            $p = \App\Models\StudioProvider::where('slug', $slug)->where('enabled', true)->first();
        } catch (\Throwable $e) {
            return null;
        }
        if (! $p) {
            return null;
        }

        return [
            'slug' => $p->slug,
            'name' => $p->name,
            'protocol' => (string) $p->protocol,
            'base_url' => rtrim((string) $p->base_url, '/'),
            'auth_style' => (string) $p->auth_style,
            // Tham số bật tìm kiếm web của gateway này (rỗng = chưa khai). Quyết định "có tìm kiếm" đến
            // từ CÀI ĐẶT chứ không từ danh sách nhà cung cấp viết cứng trong mã — xem WebAccessService.
            'search_param' => trim((string) ($p->search_param ?? '')) ?: null,
            'search_mode' => trim((string) ($p->search_mode ?? '')) ?: null,
            'api_key_ref' => $p->api_key_ref ?: $p->slug,
        ];
    }
}

if (! function_exists('studio_custom_provider_key')) {
    /**
     * Resolve the API key for a custom provider: its api_key_ref slot first, then the
     * slug itself — both through the same StudioApiKey/env fallback as built-ins.
     */
    function studio_custom_provider_key(array $provider): ?string
    {
        foreach (array_filter([$provider['api_key_ref'] ?? null, $provider['slug'] ?? null]) as $ref) {
            $key = studio_api_key((string) $ref);
            if ($key) {
                return $key;
            }
        }
        return null;
    }
}

if (! function_exists('studio_custom_provider_call')) {
    /**
     * Transport for user-declared provider routes. Speaks the three wire protocols
     * this codebase already uses:
     *   - openai:  POST {base}/chat/completions, Bearer auth (works for every
     *              [OI]-compatible gateway: OpenRouter, Together, Groq, vLLM…)
     *   - dashscope: POST {base}/api/v1/services/aigc/multimodal-generation/generation
     *              (image generation on any DashScope-compatible host)
     *   - gemini:  POST {base}/v1beta/models/{model}:generateContent, x-goog-api-key
     * Returns the decoded JSON body on success, null otherwise.
     */
    function studio_custom_provider_call(array $provider, string $model, string $prompt, array $extra = [])
    {
        $key = studio_custom_provider_key($provider);
        if (! $key) {
            return null;
        }

        $base = (string) ($provider['base_url'] ?? '');
        if ($base === '') {
            return null;
        }

        $protocol = (string) ($provider['protocol'] ?? 'openai');

        try {
            if ($protocol === 'gemini') {
                $url = $base.'/v1beta/models/'.$model.':generateContent';
                $headerName = (($provider['auth_style'] ?? 'bearer') === 'x-goog-api-key') ? 'x-goog-api-key' : 'Authorization';
                $headers = [$headerName => ($headerName === 'Authorization' ? 'Bearer '.$key : $key)];
                $resp = \Illuminate\Support\Facades\Http::withHeaders($headers)->timeout(120)->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                ]);
            } elseif ($protocol === 'dashscope') {
                $url = $base.'/api/v1/services/aigc/multimodal-generation/generation';
                $resp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(180)->post($url, array_merge([
                    'model' => $model,
                    'input' => ['prompt' => $prompt],
                ], $extra));
            } else {
                $url = $base.'/chat/completions';
                $messages = $extra['messages'] ?? [['role' => 'user', 'content' => $prompt]];
                $resp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(120)->post($url, array_merge([
                    'model' => $model,
                ], $extra, ['messages' => $messages]));
            }

            return $resp->successful() ? $resp->json() : null;
        } catch (\Throwable $e) {
            logger()->warning('Custom provider call failed ('.($provider['slug'] ?? '?').'): '.$e->getMessage());
            return null;
        }
    }
}

if (! function_exists('studio_custom_provider_image_call')) {
    /**
     * SINH ẢNH qua custom provider — hỗ trợ đủ 3 giao thức:
     *   - openai:    POST {base}/images/generations (OpenAI Images API: model, prompt, n,
     *                size). Đây chính là wire format của các gateway [OI]-compatible bán
     *                model ảnh — điển hình CKEY Việt Nam (https://ckey.vn/docs, base
     *                https://api.xah.io/v1, Bearer key, model dạng adminsgehdt/qwen-image-max).
     *   - dashscope: POST {base}/api/v1/services/aigc/multimodal-generation/generation.
     *   - gemini:    POST {base}/v1beta/models/{model}:generateContent.
     * Trả về JSON đã decode khi thành công, null khi lỗi/key thiếu. Bên gọi tự trích
     * URL/base64 (data.0.url | data.0.b64_json | output.choices… | inlineData.data).
     */
    function studio_custom_provider_image_call(array $provider, string $model, string $prompt, array $extra = [])
    {
        $key = studio_custom_provider_key($provider);
        if (! $key) {
            return null;
        }

        $base = rtrim((string) ($provider['base_url'] ?? ''), '/');
        if ($base === '') {
            return null;
        }

        $protocol = (string) ($provider['protocol'] ?? 'openai');

        try {
            if ($protocol === 'openai') {
                $url = $base.'/images/generations';
                $body = array_merge([
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => 1,
                ], $extra);

                $resp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(180)->post($url, $body);

                return $resp->successful() ? $resp->json() : null;
            }

            // dashscope / gemini: dùng chung transport chat-style có sẵn.
            return studio_custom_provider_call($provider, $model, $prompt, $extra);
        } catch (\Throwable $e) {
            logger()->warning('Custom provider image call failed ('.($provider['slug'] ?? '?').'): '.$e->getMessage());

            return null;
        }
    }
}

if (! function_exists('is_qwen_quota_error')) {
    /**
     * Whether a DashScope/QwenCloud message/body indicates quota exhaustion (Throttling.AllocationQuota).
     */
    function is_qwen_quota_error(?string $message): bool
    {
        if (! $message) {
            return false;
        }
        $lower = strtolower($message);

        return str_contains($lower, 'allocationquota') || str_contains($lower, 'throttling') || str_contains($message, '429');
    }
}

if (! function_exists('studio_qwen_credentials')) {
    /**
     * Ordered Qwen/DashScope API keys to try for a task, with automatic failover:
     *   - image / video / prompt / vision: Token Plan (sk-sp-…) first, then Pay-As-You-Go (sk-…/sk-ws-…)
     *   - edit (Inpaint): Pay-As-You-Go first (edit models usually live on the pay-go host), then Token Plan.
     * Keys are gathered from every Qwen/DashScope slot and ordered by their prefix.
     */
    if (! function_exists('deepseek_base_url')) {
    function deepseek_base_url(string $key): string
    {
        return (string) config('studio.deepseek_base', 'https://api.deepseek.com');
    }
}


function studio_qwen_credentials(string $task = 'image', ?string $model_id = null): array
    {
        // Registered keys (multi per provider, scope-aware) — fall back to env/config slots.
        $keys = [];
        foreach (['qwen', 'dashscope', 'qwen_edit', 'wan'] as $p) {
            foreach (studio_api_keys_for($p, $model_id, $task === 'edit' ? 'edit' : ($task === 'video' ? 'video' : 'image')) as $k) {
                $v = studio_api_key_value($k);
                if ($v) $keys[] = $v;
            }
        }
        $keys = array_merge($keys, array_values(array_unique(array_filter([
            studio_api_key('qwen'), studio_api_key('dashscope'), studio_api_key('qwen_edit'), studio_api_key('wan'),
        ]))));
        $keys = array_values(array_unique(array_filter($keys)));

        $plan = [];
        $paygo = [];
        foreach ($keys as $k) {
            if (str_starts_with($k, 'sk-sp-')) {
                $plan[] = $k;
            } else {
                $paygo[] = $k;
            }
        }

        // GEN (image/video/edit) models live on the Pay-As-You-Go host; TEXT/Chat (prompt/vision) live on
        // the Token/Coding-Plan host. Order accordingly so a video/image request isn't sent to the plan host
        // (which has no image/video model -> "Model not exist") and doesn't burn the free-tier plan quota.
        return in_array($task, ['prompt', 'vision'], true)
            ? array_merge($plan, $paygo)   // text/vision: plan first
            : array_merge($paygo, $plan);  // image/video/edit: pay-go first
    }
}

if (! function_exists('capture_provider_quota_reset')) {
    /**
     * Extract the provider's "quota will reset at <time> UTC" from a quota error and store it
     * so the UI can show when the limit resets.
     */
    function capture_provider_quota_reset(?string $message): void
    {
        if (! $message) {
            return;
        }
        if (preg_match('/reset(?:s| will reset)? at ([0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} UTC)/i', $message, $m)) {
            set_setting('studio_provider_quota_resets_at', $m[1]);
        }
    }
}

if (! function_exists('studio_usage')) {
    /**
     * Real token/credit usage summary (from the DB) + the provider's last-known quota reset time.
     */
    function studio_usage($user = null): array
    {
        $user = $user ?? auth()->user();
        $q = $user ? $user->generations()->where('status', 'completed') : null;

        return [
            // [Q4] Thành viên nhóm ⇒ số dư là của CHỦ NHÓM (người trả tiền), không phải tài khoản phụ.
            'balance' => $user ? (int) $user->billingBalance() : 0,
            'used_total' => $q ? (int) $q->sum('credits_cost') : 0,
            'used_today' => $q ? (int) $q->whereDate('created_at', today())->sum('credits_cost') : 0,
            'limit' => (int) studio_config('quota_limit', 0),
            'quota_resets_at' => (string) setting('studio_provider_quota_resets_at', ''),
        ];
    }
}

if (! function_exists('studio_plan_limits')) {
    /**
     * Giới hạn của GÓI đang có hiệu lực — dùng để THỰC THI đặc quyền của gói.
     *
     * Vì sao: plans.resolution_cap trước đây chỉ được trả về ở API catalog, KHÔNG chỗ nào trong
     * pipeline kiểm tra ⇒ gói Miễn phí (1K) và gói Studio (2K) cho ra ảnh giống hệt nhau, tức
     * đặc quyền của gói chỉ là chữ trang trí.
     *
     * Quy ước: cap ảnh theo plans.resolution_cap ('1K'|'2K'); cap video suy ra từ cap ảnh
     * (1K ⇒ 720p, 2K ⇒ 1080p). Không có gói / gói hết hạn ⇒ dùng mặc định toàn cục — GIỮ NGUYÊN
     * hành vi cũ cho tài khoản chưa gán gói.
     */
    function studio_plan_limits($user = null): array
    {
        $user = $user ?? auth()->user();

        $plan = null;
        try {
            $plan = $user?->activePlan();
        } catch (\Throwable $e) {
            $plan = null;
        }

        $imageCap = (string) ($plan->resolution_cap ?? studio_config('image_resolution', '2K'));
        if (! in_array($imageCap, ['1K', '2K'], true)) {
            $imageCap = '2K';
        }

        return [
            'plan' => $plan,
            'image_resolution_cap' => $imageCap,
            'video_resolution_cap' => $imageCap === '1K' ? '720' : '1080',
            // [Q1 — 2026-09-19] Chủ dự án đã quyết: BẬT chặn khi hết credit (mặc định nay là true).
            // Đổi mặc định ở ĐÚNG MỘT CHỖ này; muốn tạm mở lại thì đặt setting studio_enforce_credits=0
            // trong Quản trị (không cần sửa mã).
            'enforce_credits' => filter_var(studio_config('enforce_credits', true), FILTER_VALIDATE_BOOLEAN),
        ];
    }
}

if (! function_exists('module_enabled')) {
    /** [Modules 2026-09-19] Module có đang BẬT toàn cục không (chủ dự án tắt được trong Quản trị). */
    function module_enabled(string $id): bool
    {
        return \App\Support\ModuleRegistry::enabledGlobally($id);
    }
}

if (! function_exists('module_allowed')) {
    /**
     * [Modules 2026-09-19] Người dùng này CÓ QUYỀN dùng module không? Đây là chỗ DUY NHẤT quyết định:
     *   1) module phải đang bật toàn cục, và
     *   2) module phải nằm trong `plans.modules` của gói người dùng đang có, và
     *   3) các module nó phụ thuộc cũng phải được cấp (vd 'batch' cần 'prompt').
     *
     * Super Admin được coi là có mọi module đang bật: đây là tài khoản của chính chủ dự án, và các module
     * còn là công cụ nội bộ (cùng lý do đã miễn chặn credit cho owner — xem queueGeneration).
     */
    function module_allowed($user, string $id): bool
    {
        $registry = \App\Support\ModuleRegistry::class;

        if (! $registry::has($id) || ! $registry::enabledGlobally($id)) {
            return false;
        }

        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        // Tài khoản NỘI BỘ (admin/super admin) không bị công tắc gói chặn: quyền theo gói là chuyện của
        // KHÁCH mua gói, còn nhân sự vận hành cần vào được mọi màn để hỗ trợ khách.
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return true;
        }

        foreach ($registry::get($id)['depends_on'] as $parent) {
            if (! module_allowed($user, (string) $parent)) {
                return false;
            }
        }

        // Không có gói (tài khoản cũ / chưa gán) ⇒ dùng GÓI MẶC ĐỊNH (thường là Miễn phí) thay vì khoá
        // sạch tính năng — xem Plan::defaultPlan().
        $plan = $user->activePlan() ?? \App\Models\Plan::defaultPlan();

        return $plan !== null && $plan->grantsModule($id);
    }
}

if (! function_exists('user_modules')) {
    /** @return array<int, string> id các module người dùng được dùng (đã tính bật/tắt + phụ thuộc). */
    function user_modules($user = null): array
    {
        $user = $user ?? auth()->user();

        return array_values(array_filter(\App\Support\ModuleRegistry::ids(), fn ($id) => module_allowed($user, $id)));
    }
}

if (! function_exists('modules_status')) {
    /**
     * Trạng thái TỪNG module cho giao diện: được dùng hay bị khoá, và vì sao.
     * Trả đủ cả module bị khoá để Studio hiện ổ khóa + gợi ý nâng cấp thay vì giấu đi (khách cần biết
     * gói cao hơn có gì mới mua).
     */
    function modules_status($user = null): array
    {
        $user = $user ?? auth()->user();
        $plan = $user?->activePlan() ?? \App\Models\Plan::defaultPlan();
        $disabled = \App\Support\ModuleRegistry::disabledGlobally();

        return array_map(function (array $m) use ($user, $plan, $disabled) {
            $id = $m['id'];
            $allowed = module_allowed($user, $id);
            $reason = null;
            if (! $allowed) {
                if (in_array($id, $disabled, true)) {
                    $reason = 'disabled';
                } elseif ($plan === null) {
                    $reason = 'no_plan';
                } elseif (! $plan->grantsModule($id)) {
                    $reason = 'plan';
                } else {
                    $reason = 'dependency';
                }
            }

            return [
                'id' => $id,
                'name' => $m['name'],
                'group' => $m['group'],
                'kind' => $m['kind'],
                'gui' => ! empty($m['gui']),
                'icon' => $m['icon'] ?? 'square',
                'allowed' => $allowed,
                'reason' => $reason,
                'depends_on' => $m['depends_on'] ?? [],
                'in_plan' => $plan ? $plan->grantsModule($id) : false,
            ];
        }, \App\Support\ModuleRegistry::all());
    }
}

if (! function_exists('team_can_view_project')) {
    /**
     * [Q4 — 2026-09-19] Ai được XEM/LÀM VIỆC trên một bộ sưu tập: chủ bộ sưu tập · thành viên trong
     * nhóm của họ · Super Admin. Thành viên làm việc chung bộ sưu tập của chủ nhóm — đó là toàn bộ ý
     * nghĩa của "ghế"; nhưng KHÔNG được xoá bộ sưu tập hay đổi trạng thái (việc của chủ nhóm).
     */
    function team_can_view_project($actor, $project): bool
    {
        if (! $actor || ! $project) {
            return false;
        }

        if ($actor->isSuperAdmin() || (int) $project->user_id === (int) $actor->id) {
            return true;
        }

        return $actor->isTeamMember() && (int) $project->user_id === (int) $actor->team_owner_id;
    }
}

if (! function_exists('studio_project_writable_by')) {
    /**
     * [P0.3 — 2026-09-20] Ai được GHI ẢNH MỚI vào một bộ sưu tập: chủ bộ sưu tập, hoặc THÀNH VIÊN
     * trong nhóm của chủ (ghế dùng chung).
     *
     * Vì sao cần: gói có SỐ GHẾ hứa với khách "cả nhóm dùng chung credit và chung bộ sưu tập"
     * (resources/views/pricing.blade.php), ProjectController::index() cố ý trả bộ sưu tập của chủ
     * nhóm cho thành viên, và giao diện hiện nút «Áp dụng bộ sưu tập này» cho mọi dòng. Nhưng
     * /api/generate, /api/video và resolveProjectId() lại chỉ chấp nhận bộ sưu tập có
     * `user_id = người bấm` ⇒ thành viên bấm vào là 422 (hoặc bị bỏ qua im lặng ở endpoint phái
     * sinh) và ảnh rơi ra ngoài bộ sưu tập. Đây là mâu thuẫn giữa lời hứa và cổng quyền.
     *
     * Đọc thì đã mở cho thành viên (team_can_view_project) — ghi cũng phải mở tương ứng, nếu không
     * "làm việc chung" là tính năng chết ngay ở bước đầu tiên.
     */
    function studio_project_writable_by($actor, $projectId): bool
    {
        $id = (int) $projectId;
        if (! $actor || $id <= 0) {
            return false;
        }

        $project = \App\Models\Project::find($id);
        if (! $project) {
            return false;
        }

        if ((int) $project->user_id === (int) $actor->id || $actor->isSuperAdmin()) {
            return true;
        }

        return $actor->isTeamMember() && (int) $project->user_id === (int) $actor->team_owner_id;
    }
}

if (! function_exists('team_can_manage_project')) {
    /** [Q4] Ai được XOÁ/ĐỔI TRẠNG THÁI bộ sưu tập: chủ bộ sưu tập hoặc Super Admin (thành viên: không). */
    function team_can_manage_project($actor, $project): bool
    {
        return (bool) $actor && (bool) $project
            && ($actor->isSuperAdmin() || (int) $project->user_id === (int) $actor->id);
    }
}

if (! function_exists('studio_credit_balance')) {
    /**
     * [Q4 — 2026-09-19] Số dư credit THẬT của phiên làm việc: thành viên nhóm dùng bể credit của chủ
     * nhóm. Một chỗ duy nhất để giao diện và pipeline nói cùng một con số.
     */
    function studio_credit_balance($user = null): int
    {
        $user = $user ?? auth()->user();

        return $user ? (int) $user->billingBalance() : 0;
    }
}

if (! function_exists('studio_team_seats')) {
    /**
     * [Q4] Tình trạng ghế của nhóm: tổng ghế theo gói · đã dùng · còn lại · có phải thành viên không.
     * Giao diện dùng đúng số này (không tự đếm ở client).
     */
    function studio_team_seats($user = null): array
    {
        $user = $user ?? auth()->user();
        $team = app(\App\Services\TeamService::class);
        $owner = $team->ownerOf($user);

        if (! $user || ! $owner) {
            return ['limit' => 1, 'used' => 1, 'remaining' => 0, 'is_member' => false, 'owner' => null];
        }

        // Tính MỘT lần rồi suy ra phần còn lại: gọi usedSeats() và remainingSeats() riêng sẽ đếm hai
        // lần cùng một con số (bắt được bằng ngân sách query của /api/boot).
        $limit = $team->seatLimit($owner);
        $used = $team->usedSeats($owner);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'is_member' => $user->isTeamMember(),
            'owner' => ['id' => $owner->id, 'name' => $owner->name],
        ];
    }
}

if (! function_exists('studio_credit_cost')) {
    /**
     * Chi phí credit của MỘT thao tác — ƯU TIÊN THEO GÓI, fallback về setting toàn cục.
     *
     * Vì sao: plans.image_credit_cost / video_credit_cost đã có trong lược đồ và trong trang Quản
     * trị, nhưng pipeline lại luôn đọc setting toàn cục studio_config('image_credits') ⇒ cột của
     * gói là trang trí, mọi gói tiêu credit như nhau. Hàm này là ĐƯỜNG DUY NHẤT để lấy chi phí.
     *
     * Tương thích ngược: gói để mặc định (1 credit/ảnh · 10 credit/video) cho kết quả y hệt
     * setting cũ; gói hết hạn hoặc chưa gán gói cũng rơi về mặc định toàn cục.
     *
     * @param  string  $kind  'image' | 'video'
     */
    function studio_credit_cost(string $kind = 'image', $user = null): int
    {
        $user = $user ?? auth()->user();

        $plan = null;
        try {
            $plan = $user?->activePlan();
        } catch (\Throwable $e) {
            // Không có bảng plans / lỗi truy vấn: dùng mặc định toàn cục, KHÔNG làm hỏng pipeline.
            $plan = null;
        }

        $fromPlan = $kind === 'video' ? (int) ($plan->video_credit_cost ?? 0) : (int) ($plan->image_credit_cost ?? 0);
        if ($fromPlan > 0) {
            return $fromPlan;
        }

        return $kind === 'video'
            ? (int) studio_config('video_credits', 10)
            : (int) studio_config('image_credits', 1);
    }
}


/**
 * Studio model registry — dynamic per group (image | video | inference).
 * Falls back to a built-in catalog when nothing is registered yet (so it works out-of-the-box).
 */
if (! function_exists('studio_model_catalog')) {
    /**
     * Catalog model TÍCH HỢP — "model QwenCloud mới nhất" theo qwencloud.com/models
     * (kiểm tra 2026-09-17), tập trung Qwen làm nhà cung cấp chính, flux làm fallback,
     * gemini tùy chọn (cuối luồng). Đây là nguồn cho:
     *   · fallback khi registry (studio_models) còn trống — chạy được ngay out-of-the-box;
     *   · lệnh đồng bộ "php artisan studio:sync-models" (và nút Đồng bộ ở tab Luồng ưu
     *     tiên) — idempotent theo (group, provider, model_id), nên khi QwenCloud ra
     *     model mới, cập nhật MẢNG NÀY rồi chạy lại lệnh là registry được làm mới.
     *
     * Thứ tự thực tế khi chọn model: default gán cho nhóm công việc → rank provider
     * theo luồng (qwen → custom → flux → gemini) → priority trong cùng provider.
     */
    function studio_model_catalog(): array
    {
        return [
            // ── IMAGE — Tạo Ảnh 2D / ảnh từ ảnh mẫu (ConceptCard, RefImageCard) ──
            ['group' => 'image', 'name' => 'Qwen Image 3.0 Pro', 'provider' => 'qwen', 'model_id' => 'qwen-image-3.0-pro', 'api_key_ref' => 'qwen', 'priority' => 10, 'note' => 'QwenCloud mới nhất — chữ dày đặc 10px, 12 ngôn ngữ, ảnh-trong-ảnh'],
            ['group' => 'image', 'name' => 'Qwen Image 2512 (base)', 'provider' => 'qwen', 'model_id' => 'qwen-image-2512', 'api_key_ref' => 'qwen', 'priority' => 8, 'note' => 'Rẻ hơn — ảnh chuẩn sàn TMĐT (~$0.02/MP)'],
            ['group' => 'image', 'name' => 'Qwen Image (base)', 'provider' => 'qwen', 'model_id' => 'qwen-image', 'api_key_ref' => 'qwen', 'priority' => 7, 'note' => 'Base ổn định, rẻ'],
            ['group' => 'image', 'name' => 'Qwen Image Max', 'provider' => 'qwen', 'model_id' => 'qwen-image-max', 'api_key_ref' => 'qwen', 'priority' => 6, 'note' => 'Chất lượng cao nhất (~$0.075/ảnh) — gói Pro/Studio, xem PRICING.md §3.2'],
            ['group' => 'image', 'name' => 'Flux 1.1 Schnell (Fal)', 'provider' => 'fal', 'model_id' => 'flux-1.1-schnell', 'api_key_ref' => 'fal', 'priority' => 3, 'note' => 'FALLBACK khi Qwen lỗi/hết hạn mức — nhanh, rẻ'],
            ['group' => 'image', 'name' => 'Gemini Flash Image', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash-image', 'api_key_ref' => 'gemini', 'priority' => 1, 'note' => 'Tùy chọn cuối — chỉ khi có GEMINI_API_KEY'],

            // ── EDIT — Sửa ảnh / Inpaint / reimagine / xoá nền ──
            ['group' => 'edit', 'name' => 'Qwen Image Edit 2511', 'provider' => 'qwen_edit', 'model_id' => 'qwen-image-edit-2511', 'api_key_ref' => 'qwen_edit', 'priority' => 10, 'note' => 'Bản edit mới nhất (~$0.03/MP) — "xưởng" của FabrikAI'],
            ['group' => 'edit', 'name' => 'Qwen Image Edit', 'provider' => 'qwen_edit', 'model_id' => 'qwen-image-edit', 'api_key_ref' => 'qwen_edit', 'priority' => 9, 'note' => 'Ổn định, có ở mọi gói key'],
            ['group' => 'edit', 'name' => 'Qwen Image Edit (max)', 'provider' => 'qwen_edit', 'model_id' => 'qwen-image-edit-max', 'api_key_ref' => 'qwen_edit', 'priority' => 8, 'note' => 'Chất lượng cao hơn (~$0.075/ảnh) — xem PRICING.md'],
            ['group' => 'edit', 'name' => 'Gemini Flash Image (edit)', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash-image', 'api_key_ref' => 'gemini', 'priority' => 1, 'note' => 'Tùy chọn cuối — geminiImageEdit path'],

            // ── VIDEO — catwalk (Wan3.0 mới nhất của QwenCloud; wan2.7 là thế hệ trước) ──
            ['group' => 'video', 'name' => 'Wan 3.0 Video', 'provider' => 'wan', 'model_id' => 'wan3.0-video', 'api_key_ref' => 'wan', 'priority' => 10, 'note' => 'QwenCloud 2026 — all-in-one t2v/i2v/r2v/edit, tới 30s, có tiếng'],
            ['group' => 'video', 'name' => 'Wan 2.7 T2V', 'provider' => 'wan', 'model_id' => 'wan2.7-t2v', 'api_key_ref' => 'wan', 'priority' => 8, 'note' => 'Text-to-video thế hệ 2.7'],
            ['group' => 'video', 'name' => 'Wan 2.7 i2v', 'provider' => 'wan', 'model_id' => 'wan2.7-i2v', 'api_key_ref' => 'wan', 'priority' => 7, 'note' => 'Image-to-video (ảnh đầu thành video catwalk)'],
            ['group' => 'video', 'name' => 'Wan 2.5 T2V', 'provider' => 'wan', 'model_id' => 'wan2.5-t2v', 'api_key_ref' => 'wan', 'priority' => 5, 'note' => 'Fallback cũ — một số host/key chưa có 2.7+'],

            // ── SWAP — Thử đồ / ghép người mẫu (Fitting Room) — dùng chung model edit ──
            ['group' => 'swap', 'name' => 'Qwen Image Edit 2511 (thử đồ)', 'provider' => 'qwen_edit', 'model_id' => 'qwen-image-edit-2511', 'api_key_ref' => 'qwen_edit', 'priority' => 10, 'note' => 'Giữ đồ, thay người/nền — cùng model edit của Inpaint'],
            ['group' => 'swap', 'name' => 'Qwen Image Edit (thử đồ)', 'provider' => 'qwen_edit', 'model_id' => 'qwen-image-edit', 'api_key_ref' => 'qwen_edit', 'priority' => 9, 'note' => 'Fallback thử đồ ổn định'],

            // ── VISION — đọc ảnh (mô tả khuôn mặt/dáng, phân tích ảnh tham chiếu) ──
            ['group' => 'vision', 'name' => 'Qwen 3.8 Flash (multimodal)', 'provider' => 'qwen', 'model_id' => 'qwen3.8-flash', 'api_key_ref' => 'qwen', 'priority' => 10, 'note' => 'Rẻ, đọc ảnh/video/text — endpoint chat OpenAI-compatible'],
            ['group' => 'vision', 'name' => 'Qwen 3.8 Max (multimodal)', 'provider' => 'qwen', 'model_id' => 'qwen3.8-max', 'api_key_ref' => 'qwen', 'priority' => 8, 'note' => 'Nhận diện sắc hơn, đắt hơn'],
            ['group' => 'vision', 'name' => 'Gemini 2.5 Flash (vision)', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash', 'api_key_ref' => 'gemini', 'priority' => 1, 'note' => 'Tùy chọn cuối'],

            // ── PROMPT — Giám đốc sáng tạo / Thuật sỹ ảo ──
            ['group' => 'prompt', 'name' => 'Qwen 3.8 Flash', 'provider' => 'qwen', 'model_id' => 'qwen3.8-flash', 'api_key_ref' => 'qwen', 'priority' => 10, 'note' => '1M context, agentic, tiếng Việt tốt'],
            ['group' => 'prompt', 'name' => 'Qwen 3.8 Max', 'provider' => 'qwen', 'model_id' => 'qwen3.8-max', 'api_key_ref' => 'qwen', 'priority' => 8, 'note' => 'Suy luận sâu hơn (snapshot 0902)'],
            ['group' => 'prompt', 'name' => 'DeepSeek Chat', 'provider' => 'deepseek', 'model_id' => 'deepseek-chat', 'api_key_ref' => 'deepseek', 'priority' => 5, 'note' => 'Nhóm DeepSeek đứng TRƯỚC Gemini — suy luận prompt, giá rẻ'],
            ['group' => 'prompt', 'name' => 'DeepSeek Reasoner', 'provider' => 'deepseek', 'model_id' => 'deepseek-reasoner', 'api_key_ref' => 'deepseek', 'priority' => 4, 'note' => 'Suy luận sâu (chain-of-thought), chậm hơn chat'],
            ['group' => 'prompt', 'name' => 'Gemini 2.5 Flash', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash', 'api_key_ref' => 'gemini', 'priority' => 1, 'note' => 'Tùy chọn cuối'],

            // ── TRANSLATE — dịch prompt VI ↔ EN ──
            ['group' => 'translate', 'name' => 'Qwen 3.8 Flash (dịch)', 'provider' => 'qwen', 'model_id' => 'qwen3.8-flash', 'api_key_ref' => 'qwen', 'priority' => 10, 'note' => 'Dịch VI↔EN tự nhiên, rẻ'],
            ['group' => 'translate', 'name' => 'DeepSeek Chat (dịch)', 'provider' => 'deepseek', 'model_id' => 'deepseek-chat', 'api_key_ref' => 'deepseek', 'priority' => 5, 'note' => 'Nhóm DeepSeek đứng TRƯỚC Gemini — dịch VI↔EN'],
            ['group' => 'translate', 'name' => 'Gemini 2.5 Flash (dịch)', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash', 'api_key_ref' => 'gemini', 'priority' => 1, 'note' => 'Tùy chọn cuối'],

            // ── INFERENCE (legacy key — giữ cho đường resolve_studio_model cũ) ──
            ['group' => 'inference', 'name' => 'Qwen 3.8 Flash (multimodal)', 'provider' => 'qwen', 'model_id' => 'qwen3.8-flash', 'api_key_ref' => 'qwen', 'priority' => 10, 'note' => 'Suy luận prompt — đọc được ảnh/video/text'],
            ['group' => 'inference', 'name' => 'Qwen 3.8 Max (multimodal)', 'provider' => 'qwen', 'model_id' => 'qwen3.8-max', 'api_key_ref' => 'qwen', 'priority' => 8, 'note' => 'Chất lượng cao hơn flash'],
            ['group' => 'inference', 'name' => 'Gemini (Giám đốc sáng tạo)', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash', 'api_key_ref' => 'gemini', 'priority' => 1, 'note' => 'Suy luận prompt — tùy chọn cuối'],
        ];
    }
}

if (! function_exists('studio_sync_model_catalog')) {
    /**
     * Đồng bộ catalog tích hợp (model QwenCloud mới nhất) vào Model Registry DB
     * (studio_models) — idempotent theo (group, provider, model_id): cập nhật
     * name/priority/note của dòng đã có, tạo dòng mới, KHÔNG xoá dòng admin tự
     * thêm. Trả về ['created' => n, 'updated' => n, 'total' => n].
     *
     * Cách gọi: "php artisan studio:sync-models" hoặc nút "Đồng bộ model Qwen"
     * trong trang Cài đặt (tab Luồng ưu tiên). Khi QwenCloud thêm model mới: cập
     * nhật studio_model_catalog() rồi chạy lại — cache tự xoá qua model events.
     */
    function studio_sync_model_catalog(?string $provider = null): array
    {
        $created = 0;
        $updated = 0;

        foreach (studio_model_catalog() as $row) {
            // Lọc theo provider: hữu ích khi chỉ muốn nhập MỘT nhóm provider mới mà
            // không hồi sinh những dòng catalog admin đã cố ý xoá.
            if ($provider !== null && $provider !== '' && ($row['provider'] ?? null) !== $provider) {
                continue;
            }
            $existing = \App\Models\StudioModel::query()
                ->where('group', $row['group'])
                ->where('provider', $row['provider'])
                ->where('model_id', $row['model_id'])
                ->first();

            if ($existing) {
                // Chỉ đè các trường "mô tả/catalog"; giữ enabled + api_key_ref của admin.
                $existing->fill([
                    'name' => $row['name'],
                    'priority' => $row['priority'],
                    'note' => $row['note'],
                ]);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }
            } else {
                \App\Models\StudioModel::create([
                    'group' => $row['group'],
                    'name' => $row['name'],
                    'provider' => $row['provider'],
                    'model_id' => $row['model_id'],
                    'api_key_ref' => $row['api_key_ref'],
                    'priority' => $row['priority'],
                    'enabled' => true,
                    'note' => $row['note'],
                ]);
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => \App\Models\StudioModel::count(),
        ];
    }
}

if (! function_exists('studio_drop_queued_generation')) {
    /**
     * Xoá job đã enqueue cho MỘT generation (nếu có) khỏi bảng queue.
     *
     * [Vì sao cần] Khi máy chủ KHÔNG có worker nền, mỗi generation vẫn được enqueue (đúng thiết kế)
     * nhưng rồi được lưới an toàn xử lý inline. Job đã enqueue sẽ nằm lại trong bảng jobs MÃI MÃI —
     * không ai nhặt, và cứ mỗi ảnh lại thêm một job chết. Hàm này dọn nó sau khi đã xử lý inline.
     *
     * Không dùng WHERE ... LIKE vì payload chứa chuỗi đã escape (generationId\";i:1;) và MySQL coi
     * backslash là ký tự escape trong LIKE -> mẫu dễ khớp sai. Lọc ở phía PHP cho chắc.
     *
     * @return int số job đã xoá
     */
    function studio_drop_queued_generation(int $generationId): int
    {
        $needle = 'generationId\";i:'.$generationId.';';
        $deleted = 0;

        try {
            $rows = \Illuminate\Support\Facades\DB::table('jobs')->get(['id', 'payload']);
        } catch (\Throwable $e) {
            return 0;   // bảng jobs chưa tồn tại / DB không phải queue store
        }

        foreach ($rows as $row) {
            if (str_contains((string) $row->payload, $needle)) {
                \Illuminate\Support\Facades\DB::table('jobs')->where('id', $row->id)->delete();
                $deleted++;
            }
        }

        return $deleted;
    }
}

if (! function_exists('studio_dispatch_generation')) {
    /**
     * Đẩy job xử lý cho MỘT generation, kèm chốt chống dội queue.
     *
     * [BUG THẬT gặp khi deploy fabrikai.shop 2026-09-17] Bản cũ CHỈ enqueue ở show() — tức là lúc
     * client POLL. Hệ quả: người dùng tạo ảnh rồi đóng tab ngay ⇒ không ai poll ⇒ generation nằm
     * 'pending' VĨNH VIỄN trong khi credit đã bị trừ. Đã kiểm chứng bằng chạy thật: tạo generation
     * xong, bảng 'jobs' vẫn rỗng cho tới khi có request poll.
     *
     * Nay dùng chung cho 2 chỗ: (1) Generation::created() — đẩy ngay lúc tạo, (2) show() — lưới an
     * toàn cho generation cũ/còn sót. Chốt Cache::add bảo đảm mỗi generation chỉ vào queue MỘT lần
     * dù cả hai chỗ cùng gọi (job trùng cũng vô hại vì job tự CAS pending→processing rồi return).
     *
     * @return bool true nếu lần gọi này thực sự đẩy job vào queue.
     */
    function studio_dispatch_generation(\App\Models\Generation $generation): bool
    {
        if ($generation->status !== 'pending') {
            return false;
        }

        if (! \Illuminate\Support\Facades\Cache::add('studio:queued:'.$generation->id, 1, now()->addMinutes(10))) {
            return false;   // đã đẩy cho generation này rồi
        }

        if ($generation->type === 'video') {
            \App\Jobs\RenderVideoJob::dispatch($generation->id);
        } else {
            \App\Jobs\RenderImageJob::dispatch($generation->id);
        }

        return true;
    }
}

if (! function_exists('studio_models')) {
    function studio_models(?string $group = null)
    {
        // [HIỆU NĂNG — vòng 20] cache 1 lần cho cả request: hàm này nằm trong đường legacy của
        // studio_task_group_models() nên bị gọi lặp khi resolve nhiều nhóm công việc (mỗi lượt là
        // 1 query `select * from studio_models` không điều kiện).
        // ⚠️ Cache MẢNG THUẦN, không cache Eloquent Collection.
        // [BUG PRODUCTION — phát hiện 2026-09-17 khi deploy] Bản cũ cache thẳng kết quả ->get()
        // (một Illuminate\Database\Eloquent\Collection chứa model). Laravel KHÔNG hỗ trợ cache
        // model/collection: khi ghi thì được, nhưng lần ĐỌC lại ném
        //   "The script tried to call a method on an incomplete object. Please ensure that the class
        //    definition Illuminate\Database\Eloquent\Collection ... was loaded before unserialize()"
        // => GET /api/defaults trả 500 ở MỌI lần gọi thứ hai trở đi.
        // Bộ test KHÔNG bắt được vì phpunit.xml đặt CACHE_STORE=array — store array giữ giá trị trong
        // bộ nhớ, không serialize. Đã thêm StudioCacheSerializationTest dùng store CÓ serialize
        // (database) để lỗi này không quay lại.
        $rows = collect(\Illuminate\Support\Facades\Cache::remember(
            App\Models\StudioModel::CACHE_KEY_ALL,
            60,
            function () {
                try {
                    return App\Models\StudioModel::query()->orderByDesc('priority')->orderBy('id')
                        ->get()
                        ->map(fn ($m) => $m->toArray())   // mảng thuần -> serialize an toàn
                        ->all();
                } catch (\Throwable $e) {
                    return [];   // chưa migrate -> rơi xuống catalog hardcode bên dưới
                }
            }
        ));

        if ($rows->isEmpty()) {
            $rows = collect(studio_model_catalog());
        }

        return $group ? $rows->where('group', $group)->values() : $rows;
    }
}

if (! function_exists('resolve_studio_model')) {
    // Pick the highest-priority ENABLED model for a group.
    function resolve_studio_model(string $group): ?array
    {
        $m = studio_models($group)
            ->where('enabled', true)
            ->sortByDesc('priority')
            ->first();
        if (! $m) return null;
        return ['provider' => $m['provider'], 'model' => $m['model_id'], 'api_key_ref' => $m['api_key_ref'] ?? null];
    }
}

if (! function_exists('studio_model_candidates')) {
    /**
     * Unified, priority-driven model list for a group. Order is:
     *   1. the model id from the DEFAULT settings (Cài đặt) for the group — highest priority;
     *   2. the registered (enabled) models of the group, by their registered priority (desc).
     * Deduplicated by provider:model. This single list drives generation, the default model
     * resolution and the settings check, so they can never disagree.
     */
    function studio_model_candidates(string $group): array
    {
        $list = [];
        $seen = [];
        $add = function ($provider, $model) use (&$list, &$seen) {
            $provider = (string) $provider;
            $model = (string) $model;
            if (! $provider || ! $model) {
                return;
            }
            $k = $provider.':'.$model;
            if (isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $list[] = ['provider' => $provider, 'model' => $model, 'api_key_ref' => $provider];
        };

        // 1. Default settings model for the group (top priority).
        if ($group === 'video') {
            $add('wan', (string) studio_config('video_model', 'wan3.0-video'));
        } elseif (in_array($group, ['image', 'inference', 'text'], true)) {
            $p = (string) studio_config('image_provider', 'qwen');
            // Chỉ provider có ÁNH XẠ MODEL tường minh mới đóng góp candidate mặc định.
            // Custom slug (vd 'ckey') không có model mặc định ⇒ bỏ qua: model của nó đến
            // từ Model Registry (bước 2) hoặc từ default gán riêng cho nhóm công việc.
            // Trước đây nhánh default gán model flux-1.1-schnell cho MỌI slug lạ — sai model
            // (vd ckey + flux-1.1-schnell ⇒ gọi gateway CKEY bằng model fal không tồn tại).
            $m = match ($p) {
                'gemini' => (string) studio_config('gemini_image_model', 'gemini-2.5-flash-image'),
                'wan' => (string) studio_config('wan_model', 'wan2.7-image-pro'),
                'qwen' => (string) studio_config('qwen_model', 'qwen-image-3.0-pro'),
                'flux' => (string) studio_config('image_model', 'flux-1.1-schnell'),
                default => null,
            };
            if ($m !== null && $m !== '') {
                $add($p, $m);
            }
        }

        // 2. Registered models of the group, ranked by the PROVIDER PRIORITY FLOW
        // (qwen → custom → flux → gemini; xem studio_provider_rank) first, then by
        // their saved priority (desc) within the same provider. This is what makes
        // Qwen the primary route and Flux/Gemini true fallbacks without touching the
        // per-model priorities the admin set.
        $rows = studio_sort_by_provider_rank(
            studio_models($group)->filter(function ($m) {
                return ($m['enabled'] ?? true) == true;
            })->values()->all()
        );
        foreach ($rows as $m) {
            $add($m['provider'] ?? null, $m['model_id'] ?? null);
        }

        return $list;
    }
}

if (! function_exists('studio_candidate_key')) {
    /**
     * Ordered list of API keys for a given (provider, model) candidate, chosen ONLY by registered
     * priority within the group (and the model/group scope) — never by key type. Qwen/DashScope/Wan
     * candidates may use any Qwen-family key, and the host is routed automatically by key prefix.
     * The first element is the top-priority key used first; each subsequent key is tried if it fails.
     */
    function studio_candidate_key(array $candidate, string $group): array
    {
        $provider = (string) ($candidate['provider'] ?? '');
        $model = (string) ($candidate['model'] ?? '');
        if (! $provider) {
            return [];
        }

        // KEY PRIORITY IS INTENTIONALLY IGNORED. The model priority within the group
        // (studio_model_candidates) is the ONLY driver of which model/key is used, per the admin's
        // rule: "bỏ qua mức độ ưu tiên keys, tập trung mức độ ưu tiên model cùng nhóm". We merely
        // collect the valid keys for this model (any registration order) and dedup them.
        //
        // The one hard rule that remains: Image/Video/Edit models ONLY exist on the Pay-As-You-Go
        // host, so a Token/Coding-Plan key (sk-sp-…) is dropped for those groups (its host has no
        // generation model); it is kept for text/vision/inference groups.
        $genGroups = in_array($group, ['image', 'video', 'edit'], true);

        $families = in_array($provider, ['qwen', 'wan', 'dashscope'], true)
            ? ['qwen', 'dashscope', 'wan', 'qwen_edit']
            : [$provider];

        $keys = [];
        foreach ($families as $fam) {
            foreach (studio_api_keys_for($fam, $model, $group) as $k) {
                $v = studio_api_key_value($k);
                if ($v) {
                    $keys[] = $v;
                }
            }
        }
        // env/config fallback slots.
        foreach ($families as $fam) {
            $v = studio_api_key($fam);
            if ($v) {
                $keys[] = $v;
            }
        }

        $keys = array_values(array_unique($keys));
        if ($genGroups) {
            $keys = array_values(array_filter($keys, fn ($k) => ! str_starts_with((string) $k, 'sk-sp-')));
        }

        return $keys;
    }
}

/*
|--------------------------------------------------------------------------
| TASK GROUPS — model theo nhóm công việc (mỗi card / tính năng một nhóm)
|--------------------------------------------------------------------------
| Bản đồ card /studio → nhóm:
|   image    : ConceptCard (Tạo Ảnh 2D), RefImageCard refgen (Ảnh mới từ ảnh mẫu / Thử đồ)
|   edit     : InpaintCard (Sửa ảnh), reimagine/variation, xóa nền — model edit-capable
|   video    : DirectorCard (Render video catwalk)
|   swap     : SwapCard (Thay Đổi Người Mẫu / Try-on)
|   vision   : đọc ảnh (mô tả khuôn mặt / dáng / phân tích ảnh tham chiếu)
|   prompt   : Giám đốc sáng tạo (GeminiService), StylistCard (Thuật sỹ ảo)
|   translate: dịch prompt VI ↔ EN
|
| Nguyên tắc: Model Registry (studio_models) là nguồn duy nhất — một model đăng ký
| 1 lần với group = vai trò của nó; task-group helper gom + lọc đúng loại cho từng
| card. Default-per-group lưu setting studio_task_<group>_model (provider:model).
| Các setting cũ (qwen_edit_model, swap_model…) vẫn được tôn trọng khi nhóm chưa
| gán default — không xóa trộn.
*/
if (! function_exists('studio_task_groups')) {
    /**
     * Danh sách nhóm công việc + nhãn hiển thị + model mặc định LEGACY (setting cũ
     * tương ứng) để UI Settings hiển thị "đang dùng gì" ngay cả khi chưa gán default mới.
     */
    function studio_task_groups(): array
    {
        return [
            'image' => ['label' => 'Tạo ảnh 2D (Concept / Ảnh mới từ ảnh mẫu)', 'legacy_default' => function () {
                $p = (string) studio_config('image_provider', 'qwen');
                $m = match ($p) {
                    'gemini' => (string) studio_config('gemini_image_model', 'gemini-2.5-flash-image'),
                    'wan' => (string) studio_config('wan_model', 'wan2.7-image-pro'),
                    'qwen' => (string) studio_config('qwen_model', 'qwen-image-3.0-pro'),
                    default => (string) studio_config('image_model', 'flux-1.1-schnell'),
                };
                return $p.':'.$m;
            }],
            'edit' => ['label' => 'Sửa ảnh / Inpaint (chỉnh sửa theo vùng, reimagine)', 'legacy_default' => fn () => 'qwen:'.(string) studio_config('qwen_edit_model', 'qwen-image-edit')],
            'video' => ['label' => 'Video catwalk (Kịch bản quay)', 'legacy_default' => fn () => 'wan:'.(string) studio_config('video_model', 'wan3.0-video')],
            // Nhóm 'swap' giữ nguyên KEY (nhiều nơi đọc) nhưng ĐỔI NHÃN: từ 2026-09-17 card "Thay
            // người mẫu" đã bị gỡ, nhóm này chỉ còn phục vụ đường thử đồ của Fitting Room
            // (VirtualTryOnService) và swapEdit (đổi/xoá người trong ảnh).
            'swap' => ['label' => 'Thử đồ / ghép người mẫu (Fitting Room)', 'legacy_default' => fn () => 'qwen:'.studio_swap_model()],
            'vision' => ['label' => 'Đọc ảnh (mô tả khuôn mặt / dáng / phân tích)', 'legacy_default' => fn () => 'qwen:'.(string) studio_config('qwen_vision_model', 'qwen3.8-flash')],
            'prompt' => ['label' => 'Suy luận prompt (Giám đốc sáng tạo / Thuật sỹ ảo)', 'legacy_default' => function () {
                $p = (string) studio_config('prompt_provider', 'gemini');
                $m = $p === 'gemini'
                    ? (string) studio_config('prompt_model', 'gemini-2.5-flash')
                    : (string) studio_config('qwen_prompt_model', 'qwen3.8-flash');
                return $p.':'.$m;
            }],
            'translate' => ['label' => 'Dịch prompt (VI ↔ EN)', 'legacy_default' => fn () => 'gemini:'.(string) studio_config('translate_model', 'gemini-2.5-flash')],

            // ── NHÓM RIÊNG CHO AGENT STUDIO (2026-09-23) ──────────────────────────────────────
            // Ba VAI khác nhau, không gộp: viết nội dung cần model suy luận; đọc ảnh mẫu cần model
            // NHÌN được ảnh; tra cứu nguồn ngoài cần model/nhà cung cấp CÓ tìm kiếm. Gộp chung vào
            // 'prompt' thì chủ shop không thể đổi một vai mà giữ nguyên hai vai còn lại.
            // Nhóm nào bỏ trống ⇒ agent tự dùng nhóm nền ('prompt' cho suy luận, 'vision' cho đọc ảnh),
            // nên cấu hình cũ vẫn chạy y như trước.
            'agent_reason' => ['label' => 'Agent Studio — Suy luận & viết nội dung', 'legacy_default' => fn () => ''],
            'agent_vision' => ['label' => 'Agent Studio — Đọc ảnh mẫu (bám phong cách)', 'legacy_default' => fn () => ''],
            'agent_search' => ['label' => 'Agent Studio — Tìm kiếm nguồn ngoài', 'legacy_default' => fn () => ''],
        ];
    }
}

if (! function_exists('studio_task_group_models')) {
    /**
     * Danh sách model của một nhóm công việc, theo thứ tự ưu tiên:
     *   1. default mới (setting studio_task_<group>_model) — nếu có, đứng đầu;
     *   2. các model group=<group> trong Model Registry (priority desc);
     *   3. nhóm chưa có model đăng ký → kế thừa danh sách legacy tương ứng
     *      (giữ mọi pipeline hiện có hoạt động nguyên vẹn).
     * Dedup theo provider:model. Trả về [] = [['provider','model','label','default','registry_id'], …]
     */
    function studio_models_enabled_by_group()
    {
        // ⚠️ Cùng lý do như studio_models(): trả về MẢNG THUẦN, không trả Eloquent Collection
        // (cache collection -> lần đọc thứ hai ném "incomplete object ... unserialize()").
        return \Illuminate\Support\Facades\Cache::remember(
            \App\Models\StudioModel::CACHE_KEY,
            60,
            function () {
                try {
                    return \App\Models\StudioModel::query()
                        ->where('enabled', true)
                        ->orderByDesc('priority')->orderBy('id')
                        ->get()
                        ->groupBy('group')
                        ->map(fn ($g) => $g->map(fn ($m) => $m->toArray())->all())
                        ->all();
                } catch (\Throwable $e) {
                    return [];   // chưa migrate studio_models -> đường legacy lo tiếp
                }
            }
        );
    }

    function studio_task_group_models(string $group): array
    {
        $groups = studio_task_groups();
        if (! isset($groups[$group])) {
            return [];
        }

        $list = [];
        $seen = [];
        $add = function (?string $provider, ?string $model, ?int $registryId = null, bool $default = false, ?string $label = null) use (&$list, &$seen) {
            $provider = trim((string) $provider);
            $model = trim((string) $model);
            if ($provider === '' || $model === '') {
                return;
            }
            $k = $provider.':'.$model;
            if (isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $list[] = [
                'provider' => $provider,
                'model' => $model,
                'label' => $label ?: $model,
                'default' => $default,
                'registry_id' => $registryId,
            ];
        };

        // 1. Default mới của nhóm (nếu đã gán trong Settings → tab Cấu hình nhóm).
        $assigned = trim((string) setting('studio_task_'.$group.'_model', ''));
        if ($assigned !== '' && str_contains($assigned, ':')) {
            [$p, $m] = explode(':', $assigned, 2);
            $add($p, $m, null, true);
        }

        // 2. Model Registry của nhóm — hình thức chính: gán model vào đúng vai trò.
        // Nạp MỘT lần rồi lọc theo nhóm (trước đây query lại cho MỖI nhóm — 21 query/request).
        // $row là MẢNG (cache trả mảng thuần) — dùng truy cập mảng, không dùng -> như bản cũ.
        // Xếp lại theo LUỒNG ƯU TIÊN provider (qwen → custom → flux → gemini) trước,
        // rồi mới tới priority — DB chỉ sort priority nên phải xếp lại ở đây.
        $rows = studio_sort_by_provider_rank(studio_models_enabled_by_group()[$group] ?? []);
        foreach ($rows as $row) {
            $add($row['provider'] ?? null, $row['model_id'] ?? null, $row['id'] ?? null, false, $row['name'] ?? null);
        }

        // 3. Legacy kế thừa (nhóm chưa đăng ký model nào) — pipeline cũ tiếp tục chạy.
        if (count($list) === 0) {
            $legacy = [];
            if ($group === 'image') {
                $legacy = studio_model_candidates('image');
            } elseif ($group === 'edit') {
                $legacy = [['provider' => 'qwen', 'model' => (string) studio_config('qwen_edit_model', 'qwen-image-edit')]];
                foreach (studio_model_candidates('image') as $c) {
                    $p = (string) ($c['provider'] ?? '');
                    $m = (string) ($c['model'] ?? '');
                    if (in_array($p, ['qwen', 'wan', 'dashscope'], true)
                        && app(\App\Services\ImageAIService::class)->isImageEditCapableModel($m)) {
                        $legacy[] = ['provider' => $p, 'model' => $m];
                    }
                }
            } elseif ($group === 'video') {
                $legacy = studio_model_candidates('video');
            } elseif ($group === 'swap') {
                $legacy = [['provider' => 'qwen', 'model' => studio_swap_model()]];
            } elseif ($group === 'vision') {
                $legacy = array_map(fn ($m) => ['provider' => 'qwen', 'model' => $m], array_slice(studio_qwen_vision_models(), 0, 5));
            } elseif ($group === 'prompt') {
                $legacy = array_map(fn ($m) => ['provider' => 'qwen', 'model' => $m], array_slice(studio_qwen_text_models(), 0, 5));
                $legacy[] = ['provider' => 'gemini', 'model' => (string) studio_config('prompt_model', 'gemini-2.5-flash')];
            } elseif ($group === 'translate') {
                // Qwen trước theo luồng ưu tiên; Gemini là tùy chọn cuối (đổi từ bản cũ
                // đặt gemini đầu — giữ làm fallback khi chưa cấu hình key Qwen).
                $legacy = [
                    ['provider' => 'qwen', 'model' => (string) studio_config('qwen_prompt_model', 'qwen3.8-flash')],
                    ['provider' => 'gemini', 'model' => (string) studio_config('translate_model', 'gemini-2.5-flash')],
                ];
            }
            foreach ($legacy as $i => $c) {
                $add($c['provider'] ?? null, $c['model'] ?? null, null, $i === 0);
            }
        }

        return $list;
    }
}

if (! function_exists('studio_task_group_default')) {
    /**
     * Model mặc định HIỆN HÀNH của một nhóm — provider:model string. Ưu tiên default
     * mới; nếu chưa gán, model đầu tiên của danh sách (default legacy đã đứng đầu).
     */
    function studio_task_group_default(string $group): ?string
    {
        $list = studio_task_group_models($group);
        foreach ($list as $c) {
            if (! empty($c['default'])) {
                return $c['provider'].':'.$c['model'];
            }
        }
        return $list ? $list[0]['provider'].':'.$list[0]['model'] : null;
    }
}

if (! function_exists('studio_task_group_resolve')) {
    /**
     * Tách default của nhóm thành [provider, model] — dùng trực tiếp tại các call-site
     * (queueGeneration, swap, translate…) thay cho chuỗi setting rời rạc cũ.
     */
    function studio_task_group_resolve(string $group): array
    {
        $d = studio_task_group_default($group);
        if (! $d || ! str_contains($d, ':')) {
            return [null, null];
        }
        [$p, $m] = explode(':', $d, 2);
        return [$p, $m];
    }
}



/**
 * Resolve a robust VISION model for describing a face / analyzing a reference image.
 *
 * Không hard-code model trong logic — mọi lựa chọn đi qua chuỗi cấu hình:
 * Studio Settings (DB) -> .env -> config/studio.php. Qwen 3.8 series (qwen3.8-flash / qwen3.8-max)
 * là model ĐA PHƯƠNG THỨC (đọc ảnh/video/text qua endpoint chat OpenAI-compatible) nên được
 * ưu tiên hơn qwen-vl-* cũ. Chỉ model chắc chắn KHÔNG vision (sinh/chỉnh sửa ảnh qwen-image-*,
 * wanx*-image*, dịch vụ audio/embedding) bị loại.
 */
function studio_vision_model(?string $provider = null): string
{
    $provider = $provider ?: (string) studio_config('vision_provider', 'qwen');

    if ($provider === 'qwen') {
        $m = studio_qwen_vision_default();

        if (! is_qwen_vision_capable($m)) {
            // Model đã cấu hình không phải chat-vision -> chọn model hợp lệ đầu tiên trong
            // danh sách ưu tiên (config được), cuối cùng mới tới default mềm.
            foreach (studio_qwen_vision_models() as $candidate) {
                if (is_qwen_vision_capable($candidate)) {
                    return $candidate;
                }
            }

            return (string) config('studio.qwen_vision_model', 'qwen3.8-flash');
        }

        return $m;
    }

    $m = (string) studio_config('vision_model', 'gemini-2.5-flash');
    if (! str_starts_with(strtolower($m), 'gemini')) {
        return 'gemini-2.5-flash'; // sai provider/model (qwen/deepseek/…) -> dùng Gemini vision
    }
    return $m ?: 'gemini-2.5-flash';
}

/**
 * Qwen VISION model mặc định — đọc Settings (DB) -> env/config; không cứng trong logic.
 */
function studio_qwen_vision_default(): string
{
    $m = trim((string) studio_config('qwen_vision_model', ''));

    if ($m === '' || ! is_qwen_vision_capable($m)) {
        $m = (string) config('studio.qwen_vision_model', 'qwen3.8-flash');
    }

    return ($m && is_qwen_vision_capable($m)) ? $m : 'qwen3.8-flash';
}

/**
 * Qwen MAX model mặc định — đọc Settings (DB) -> env/config; không cứng trong logic.
 */
function studio_qwen_max_default(): string
{
    $m = trim((string) studio_config('qwen_max_model', ''));

    if ($m === '') {
        $m = (string) config('studio.qwen_max_model', 'qwen3.8-max');
    }

    return $m ?: 'qwen3.8-max';
}

/**
 * Whether a Qwen model can be used for vision/chat-multimodal calls.
 * qwen3.x-flash/max và dòng qwen-vl (các phiên bản) là multimodal chat; model SINH/CHỈNH SỬA ẢNH
 * (qwen-image-*, wanx*-image*) và dịch vụ không-chat (embedding, TTS, ASR, rerank…) bị loại.
 */
function is_qwen_vision_capable(?string $model): bool
{
    if (! $model) {
        return false;
    }
    $m = strtolower(trim($model));

    // Chỉ nhận model Qwen (qwen-… / qvq-… / qwq-…). Chặn mọi tên model của provider
    // khác (gemini-…, gpt-…, deepseek-…, flux-…, wan-…, kling-…, veo-…) — nếu admin
    // lỡ nhập sai vào danh sách Qwen, nó sẽ không bị gửi sang host Qwen gây "model not found".
    if (! str_starts_with($m, 'qwen') && ! str_starts_with($m, 'qvq') && ! str_starts_with($m, 'qwq')) {
        return false;
    }

    if (preg_match('/(^|[\-_.])(image|img)(edit)?([\-_.]|$)/', $m)
        || str_contains($m, 'wanx')
        || str_contains($m, 'videogen')
        || str_contains($m, 'taichu')
        || str_contains($m, 'embedding')
        || str_contains($m, 'paraformer')
        || str_contains($m, 'speech')
        || str_contains($m, 'tts')
        || str_contains($m, 'rerank')
        || str_contains($m, 'asr')) {
        return false;
    }

    return true;
}

/**
 * Candidate Qwen VISION models to try in order (robust: host/account chỉ expose một subset).
 *
 * Ưu tiên 1: danh sách tùy biến qwen_vision_models (Settings, phân cách dấu phẩy) —
 * model đầu là ưu tiên cao nhất, nhập được BẤT KỲ model nào (qwen3.8-max, qwen3.8-flash…).
 * Ưu tiên 2: [qwen_vision_model đã cấu hình, qwen_max_model, qwen-vl-* fallback].
 */
function studio_qwen_vision_models(): array
{
    $custom = array_values(array_filter(
        array_map('trim', explode(',', (string) studio_config('qwen_vision_models', ''))),
        fn ($m) => $m !== '' && is_qwen_vision_capable($m)
    ));
    $primary = studio_qwen_vision_default(); // model admin chọn qua Settings/env/config
    $max = studio_qwen_max_default();
    $defaults = [$primary, $max, 'qwen3.8-flash', 'qwen-vl-max', 'qwen-vl-plus'];

    return array_values(array_unique(array_filter(array_merge($custom, $defaults))));
}

/**
 * Candidate Qwen TEXT/chat models to try in order (OpenAI-compatible chat completions).
 *
 * Ưu tiên 1: danh sách tùy biến qwen_text_models (Settings, phân cách dấu phẩy).
 * Ưu tiên 2: [qwen_prompt_model, stylist_model, qwen_max_model, default mềm, qwen-plus, qwen-turbo].
 * Dùng cho stylist, translate fallback và creative-director (Qwen) path.
 */
function studio_qwen_text_models(): array
{
    $custom = array_filter(array_map('trim', explode(',', (string) studio_config('qwen_text_models', ''))));
    if (! empty($custom)) {
        return array_values(array_unique(array_filter($custom)));
    }

    $configured = trim((string) studio_config('qwen_prompt_model', ''));
    if ($configured === '') {
        $configured = (string) config('studio.qwen_prompt_model', 'qwen3.8-flash');
    }
    $stylist = trim((string) studio_config('stylist_model', ''));
    $max = studio_qwen_max_default();
    $candidates = [$configured, $stylist, $max];
    $candidates[] = (string) config('studio.qwen_prompt_model', 'qwen3.8-flash'); // default mềm
    $candidates[] = 'qwen-plus';
    $candidates[] = 'qwen-turbo';

    return array_values(array_unique(array_filter($candidates)));
}



/* ══════════════════════════════════════════════════════════════════════════════
   THEME (giao diện Sáng/Tối) — 2026-09-23 · docs/DESIGN_SYSTEM.md §1.1
   Một chỗ khai, mọi shell dùng: blade render sẵn data-theme cho thẻ <html> để KHÔNG nháy
   màu khi tải trang, và partial resources/views/partials/theme.blade.php sửa lại ngay
   trước lần vẽ đầu tiên khi người dùng chọn "theo hệ điều hành".
   ══════════════════════════════════════════════════════════════════════════════ */

if (! function_exists('theme_options')) {
    /**
     * Ba lựa chọn hợp lệ — NGUỒN DUY NHẤT cho cả PHP (whitelist ở ThemeController) lẫn blade.
     */
    function theme_options(): array
    {
        return ['light', 'dark', 'system'];
    }
}

if (! function_exists('theme_pref')) {
    /**
     * Tùy chọn theme của người ĐANG đăng nhập: light | dark | system.
     *
     * Chưa từng chọn (users.theme = NULL) ⇒ 'dark' — đúng giao diện sản phẩm đang dùng, nên
     * người dùng hiện hữu không bị đổi giao diện sau khi tính năng này lên.
     * Khách chưa đăng nhập ⇒ 'dark' (script trong partial vẫn đổi được theo máy của họ).
     */
    function theme_pref(): string
    {
        $pref = auth()->user()?->theme;

        return in_array($pref, theme_options(), true) ? $pref : 'dark';
    }
}

if (! function_exists('theme_resolved')) {
    /**
     * Theme CỤ THỂ để render vào data-theme của thẻ <html>.
     *
     * 'system' không giải được ở phía server (máy chủ không biết ý hệ điều hành của khách),
     * nên trả 'dark' và để script trong partial sửa lại TRƯỚC khi vẽ. Đây là lý do duy nhất
     * khiến giá trị render sẵn có thể khác lựa chọn của người dùng.
     */
    function theme_resolved(): string
    {
        return theme_pref() === 'light' ? 'light' : 'dark';
    }
}


/* ══════════════════════════════════════════════════════════════════════════════
   CỠ CHỮ TOÀN CỤC (2026-09-23) — cùng chỗ với theme, cùng cách xử lý.
   Thang cỡ chữ trong app.css là các token --text-* nhân với --font-scale, nên chỉ cần một con số
   phần trăm là cả giao diện to/nhỏ theo, không phải sửa từng chỗ.
   ══════════════════════════════════════════════════════════════════════════════ */

if (! function_exists('font_scale_options')) {
    /** Bốn mức cho người dùng chọn — NGUỒN DUY NHẤT cho cả PHP (whitelist) lẫn giao diện. */
    function font_scale_options(): array
    {
        return [90, 100, 115, 130];
    }
}

if (! function_exists('font_scale')) {
    /**
     * Mức cỡ chữ của người ĐANG đăng nhập (phần trăm). Chưa chọn ⇒ 100.
     */
    function font_scale(): int
    {
        $value = (int) (auth()->user()?->font_scale ?? 0);

        return in_array($value, font_scale_options(), true) ? $value : 100;
    }
}

if (! function_exists('font_scale_ratio')) {
    /**
     * Hệ số để render vào style của thẻ <html>: 1 · 1.15 · 1.3 …
     *
     * Render NGAY Ở SERVER (không chờ JS) để trang không nháy cỡ chữ khi tải — cùng lý do với data-theme.
     */
    function font_scale_ratio(): string
    {
        return rtrim(rtrim(number_format(font_scale() / 100, 2, '.', ''), '0'), '.') ?: '1';
    }
}


if (! function_exists('export_channels')) {
    /**
     * KÊNH BÁN cho tên file trong gói xuất xưởng — NGUỒN DUY NHẤT ở phía server (whitelist).
     *
     * ⚠️ Phải khớp danh sách ở resources/js/studio/exportChannels.js (giao diện đọc từ đó). Server
     * kiểm lại giá trị nhận được, nên hai bên lệch nhau thì request trả 422 — không âm thầm bỏ qua.
     *
     * @return array<string,string> khoá = mã kênh ('' = mặc định), giá trị = tiền tố tên file
     */
    function export_channels(): array
    {
        return [
            '' => '',
            'shopee' => 'shopee',
            'lazada' => 'lazada',
            'tiktok' => 'tiktok',
            'catalogue' => 'catalogue',
            'xuong' => 'xuong',
        ];
    }
}

if (! function_exists('export_channel_prefix')) {
    /**
     * Tiền tố tên file của kênh; giá trị lạ ⇒ chuỗi rỗng (mặc định), KHÔNG bao giờ đưa thẳng vào tên file.
     */
    function export_channel_prefix(?string $channel): string
    {
        $channels = export_channels();

        return $channels[$channel ?? ''] ?? '';
    }
}

