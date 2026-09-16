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

if (! function_exists('format_price')) {
    function format_price($value): string
    {
        $n = (float) $value;

        return number_format($n, 0, ',', '.').'₫';
    }
}

if (! function_exists('get_cart')) {
    function get_cart(): \App\Models\Cart
    {
        return app(\App\Services\CartService::class)->cart();
    }
}

if (! function_exists('cart_payload')) {
    function cart_payload(): array
    {
        return app(\App\Services\CartService::class)->payload();
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

if (! function_exists('seo')) {
    function seo(): \App\Support\Seo
    {
        return app(\App\Support\Seo::class);
    }
}

if (! function_exists('category_icons')) {
    function category_icons(): array
    {
        return [
            'tag' => ['label' => 'Danh mục', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/>'],
            'bag' => ['label' => 'Túi xách', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z"/>'],
            'home' => ['label' => 'Nhà cửa', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/>'],
            'clock' => ['label' => 'Đồng hồ', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
            'eye' => ['label' => 'Kính mắt', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>'],
            'star' => ['label' => 'Nổi bật', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-6.98-2.885-6.98 2.885a.562.562 0 01-.84-.61l1.285-5.385a.563.563 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/>'],
            'heart' => ['label' => 'Yêu thích', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/>'],
            'shirt' => ['label' => 'Thời trang', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3.5L3.5 6.25l2 4.5 2.5-1.5V20.5h8V9.25l2.5 1.5 2-4.5L15.75 3.5a4.75 4.75 0 01-7.5 0z"/>'],
            'sparkles' => ['label' => 'Làm đẹp', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5l1.2 3.8 3.8 1.2-3.8 1.2L9 14.5l-1.2-3.8L4 9.5l3.8-1.2L9 4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 12l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6z"/>'],
            'gift' => ['label' => 'Quà tặng', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12M12 6l-3.3-3.3a2.1 2.1 0 10-3 3L9 6m3-3.5L15.3 5.6a2.1 2.1 0 113-3L12 6.5M4.5 9h15A1.5 1.5 0 0121 10.5v4A1.5 1.5 0 0119.5 16h-15A1.5 1.5 0 013 14.5v-4A1.5 1.5 0 014.5 9z"/>'],

            'chat' => ['label' => 'Liên hệ', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/>'],
            'phone' => ['label' => 'Điện thoại', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>'],
            'camera' => ['label' => 'Máy ảnh', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>'],
            'book' => ['label' => 'Sách', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/>'],
            'music' => ['label' => 'Âm nhạc', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 9l10.5-3m0 6.553v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 11-.99-3.467l2.31-.66a2.25 2.25 0 001.632-2.163zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 01-.99-3.467l2.31-.66A2.25 2.25 0 009 15.553z"/>'],
            'shield' => ['label' => 'Bảo hành', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>'],
            'sun' => ['label' => 'Mùa hè', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/>'],
            'moon' => ['label' => 'Ban đêm', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/>'],
            'bolt' => ['label' => 'Ưu đãi', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>'],
            'bank' => ['label' => 'Tài chính', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21.75H2.25m0 0H1.5M9 9h.008v.008H9V9zm3 0h.008v.008H12V9zm3 0h.008v.008H15V9zm-6 5h.008v.008H9V14zm3 0h.008v.008H12V14zm3 0h.008v.008H15V14z"/>'],
            'wrench' => ['label' => 'Dịch vụ', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z"/>'],
            'cube' => ['label' => 'Sản phẩm', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5L21 9l-9 4.5L3 9l9-4.5zM3 9v6l9 4.5 9-4.5V9M12 13.5V21"/>'],

        ];
    }
}

if (! function_exists('category_icon_svg')) {
    function category_icon_svg(?string $name): string
    {
        $icons = category_icons();
        $key = $name ?? 'tag';

        return $icons[$key]['svg'] ?? $icons['tag']['svg'];
    }
}

if (! function_exists('widget_enabled')) {
    function widget_enabled(string $key, bool $default = true): bool
    {
        $v = setting('widget_'.$key.'_enabled');

        return $v === null ? $default : (bool) $v;
    }
}

if (! function_exists('widget_limit')) {
    function widget_limit(string $key, int $default = 8): int
    {
        return max(1, (int) setting('widget_'.$key.'_limit', $default));
    }
}

if (! function_exists('widget_field')) {
    function widget_field(string $key, string $field, $default = ''): string
    {
        return (string) setting('widget_'.$key.'_'.$field, $default);
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
        \Illuminate\Support\Facades\Log::warning('studio_fail: '.$context, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]);
        $msg = $safeMessage ?? ($context !== '' ? $context.' thất bại.' : 'Lỗi hệ thống, vui lòng thử lại.');
        $payload = ['ok' => false, 'message' => $msg];
        if (config('app.debug')) {
            $payload['debug'] = $e->getMessage();
        }
        return response()->json($payload, $status);
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

if (! function_exists('studio_generation_error')) {
    /**
     * Thông điệp lỗi ghi vào cột Generation.error (N8/N10). Cột này TRẢ CHO CLIENT qua
     * StudioController::show(), nên KHÔNG được chứa message thô của provider/DB.
     *
     * - Luôn log đầy đủ (class + message + file:line) ở server để còn chẩn đoán.
     * - APP_DEBUG=true  → giữ nguyên văn (dev cần thấy lỗi thật).
     * - Production      → chỉ trả câu chung, không lộ chi tiết nội bộ.
     *
     * @param  string  $prefix  tiền tố giữ nguyên cho user (vd 'Render bị ngắt: ')
     */
    function studio_generation_error(\Throwable $e, string $prefix = ''): string
    {
        \Illuminate\Support\Facades\Log::error('Generation failed'.($prefix !== '' ? ' — '.trim($prefix) : ''), [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ]);

        if (config('app.debug')) {
            return $prefix.$e->getMessage();
        }

        return $prefix !== ''
            ? $prefix.'Vui lòng thử lại.'
            : 'Xử lý thất bại. Vui lòng thử lại hoặc kiểm tra cài đặt API/model.';
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
        $p = strtolower((string) studio_suggest_config('provider', 'qwen'));

        return in_array($p, ['gemini', 'qwen'], true) ? $p : 'qwen';
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
        return str_starts_with($url, 'http') ? $url : url($url);
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
        return [
            'qwen' => ['name' => 'Qwen — ảnh (QwenCloud)', 'protocol' => 'dashscope', 'hint' => 'QWEN_API_KEY (home.qwencloud.com/api-keys)'],
            'qwen_edit' => ['name' => 'Qwen Edit — chỉnh sửa ảnh / Inpaint', 'protocol' => 'dashscope', 'hint' => 'QWEN_EDIT_KEY · model edit (qwen-image-edit, wanx2.1-imageedit…)'],
            'dashscope' => ['name' => 'DashScope — Wan/Qwen image & video (Alibaba)', 'protocol' => 'dashscope', 'hint' => 'DASHSCOPE_API_KEY'],
            'wan' => ['name' => 'Wan AI — video', 'protocol' => 'dashscope', 'hint' => 'WAN_API_KEY / DASHSCOPE_API_KEY'],
            'gemini' => ['name' => 'Gemini — Giám đốc sáng tạo', 'protocol' => 'gemini', 'hint' => 'GEMINI_API_KEY (aistudio.google.com)'],
            'veo' => ['name' => 'Google Veo — video', 'protocol' => 'gemini', 'hint' => 'GOOGLE_VEO_KEY'],
            'fal' => ['name' => 'Fal.ai — Flux (ảnh)', 'protocol' => 'openai', 'hint' => 'FAL_KEY'],
            'replicate' => ['name' => 'Replicate — Flux (ảnh)', 'protocol' => 'openai', 'hint' => 'REPLICATE_API_TOKEN (replicate.com/account/api-tokens)'],
            'deepseek' => ['name' => 'DeepSeek — ngôn ngữ / suy luận', 'protocol' => 'openai', 'hint' => 'DEEPSEEK_API_KEY · model deepseek-chat'],
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
                'base_url' => null,
                'auth_style' => $meta['protocol'] === 'gemini' ? 'x-goog-api-key' : 'bearer',
                'hint' => $meta['hint'] ?? null,
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

if (! function_exists('deepseek_chat')) {
    /**
     * Simple DeepSeek chat-completion call (text). Returns the assistant reply or null.
     */
    function deepseek_chat(string $instruction, ?string $model = null): ?string
    {
        $key = studio_api_key('deepseek');
        if (! $key) { return null; }
        $model = $model ?: (string) config('studio.deepseek_model', 'deepseek-chat');
        try {
            $resp = \Illuminate\Support\Facades\Http::withToken($key)->timeout(60)
                ->post((string) config('studio.deepseek_base', 'https://api.deepseek.com').'/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $instruction]],
                    'stream' => false,
                ]);
            if ($resp->successful()) {
                $out = trim((string) data_get($resp->json(), 'choices.0.message.content'));
                return $out !== '' ? $out : null;
            }
            logger()->warning('DeepSeek chat failed: '.$resp->status().' '.substr((string) $resp->body(), 0, 180));
        } catch (\Throwable $e) { logger()->warning('DeepSeek chat error: '.$e->getMessage()); }
        return null;
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
            'balance' => $user ? (int) $user->credits_balance : 0,
            'used_total' => $q ? (int) $q->sum('credits_cost') : 0,
            'used_today' => $q ? (int) $q->whereDate('created_at', today())->sum('credits_cost') : 0,
            'limit' => (int) studio_config('quota_limit', 0),
            'quota_resets_at' => (string) setting('studio_provider_quota_resets_at', ''),
        ];
    }
}

if (! function_exists('category_children_nodes')) {
    function category_children_nodes(\App\Models\Category $category): \Illuminate\Support\Collection
    {
        return $category->children()->orderBy('sort_order')->get()->map(function ($sub) {
            return (object) [
                'label' => $sub->name,
                'url' => route('shop.category', $sub->slug),
                'children' => category_children_nodes($sub),
            ];
        });
    }
}

if (! function_exists('menu_tree')) {
    function menu_tree(string $location = 'header'): \Illuminate\Support\Collection
    {
        $items = \App\Models\MenuItem::location($location)->active()
            ->with(['category', 'customPage'])->orderBy('sort_order')->orderBy('id')->get();

        $byParent = $items->groupBy('parent_id');

        $builder = function ($parentId) use ($byParent, &$builder) {
            $nodes = collect();

            foreach ($byParent->get($parentId, collect()) as $item) {
                // Category-type menu item: auto-render subcategories recursively.
                if ($item->type === 'category' && $item->category) {
                    $children = category_children_nodes($item->category);
                } else {
                    $children = $builder($item->id);
                }

                $nodes->push((object) [
                    'label' => $item->label,
                    'url' => $item->getUrl(),
                    'children' => $children,
                ]);
            }

            return $nodes;
        };

        return $builder(null);
    }
/**
 * Studio model registry — dynamic per group (image | video | inference).
 * Falls back to a built-in catalog when nothing is registered yet (so it works out-of-the-box).
 */
if (! function_exists('studio_model_catalog')) {
    function studio_model_catalog(): array
    {
        return [
            ['group' => 'image', 'name' => 'Flux Schnell (Fal)', 'provider' => 'fal', 'model_id' => 'flux-1.1-schnell', 'api_key_ref' => 'fal', 'priority' => 5, 'note' => 'Nhanh, rẻ — dùng cho stub/CRUD'],
            ['group' => 'image', 'name' => 'Qwen Image 3.0 Pro', 'provider' => 'qwen', 'model_id' => 'qwen-image-3.0-pro', 'api_key_ref' => 'qwen', 'priority' => 8, 'note' => 'Giàu chi tiết, ưu tiên cao'],
            ['group' => 'image', 'name' => 'Wan 2.7 Image Pro', 'provider' => 'wan', 'model_id' => 'wan2.7-image-pro', 'api_key_ref' => 'dashscope', 'priority' => 7, 'note' => 'DashScope'],
            ['group' => 'image', 'name' => 'Gemini Flash Image', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash-image', 'api_key_ref' => 'gemini', 'priority' => 6, 'note' => 'Google'],
            ['group' => 'video', 'name' => 'Wan 2.2 i2v', 'provider' => 'wan', 'model_id' => 'wan2.2-i2v', 'api_key_ref' => 'wan', 'priority' => 9, 'note' => 'Chất lượng cao'],
            ['group' => 'video', 'name' => 'Wan 2.5 i2v', 'provider' => 'wan', 'model_id' => 'wan2.5-i2v', 'api_key_ref' => 'wan', 'priority' => 7, 'note' => 'Cân bằng'],
            ['group' => 'video', 'name' => 'Wan 2.1 i2v Turbo', 'provider' => 'wan', 'model_id' => 'wan2.1-i2v-turbo', 'api_key_ref' => 'wan', 'priority' => 5, 'note' => 'Nhanh'],
            ['group' => 'video', 'name' => 'Kling i2v', 'provider' => 'kling', 'model_id' => 'kling-v1-6-i2v', 'api_key_ref' => 'kling', 'priority' => 8, 'note' => 'Nếu có key Kling'],
            ['group' => 'inference', 'name' => 'Gemini (Giám đốc sáng tạo)', 'provider' => 'gemini', 'model_id' => 'gemini-2.5-flash', 'api_key_ref' => 'gemini', 'priority' => 9, 'note' => 'Suy luận prompt'],
            ['group' => 'inference', 'name' => 'Qwen 3.8 Flash (multimodal)', 'provider' => 'qwen', 'model_id' => 'qwen3.8-flash', 'api_key_ref' => 'qwen', 'priority' => 8, 'note' => 'Suy luận prompt — đọc được ảnh/video/text'],
            ['group' => 'inference', 'name' => 'Qwen 3.8 Max (multimodal)', 'provider' => 'qwen', 'model_id' => 'qwen3.8-max', 'api_key_ref' => 'qwen', 'priority' => 7, 'note' => 'Chất lượng cao hơn flash'],
        ];
    }
}

if (! function_exists('studio_models')) {
    function studio_models(?string $group = null)
    {
        $rows = App\Models\StudioModel::query()->get();
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
            $add('wan', (string) studio_config('video_model', 'wan2.5-t2v'));
        } elseif (in_array($group, ['image', 'inference', 'text'], true)) {
            $p = (string) studio_config('image_provider', 'flux');
            $m = match ($p) {
                'gemini' => (string) studio_config('gemini_image_model', 'gemini-2.5-flash-image'),
                'wan' => (string) studio_config('wan_model', 'wan2.7-image-pro'),
                'qwen' => (string) studio_config('qwen_model', 'qwen-image-3.0-pro'),
                default => (string) studio_config('image_model', 'flux-1.1-schnell'),
            };
            $add($p, $m);
        }

        // 2. Registered models of the group, by priority (desc).
        foreach (studio_models($group)->filter(function ($m) {
            return ($m['enabled'] ?? true) == true;
        })->sortByDesc('priority')->values() as $m) {
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
                $p = (string) studio_config('image_provider', 'flux');
                $m = match ($p) {
                    'gemini' => (string) studio_config('gemini_image_model', 'gemini-2.5-flash-image'),
                    'wan' => (string) studio_config('wan_model', 'wan2.7-image-pro'),
                    'qwen' => (string) studio_config('qwen_model', 'qwen-image-3.0-pro'),
                    default => (string) studio_config('image_model', 'flux-1.1-schnell'),
                };
                return $p.':'.$m;
            }],
            'edit' => ['label' => 'Sửa ảnh / Inpaint (chỉnh sửa theo vùng, reimagine)', 'legacy_default' => fn () => 'qwen:'.(string) studio_config('qwen_edit_model', 'qwen-image-edit')],
            'video' => ['label' => 'Video catwalk (Kịch bản quay)', 'legacy_default' => fn () => 'wan:'.(string) studio_config('video_model', 'wan2.5-t2v')],
            'swap' => ['label' => 'Thay đổi người mẫu (Try-on)', 'legacy_default' => fn () => 'qwen:'.studio_swap_model()],
            'vision' => ['label' => 'Đọc ảnh (mô tả khuôn mặt / dáng / phân tích)', 'legacy_default' => fn () => 'qwen:'.(string) studio_config('qwen_vision_model', 'qwen3.8-flash')],
            'prompt' => ['label' => 'Suy luận prompt (Giám đốc sáng tạo / Thuật sỹ ảo)', 'legacy_default' => function () {
                $p = (string) studio_config('prompt_provider', 'gemini');
                $m = $p === 'gemini'
                    ? (string) studio_config('prompt_model', 'gemini-2.5-flash')
                    : (string) studio_config('qwen_prompt_model', 'qwen3.8-flash');
                return $p.':'.$m;
            }],
            'translate' => ['label' => 'Dịch prompt (VI ↔ EN)', 'legacy_default' => fn () => 'gemini:'.(string) studio_config('translate_model', 'gemini-2.5-flash')],
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
        try {
            foreach (\App\Models\StudioModel::where('group', $group)->where('enabled', true)
                ->orderByDesc('priority')->orderBy('id')->get() as $row
            ) {
                $add($row->provider, $row->model_id, $row->id, false, $row->name);
            }
        } catch (\Throwable $e) {
            // Chưa migrate studio_models — bỏ qua, dùng legacy.
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
                $legacy = [
                    ['provider' => 'gemini', 'model' => (string) studio_config('translate_model', 'gemini-2.5-flash')],
                    ['provider' => 'qwen', 'model' => (string) studio_config('qwen_prompt_model', 'qwen3.8-flash')],
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

/*
|--------------------------------------------------------------------------
| AI Sản phẩm (Product AI) — cấu hình riêng cho trợ lý content/SEO trong form sản phẩm
|--------------------------------------------------------------------------
| Ưu tiên Qwen trước, rồi Gemini. Mọi thứ (thứ tự provider, danh sách model, key,
| timeout, giới hạn số lần thử, downscale ảnh, cache) đều đọc từ DB Settings → env
| → config/studio.php để sau này nâng cấp model mà không cần sửa code.
*/
if (! function_exists('product_ai_config')) {
    function product_ai_config(string $key, $default = null)
    {
        $stored = setting('product_ai_'.$key);

        return $stored !== null ? (string) $stored : config('studio.product_ai.'.$key, $default);
    }
}

if (! function_exists('product_ai_enabled')) {
    function product_ai_enabled(): bool
    {
        return filter_var(product_ai_config('enabled', true), FILTER_VALIDATE_BOOLEAN);
    }
}

if (! function_exists('product_ai_providers')) {
    /**
     * Thứ tự provider được thử (Qwen trước, rồi Gemini, rồi DeepSeek). DeepSeek chỉ
     * làm TEXT (không vision) nên luôn được đặt sau cùng làm phương án rẻ/đáng tin cậy.
     * Luôn đảm bảo danh sách hợp lệ đủ cả ba provider.
     */
    function product_ai_providers(): array
    {
        $raw = array_values(array_filter(array_map('trim', explode(',', strtolower((string) product_ai_config('provider_order', 'qwen,gemini,deepseek'))))));
        $valid = [];
        foreach ($raw as $p) {
            if (in_array($p, ['qwen', 'gemini', 'deepseek'], true) && ! in_array($p, $valid, true)) {
                $valid[] = $p;
            }
        }
        foreach (['qwen', 'gemini', 'deepseek'] as $p) {
            if (! in_array($p, $valid, true)) {
                $valid[] = $p;
            }
        }

        return $valid;
    }
}

if (! function_exists('product_ai_deepseek_model')) {
    function product_ai_deepseek_model(): string
    {
        return trim((string) product_ai_config('deepseek_model', config('studio.deepseek_model', 'deepseek-chat')));
    }
}

if (! function_exists('product_ai_qwen_text_models')) {
    /**
     * Dùng DUY NHẤT qwen3.8-flash cho mọi tác vụ TEXT — đây là model đa phương thức
     * (đọc được ảnh + text) và nhanh nhất. Chỉ model khác (pay-go) được thử khi key
     * không có qwen3.8-flash (xử lý riêng ở attemptQwen).
     */
    function product_ai_qwen_text_models(): array
    {
        return ['qwen3.8-flash'];
    }
}

if (! function_exists('product_ai_qwen_vision_models')) {
    /**
     * Dùng DUY NHẤT qwen3.8-flash cho VISION — multimodal, đọc ảnh trực tiếp.
     * Không còn thử qwen-vl-* (chậm) nữa.
     */
    function product_ai_qwen_vision_models(): array
    {
        return ['qwen3.8-flash'];
    }
}

if (! function_exists('product_ai_qwen_paygo_text_models')) {
    /**
     * Model TEXT cho key Pay-As-You-Go (dashscope-intl). qwen3.8-flash/max là model
     * Token-Plan (sk-sp-…); gửi chúng sang dashscope-intl sẽ treo/timeout. Dùng các
     * model cổ điển có sẵn trên dashscope-intl.
     */
    function product_ai_qwen_paygo_text_models(): array
    {
        $custom = array_values(array_filter(array_map('trim', explode(',', (string) product_ai_config('qwen_paygo_text_models', '')))));

        return $custom ?: ['qwen-plus', 'qwen-turbo', 'qwen-max'];
    }
}

if (! function_exists('product_ai_qwen_paygo_vision_models')) {
    function product_ai_qwen_paygo_vision_models(): array
    {
        $custom = array_values(array_filter(
            array_map('trim', explode(',', (string) product_ai_config('qwen_paygo_vision_models', ''))),
            fn ($m) => $m !== '' && is_qwen_vision_capable($m)
        ));

        return $custom ?: ['qwen-vl-max', 'qwen-vl-plus'];
    }
}

if (! function_exists('product_ai_gemini_text_model')) {
    function product_ai_gemini_text_model(): string
    {
        $m = trim((string) product_ai_config('gemini_text_model', 'gemini-2.5-flash'));

        return str_starts_with(strtolower($m), 'gemini') ? $m : 'gemini-2.5-flash';
    }
}

if (! function_exists('product_ai_gemini_vision_model')) {
    function product_ai_gemini_vision_model(): string
    {
        $m = trim((string) product_ai_config('gemini_vision_model', 'gemini-2.5-flash'));

        return str_starts_with(strtolower($m), 'gemini') ? $m : 'gemini-2.5-flash';
    }
}

if (! function_exists('product_ai_timeout')) {
    function product_ai_timeout(): int
    {
        return max(1, (int) product_ai_config('timeout_seconds', 30));
    }
}

if (! function_exists('product_ai_max_models')) {
    function product_ai_max_models(): int
    {
        return max(1, (int) product_ai_config('max_models', 2));
    }
}

if (! function_exists('product_ai_total_budget')) {
    function product_ai_total_budget(): int
    {
        // Hard ceiling for a whole click (vision + text share it); per-call timeout is clamped by it.
        return max(5, (int) product_ai_config('total_budget_seconds', 25));
    }
}

if (! function_exists('product_ai_max_keys')) {
    function product_ai_max_keys(): int
    {
        return max(1, (int) product_ai_config('max_keys', 3));
    }
}

if (! function_exists('product_ai_downscale_max')) {
    function product_ai_downscale_max(): int
    {
        return max(64, min(4096, (int) product_ai_config('downscale_max', 640)));
    }
}

if (! function_exists('product_ai_cache_ttl')) {
    function product_ai_cache_ttl(): int
    {
        return max(0, (int) product_ai_config('cache_ttl_hours', 720)) * 3600;
    }
}

if (! function_exists('product_ai_temperature')) {
    function product_ai_temperature(): float
    {
        return (float) product_ai_config('temperature', 0.7);
    }
}

if (! function_exists('product_ai_max_tokens')) {
    function product_ai_max_tokens(): int
    {
        return max(128, (int) product_ai_config('max_tokens', 1200));
    }
}

if (! function_exists('product_ai_vision_max_tokens')) {
    function product_ai_vision_max_tokens(): int
    {
        return max(64, (int) product_ai_config('vision_max_tokens', 400));
    }
}

