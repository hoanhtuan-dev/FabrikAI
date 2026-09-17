<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Studio credits
    |--------------------------------------------------------------------------
    | Number of credits deducted per generation. Configurable so you can tune
    | the pricing for a SaaS model later.
    */
    'image_credits' => (int) env('STUDIO_IMAGE_CREDITS', 1),

    /*
    |--------------------------------------------------------------------------
    | Chặn khi hết credit (mặc định BẬT — Q1 đã được chủ dự án quyết ngày 2026-09-19)
    |--------------------------------------------------------------------------
    | Từ trước tới nay pipeline KHÔNG chặn khi hết credit (ghi rõ trong queueGeneration:
    | "never hard-block on credits, track usage") — hợp lý khi công cụ còn nội bộ, nhưng là
    | lỗ hổng khi bán gói: khách vượt hạn mức vẫn tạo ảnh được.
    |
    | Nay mỗi thao tác phải có đủ credit, thiếu thì trả 402 CÓ CẤU TRÚC (code=out_of_credits,
    | needed, balance, upgrade_url) để giao diện mở thẳng bảng nâng cấp.
    | Muốn tạm tắt (không cần sửa mã): setting DB studio_enforce_credits=0 hoặc env
    | STUDIO_ENFORCE_CREDITS=false.
    */
    // [Q1 — 2026-09-19] Chủ dự án đã quyết: BẬT chặn khi hết credit ⇒ mặc định nay là TRUE.
    // Hai đường tắt KHÔNG cần sửa mã: setting DB studio_enforce_credits=0, hoặc env
    // STUDIO_ENFORCE_CREDITS=false. Ghi chú: giá trị ở config này được ưu tiên hơn tham số mặc định
    // truyền vào studio_config(), nên đổi mặc định phải đổi Ở ĐÂY (bài học từ lần sửa đầu bị vô hiệu).
    'enforce_credits' => filter_var(env('STUDIO_ENFORCE_CREDITS', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Giới hạn an toàn khi decode ảnh (T14)
    |--------------------------------------------------------------------------
    | Cap số pixel chống "decompression bomb": ảnh nén vài chục KB có thể giải nén
    | thành ảnh hàng trăm MP và làm OOM worker. Kiểm tra chạy TRƯỚC imagecreatefromstring()
    | (đọc header bằng getimagesizefromstring) nên bitmap chưa kịp cấp phát.
    | 0 = tắt cap. Override được từ trang Cài đặt qua setting DB studio_image_max_pixels.
    */
    'image_max_pixels' => (int) env('STUDIO_IMAGE_MAX_PIXELS', 30000000),

    /*
    |--------------------------------------------------------------------------
    | Allowlist host cho ảnh/video tải từ URL REMOTE (S3/N1/N4)
    |--------------------------------------------------------------------------
    | CSV các host được phép, khớp cả subdomain (vd: "dashscope.aliyuncs.com").
    | Rỗng = không chặn host nào (vẫn giữ scheme http/https + timeout + cap dung lượng).
    */
    'remote_image_hosts' => env('STUDIO_REMOTE_IMAGE_HOSTS', ''),
    'video_credits' => (int) env('STUDIO_VIDEO_CREDITS', 10),
    'processing' => env('STUDIO_PROCESSING', 'sync'), // sync | queue (async + worker)
    // [2026-09-17] Luồng ưu tiên provider mặc định: qwen → custom → flux → gemini.
    // 'qwen' = QwenCloud/DashScope (nhà cung cấp chính), 'custom' = provider tự khai báo trong
    // Settings (vd CKEY — api.xah.io), 'flux' = Fal.ai fallback, 'gemini' = tùy chọn cuối.
    'image_provider' => env('STUDIO_IMAGE_PROVIDER', 'qwen'), // flux | wan | qwen | gemini | slug custom

    /*
    |--------------------------------------------------------------------------
    | Luồng ưu tiên provider (fallback chain) — DeepSeek Harness style
    |--------------------------------------------------------------------------
    | CSV thứ tự NHÓM provider khi xếp danh sách model cho mỗi nhóm công việc:
    |   qwen   → Qwen/DashScope/Wan (nhà cung cấp chính, model QwenCloud mới nhất)
    |   custom → provider tự khai báo trong Settings (vd CKEY — https://api.xah.io/v1)
    |   flux   → Fal.ai / Replicate (fallback Flux khi Qwen lỗi/hết hạn mức)
    |   gemini → Google Gemini (tùy chọn — chỉ dùng khi có GEMINI_API_KEY)
    | Model mặc định gán cho từng nhóm (studio_task_<group>_model) vẫn luôn đứng trước
    | chuỗi này; bên dưới sắp theo rank nhóm, rồi priority model. Đổi thứ tự bằng
    | setting này (hoặc tab Luồng ưu tiên trong Cài đặt) — không cần sửa code.
    */
    'provider_priority' => env('STUDIO_PROVIDER_PRIORITY', 'qwen,custom,flux,gemini'),

    /*
    |--------------------------------------------------------------------------
    | Models per task
    |--------------------------------------------------------------------------
    | You can override each on the Studio Settings page; the values below are
    | the defaults and are also read from env.
    */
    'prompt_provider' => env('STUDIO_PROMPT_PROVIDER', 'qwen'), // qwen | gemini — Qwen ưu tiên
    'prompt_model' => env('STUDIO_PROMPT_MODEL', 'gemini-2.5-flash'),
    'qwen_prompt_model' => env('STUDIO_QWEN_PROMPT_MODEL', 'qwen3.8-flash'), // multimodal mặc định (đọc ảnh/video/text)
    'qwen_max_model' => env('STUDIO_QWEN_MAX_MODEL', 'qwen3.8-max'), // chất lượng cao hơn cho vision/chat
    'translate_model' => env('STUDIO_TRANSLATE_MODEL', 'gemini-2.5-flash'),
    'stylist_model' => env('STUDIO_STYLIST_MODEL', 'qwen3.8-flash'), // Model ✨ Thuật sỹ ảo (Qwen multimodal trước)
    'swap_model' => env('STUDIO_SWAP_MODEL', ''), // '' = dùng chung qwen_edit_model (giống Inpaint: qwen-image-edit-max)
    'swap_candidates' => (int) env('STUDIO_SWAP_CANDIDATES', 1), // 1 = nhanh; 2-3 = chọn bản đẹp nhất (chậm hơn)
    'swap_pose_image' => (bool) env('STUDIO_SWAP_POSE_IMAGE', false), // pose dùng MÔ TẢ (không gửi ảnh) — tránh crop/biến dạng
    'swap_face_inline' => (bool) env('STUDIO_SWAP_FACE_INLINE', true),  // true = 1 pass (mặt + đồ, 2 ảnh) — ổn định, mặc đúng mẫu
    // (Đã gỡ 2026-09-17 cùng card "Thay người mẫu": swap_superres_scale · swap_superres ·
    //  swap_face_enhance · swap_moderation · swap_qa · swap_enabled · swap_brighten — chúng chỉ được
    //  đọc trong swapModel()/executeSwapFromGeneration() vừa bị xoá. Các key swap_model ·
    //  swap_candidates · swap_pose_image · swap_face_inline VẪN DÙNG vì VirtualTryOnService (đường
    //  refgen/try-on của Fitting Room) đọc chúng.)
    'faceswap_prompt' => env('STUDIO_FACESWAP_PROMPT', 'Face swap (NOT a photo overlay): replace the face of @image1 with the face in @image2. Generate a NEW natural face matching @image2 identity, hairstyle, facial features, ears and head proportions — do NOT paste/overlay the photo. Make the new head about 80% the size of the original head — smaller and naturally proportionate, never enlarged or distorted. Blend skin tone, hairline and lighting seamlessly. Keep garment, pose, body, background unchanged.'),
    'image_model' => env('STUDIO_IMAGE_MODEL', 'flux-1.1-schnell'),
    'wan_model' => env('STUDIO_WAN_MODEL', 'wan2.7-image-pro'),
    'qwen_model' => env('STUDIO_QWEN_MODEL', 'qwen-image-3.0-pro'),
    'qwen_edit_model' => env('STUDIO_QWEN_EDIT_MODEL', 'qwen-image-edit'),
    'brand_name' => env('STUDIO_BRAND_NAME', ''),
    'gemini_image_model' => env('STUDIO_GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
    // [2026-09-17] wan3.0-video = model mới nhất trên QwenCloud (all-in-one: t2v/i2v/r2v/edit,
    // tới 30s); wan2.5-t2v giữ làm fallback trong Model Registry. Xem docs.qwencloud.com
    // → developer-guides/video-generation ("Wan3.0 Video Generation").
    'video_model' => env('STUDIO_VIDEO_MODEL', 'wan3.0-video'),
    'vision_provider' => env('STUDIO_VISION_PROVIDER', 'qwen'), // qwen | gemini — Qwen đa phương thức ưu tiên
    'vision_model' => env('STUDIO_VISION_MODEL', 'gemini-2.5-flash'),
    'qwen_vision_model' => env('STUDIO_QWEN_VISION_MODEL', 'qwen3.8-flash'), // multimodal: flash (nhanh) / qwen3.8-max (mạnh), vẫn giữ fallback qwen-vl-*
    'image_resolution' => env('STUDIO_IMAGE_RESOLUTION', '2K'), // 1K | 2K
    'video_resolution' => env('STUDIO_VIDEO_RESOLUTION', '720'), // 480 | 720 | 1080
    'image_ratio' => env('STUDIO_IMAGE_RATIO', '1:1'), // 1:1 | 4:3 | 3:4 | 16:9 | 9:16 | 4:5 | 21:9 | 19:6
    'video_duration' => env('STUDIO_VIDEO_DURATION', '10'), // 5 | 8 | 10 | 15 | 20 (giây)
    'creative_level' => (int) env('STUDIO_CREATIVE_LEVEL', 6), // 1 (bám sát brief) .. 10 (sáng tạo tự do)
    'texture' => (int) env('STUDIO_TEXTURE', 5), // 0 (mịn phẳng) .. 10 (siêu chi tiết sợi vải)
    'negative_prompt' => env('STUDIO_NEGATIVE_PROMPT', 'blurry, low quality, distorted proportions, extra limbs, deformed hands, watermark, text, logo, oversaturated, overexposed, cropped garment, inconsistent face'),
    'prompt_prefix' => env('STUDIO_PROMPT_PREFIX', 'High-fashion editorial photograph, professional fashion photography'),
    'prompt_suffix' => env('STUDIO_PROMPT_SUFFIX', 'soft diffused studio lighting, clean minimal background, ultra detailed, 4k, sharp focus'),
    'enrich_prompt' => (bool) env('STUDIO_ENRICH_PROMPT', true), // tự động làm giàu prompt với prefix/suffix/negative

    // Độ trung thực ảnh edit/tryon — kiểm soát chất lượng ảnh NGUỒN gửi tới model và hậu kỳ.
    'edit_source_max' => (int) env('STUDIO_EDIT_SOURCE_MAX', 2560), // ảnh nguồn edit chỉ downscale nếu cạnh dài > 2560px (trước đây 1600 → mất chi tiết đồ)
    'edit_postprocess' => (bool) env('STUDIO_EDIT_POSTPROCESS', false), // false = lưu raw bytes từ API (không sharpen/re-encode qua GD) — trung thực cao; true = bật lại unsharp mask 0.22 cũ

    /*
    |--------------------------------------------------------------------------
    | Gợi ý từ ảnh (image → style / prompt suggestion)
    |--------------------------------------------------------------------------
    | Tách hoàn toàn khỏi cấu hình Vision chung: tính năng "💡 Gợi ý từ ảnh" có
    | provider + model + hành vi riêng, không phụ thuộc setting nào khác.
    */
    'suggest' => [
        'enabled' => (bool) env('STUDIO_SUGGEST_ENABLED', true),
        'provider' => env('STUDIO_SUGGEST_PROVIDER', 'qwen'), // qwen | gemini — Qwen ưu tiên
        'gemini_model' => env('STUDIO_SUGGEST_GEMINI_MODEL', 'gemini-2.5-flash'),
        'qwen_model' => env('STUDIO_SUGGEST_QWEN_MODEL', 'qwen3.8-flash'), // multimodal chính
        'qwen_models' => env('STUDIO_SUGGEST_QWEN_MODELS', ''), // danh sách ưu tiên, phân cách dấu phẩy
        'creative_level' => (int) env('STUDIO_SUGGEST_CREATIVE_LEVEL', 6),
        'adherence' => (int) env('STUDIO_SUGGEST_ADHERENCE', 0), // 0 = tự theo creative; 1..10 ép bám ảnh gốc
        'detail_level' => (int) env('STUDIO_SUGGEST_DETAIL_LEVEL', 8), // 1..10 mức chi tiết phân tích ảnh (màu/đường may/hoạ tiết/độ dài/cổ/tay...)
        'max_styles' => (int) env('STUDIO_SUGGEST_MAX_STYLES', 3),
        'downscale_max' => (int) env('STUDIO_SUGGEST_DOWNSCALE_MAX', 1024),
        'fallback' => (bool) env('STUDIO_SUGGEST_FALLBACK', true), // GD color fallback khi không có key
        'include_video' => (bool) env('STUDIO_SUGGEST_INCLUDE_VIDEO', true),
        'default_lang' => env('STUDIO_SUGGEST_DEFAULT_LANG', 'en'), // en | vi
    ],

    // (Khối cấu hình "AI Sản phẩm / Product AI" đã bị GỠ cùng module thương mại điện tử
    //  2026-09-17. Trước đây khối comment của nó còn sót lại mà THIẾU dấu đóng, nên nuốt luôn
    //  phần mô tả của 'queue_worker' bên dưới — đã sửa.)

    /*
    |--------------------------------------------------------------------------
    | Queue worker — quyết định REQUEST có bị giữ lâu hay không (N11/M13)
    |--------------------------------------------------------------------------
    | Máy chủ này có `php artisan queue:work` chạy nền hay không?
    |
    |  false (MẶC ĐỊNH) — "lazy worker": khi client poll một generation còn 'pending',
    |      chính REQUEST đó xử lý inline để trả kết quả ngay. Tiện cho dev và cho máy
    |      chủ chưa dựng worker, NHƯNG request có thể bị giữ tới ~8 phút vì các service
    |      phải sleep() chờ provider (ảnh ~3 phút, video tới ~8 phút — xem N11/M13).
    |      Vài request đồng thời là cạn pool PHP-FPM ⇒ sập cả site.
    |
    |  true — production CÓ worker: request chỉ ENQUEUE rồi trả về ngay, job nền mới
    |      là chỗ sleep(). Đây là chế độ ĐÚNG cho production.
    |
    | ⚠️ Bật cờ này mà KHÔNG chạy worker ⇒ generation không bao giờ được xử lý (bộ
    | "heal" sẽ đánh dấu thất bại + hoàn credit sau 6–8 phút). Chạy kèm:
    |     php artisan queue:work --timeout=900 --tries=1
    |
    | Lưu ý: đọc thẳng từ config (KHÔNG qua studio_config()) để tránh việc một setting
    | trong DB vô tình lật chế độ của cả máy chủ.
    */
    'queue_worker' => (bool) env('STUDIO_QUEUE_WORKER', false),

    /*
    |--------------------------------------------------------------------------
    | AI providers (optional)
    |--------------------------------------------------------------------------
    | Set these in .env when you have real API keys. When empty the services
    | fall back to deterministic stubs so the whole flow works offline.
    */
    'gemini_key' => env('GEMINI_API_KEY', ''),
    'fal_key' => env('FAL_KEY', ''),
    'replicate_token' => env('REPLICATE_API_TOKEN', ''),
    'wan_key' => env('WAN_API_KEY', ''),
    'veo_key' => env('GOOGLE_VEO_KEY', ''),
    'qwen_key' => env('QWEN_API_KEY', ''),
    'qwen_edit_key' => env('QWEN_EDIT_KEY', ''), // khoá riêng cho các model chỉnh sửa ảnh Qwen (mỗi gói có bộ model edit khác nhau)
    'dashscope_key' => env('DASHSCOPE_API_KEY', ''),
    'deepseek_key' => env('DEEPSEEK_API_KEY', ''),
    'deepseek_model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    'deepseek_base' => env('DEEPSEEK_BASE', 'https://api.deepseek.com'),
    'dashscope_base' => env('DASHSCOPE_BASE', 'https://dashscope-intl.aliyuncs.com'), // Pay-As-You-Go (sk-…): intl = quốc tế
    'dashscope_token_plan_base' => env('DASHSCOPE_TOKEN_PLAN_BASE', 'https://token-plan.ap-southeast-1.maas.aliyuncs.com'), // Token/Coding Plan (sk-sp-…): riêng, không dùng chung
    'face_edit_sync' => (bool) env('STUDIO_FACE_EDIT_SYNC', false), // sau khi tạo ảnh mới, dùng model chỉnh sửa (qwen-edit) đổi mặt về ảnh tham khảo
    'quota_limit' => (int) env('STUDIO_QUOTA_LIMIT', 0), // 0 = không giới hạn; dùng để hiển thị hạn mức/tiến độ
];