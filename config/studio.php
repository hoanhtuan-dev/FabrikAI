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
    'video_credits' => (int) env('STUDIO_VIDEO_CREDITS', 10),
    'processing' => env('STUDIO_PROCESSING', 'sync'), // sync | queue (async + worker)
    'image_provider' => env('STUDIO_IMAGE_PROVIDER', 'flux'), // flux | wan | qwen

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
    'swap_superres_scale' => (int) env('STUDIO_SWAP_SUPERRES_SCALE', 2), // upscale 2x/4x trước khi trả kết quả
    'swap_pose_image' => (bool) env('STUDIO_SWAP_POSE_IMAGE', false), // pose dùng MÔ TẢ (không gửi ảnh) — tránh crop/biến dạng
    'swap_face_inline' => (bool) env('STUDIO_SWAP_FACE_INLINE', true),  // true = 1 pass (mặt + đồ, 2 ảnh) — ổn định, mặc đúng mẫu
    'swap_superres' => (bool) env('STUDIO_SWAP_SUPERRES', false),          // upscale image-super-resolution (KHÔNG có trên host intl => mặc định tắt)
    'swap_face_enhance' => (bool) env('STUDIO_SWAP_FACE_ENHANCE', false),  // face-image-enhance (mặc định tắt)
    'swap_moderation' => (bool) env('STUDIO_SWAP_MODERATION', false),      // image-moderation (mặc định tắt)
    'swap_qa' => (bool) env('STUDIO_SWAP_QA', true),                        // QA scoring (qwen3.8-flash — bật mặc định, fail êm khi rate-limit)
    'swap_enabled' => (bool) env('STUDIO_SWAP_ENABLED', false),          // TẠM ẨN tính năng Thay Đổi Người Mẫu (chưa hiệu quả, tốn nguồn lực)
    'faceswap_prompt' => env('STUDIO_FACESWAP_PROMPT', 'Face swap (NOT a photo overlay): replace the face of @image1 with the face in @image2. Generate a NEW natural face matching @image2 identity, hairstyle, facial features, ears and head proportions — do NOT paste/overlay the photo. Make the new head about 80% the size of the original head — smaller and naturally proportionate, never enlarged or distorted. Blend skin tone, hairline and lighting seamlessly. Keep garment, pose, body, background unchanged.'),
    'swap_brighten' => (bool) env('STUDIO_SWAP_BRIGHTEN', false),          // kéo sáng chủ thể tối (có thể lệch màu đồ) → mặc định TẮT
    'image_model' => env('STUDIO_IMAGE_MODEL', 'flux-1.1-schnell'),
    'wan_model' => env('STUDIO_WAN_MODEL', 'wan2.7-image-pro'),
    'qwen_model' => env('STUDIO_QWEN_MODEL', 'qwen-image-3.0-pro'),
    'qwen_edit_model' => env('STUDIO_QWEN_EDIT_MODEL', 'qwen-image-edit'),
    'brand_name' => env('STUDIO_BRAND_NAME', ''),
    'gemini_image_model' => env('STUDIO_GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
    'video_model' => env('STUDIO_VIDEO_MODEL', 'wan2.5-t2v'),
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

    /*
    |--------------------------------------------------------------------------
    | AI Sản phẩm (Product AI — content + SEO assistant trong form sản phẩm)
    |--------------------------------------------------------------------------
    | Cấu hình RIÊNG cho trợ lý viết mô tả / SEO ở trang tạo-sửa sản phẩm (admin).
    | Ưu tiên Qwen trước (qwen3.8-flash …) rồi mới Gemini; mọi model/key đều đọc
    | từ đây (DB Settings → env → config) nên nâng cấp model sau này không cần sửa code.
    */
    'product_ai' => [
        'enabled' => (bool) env('STUDIO_PRODUCT_AI_ENABLED', true),
        'provider_order' => env('STUDIO_PRODUCT_AI_PROVIDER_ORDER', 'qwen,gemini,deepseek'), // qwen trước, deepseek cuối (text)
        'qwen_text_models' => env('STUDIO_PRODUCT_AI_QWEN_TEXT_MODELS', ''), // '' = theo studio_qwen_text_models
        'qwen_vision_models' => env('STUDIO_PRODUCT_AI_QWEN_VISION_MODELS', ''), // '' = theo studio_suggest_qwen_models
        'qwen_paygo_text_models' => env('STUDIO_PRODUCT_AI_QWEN_PAYGO_TEXT_MODELS', ''), // '' = qwen-plus,turbo (dashscope-intl)
        'qwen_paygo_vision_models' => env('STUDIO_PRODUCT_AI_QWEN_PAYGO_VISION_MODELS', ''), // '' = qwen-vl-max,plus
        'gemini_text_model' => env('STUDIO_PRODUCT_AI_GEMINI_TEXT_MODEL', 'gemini-2.5-flash'),
        'gemini_vision_model' => env('STUDIO_PRODUCT_AI_GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
        'deepseek_model' => env('STUDIO_PRODUCT_AI_DEEPSEEK_MODEL', 'deepseek-chat'),
        'timeout_seconds' => (int) env('STUDIO_PRODUCT_AI_TIMEOUT', 90), // 1 call đa phương thức (giống "Gợi ý từ ảnh") cần ~30-90s
        'total_budget_seconds' => (int) env('STUDIO_PRODUCT_AI_TOTAL_BUDGET', 90), // trần tổng cho một lần bấm AI
        'max_models' => (int) env('STUDIO_PRODUCT_AI_MAX_MODELS', 1), // chỉ qwen3.8-flash — giảm thời gian, không thử model dự phòng
        'max_keys' => (int) env('STUDIO_PRODUCT_AI_MAX_KEYS', 3),
        'downscale_max' => (int) env('STUDIO_PRODUCT_AI_DOWNSCALE_MAX', 640),
        'cache_ttl_hours' => (int) env('STUDIO_PRODUCT_AI_CACHE_TTL_HOURS', 720), // 30 ngày
        'temperature' => (float) env('STUDIO_PRODUCT_AI_TEMPERATURE', 0.7),
        'max_tokens' => (int) env('STUDIO_PRODUCT_AI_MAX_TOKENS', 700), // output vừa phải để host token-plan/paygo trả về kịp (không timeout)
        'vision_max_tokens' => (int) env('STUDIO_PRODUCT_AI_VISION_MAX_TOKENS', 400), // VISION (chỉ hiểu ảnh — rẻ & nhanh)

        // Ngân sách token theo hạng mục tinh chỉnh — càng ít càng nhanh
        'refine_names_tokens' => (int) env('STUDIO_PRODUCT_AI_REFINE_NAMES_TOKENS', 500),
        'refine_name_tokens' => (int) env('STUDIO_PRODUCT_AI_REFINE_NAME_TOKENS', 350),
        'refine_seo_tokens' => (int) env('STUDIO_PRODUCT_AI_REFINE_SEO_TOKENS', 650),
        'refine_desc_tokens' => (int) env('STUDIO_PRODUCT_AI_REFINE_DESC_TOKENS', 1500),
        'refine_desc_variants_tokens' => (int) env('STUDIO_PRODUCT_AI_REFINE_DESC_VARIANTS_TOKENS', 4000),

        // Cache kết quả tinh chỉnh (giây) — gọi lại chip cùng dữ liệu → tức thì
        'refine_cache_ttl' => (int) env('STUDIO_PRODUCT_AI_REFINE_CACHE_TTL', 600),
    ],

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