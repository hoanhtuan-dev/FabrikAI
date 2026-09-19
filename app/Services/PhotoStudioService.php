<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * STUDIO — PHÒNG CHỤP THỜI TRANG CHUYÊN NGHIỆP (bối cảnh chủ đề + ánh sáng + máy ảnh + dáng + danh sách ảnh).
 *
 * VÌ SAO CẦN: card "Ghép ảnh" cũ chỉ có 3 ô ảnh + một câu mô tả. Chủ xưởng/studio thật không làm việc
 * theo kiểu đó — họ CHỐT MỘT BỐI CẢNH (set), một sơ đồ đèn, một ống kính, một dáng, rồi chụp MỘT BỘ ẢNH
 * (shot list) mà mọi tấm phải trông như CÙNG MỘT BUỔI CHỤP. Đó là ba thứ quyết định ảnh bán được hay không:
 *   1. BỐI CẢNH CHỦ ĐỀ theo bộ sưu tập (mùa, vùng miền, dịp, không gian);
 *   2. TÍNH NHẤT QUÁN giữa các tấm (cùng người mẫu · cùng nền · cùng hướng sáng · cùng grade);
 *   3. ĐỘ TRUNG THỰC CỦA TRANG PHỤC (sản phẩm phải GIỮ NGUYÊN, không để AI vẽ lại).
 *
 * Nguyên tắc file này:
 *   · Toàn bộ prompt do MÁY dựng từ catalog có sẵn — tất định, tái lập được, không gọi model AI nào.
 *     (AI vẽ ảnh ở bước sau, qua pipeline /api/compose sẵn có.)
 *   · Mọi tấm trong một buổi chụp dùng CHUNG một "look signature" nên ảnh ra đồng bộ như một bộ.
 *   · Nói thật khi chưa có model ảnh: plan trả cờ image_ready để giao diện cảnh báo chế độ demo.
 */
class PhotoStudioService
{
    public const MAX_SHOTS = 12;

    /** Tỉ lệ khung theo mục đích dùng ảnh. */
    public const RATIOS = [
        ['id' => '1:1', 'name' => '1:1 — Sàn TMĐT / feed', 'use' => 'Ảnh chính sàn, lưới Instagram'],
        ['id' => '4:5', 'name' => '4:5 — Lookbook / feed dọc', 'use' => 'Lookbook, bài đăng dọc'],
        ['id' => '3:4', 'name' => '3:4 — Catalogue', 'use' => 'Catalogue, bảng size'],
        ['id' => '9:16', 'name' => '9:16 — Story / TikTok', 'use' => 'Story, video dọc'],
        ['id' => '4:3', 'name' => '4:3 — Bối cảnh rộng', 'use' => 'Ảnh có không gian, hậu trường'],
    ];

    /** BỐI CẢNH CHỦ ĐỀ — preset theo bộ sưu tập/mùa/vùng miền. */
    private const BACKDROPS = [
        [
            'id' => 'studio-white', 'name' => 'Studio trắng vô cực', 'theme' => 'Thương mại sạch', 'season' => 'Quanh năm',
            'palette' => ['#ffffff', '#f4f4f2', '#dcdcd8'], 'light' => 'softbox-even',
            'props' => ['Phông giấy trắng', 'Sàn trắng liền nền', 'Không đạo cụ'],
            'prompt' => 'seamless infinite white studio backdrop, white floor blending into the wall, no visible horizon line, absolutely clean and minimal',
            'tip' => 'Dùng cho ảnh chính lên sàn: nền sạch, không tranh với sản phẩm.',
        ],
        [
            'id' => 'studio-grey', 'name' => 'Studio xám khói', 'theme' => 'Editorial', 'season' => 'Quanh năm',
            'palette' => ['#b9b9b6', '#8d8d8a', '#5f5f5d'], 'light' => 'beauty-dish',
            'props' => ['Phông xám gradient', 'Khối bê tông nhỏ'],
            'prompt' => 'mid-grey seamless studio backdrop with soft gradient falloff, subtle depth, refined editorial atmosphere',
            'tip' => 'Nền xám làm nổi màu vải trung tính và ánh kim.',
        ],
        [
            'id' => 'studio-black', 'name' => 'Studio đen kịch tính', 'theme' => 'Campaign', 'season' => 'Thu Đông',
            'palette' => ['#0d0d0f', '#1c1c20', '#3a3a40'], 'light' => 'hard-editorial',
            'props' => ['Phông đen', 'Đèn viền tách nền'],
            'prompt' => 'deep black studio backdrop, dramatic low-key lighting with a rim light separating the subject from the background',
            'tip' => 'Hợp sản phẩm tối màu, chất liệu bóng (da, satin, kim loại).',
        ],
        [
            'id' => 'hoian-wall', 'name' => 'Tường vôi Hội An', 'theme' => 'Di sản miền Trung', 'season' => 'Hè',
            'palette' => ['#e8d5a8', '#c9a86a', '#8a7a52'], 'light' => 'golden-hour',
            'props' => ['Tường vôi vàng', 'Chậu hoa giấy', 'Cửa gỗ cũ'],
            'prompt' => 'weathered ochre lime-washed wall of an old Hoi An house, warm sunlit texture, bougainvillea shadows, wooden shutters blurred behind',
            'tip' => 'Bộ sưu tập linen/cotton mùa hè, tông vàng đất.',
        ],
        [
            'id' => 'hanoi-autumn', 'name' => 'Phố cổ Hà Nội mùa thu', 'theme' => 'Thu Hà Nội', 'season' => 'Thu',
            'palette' => ['#c8a24a', '#7d6a3f', '#4a5340'], 'light' => 'window-soft',
            'props' => ['Tường rêu', 'Lá vàng rơi', 'Xe đạp cũ'],
            'prompt' => 'old Hanoi old-quarter street corner in autumn, moss-stained walls, fallen yellow leaves, soft overcast daylight, muted nostalgic tones',
            'tip' => 'Hợp blazer, trench, tông be-nâu.',
        ],
        [
            'id' => 'danang-beach', 'name' => 'Biển Đà Nẵng', 'theme' => 'Biển & nắng', 'season' => 'Hè',
            'palette' => ['#e9f1f4', '#8fc3d6', '#d9c9a8'], 'light' => 'harsh-noon',
            'props' => ['Cát trắng', 'Nước xanh', 'Ô/dù vải'],
            'prompt' => 'white sand beach on the central Vietnam coast, turquoise sea out of focus, bright midday sun, crisp shadows, breezy summer atmosphere',
            'tip' => 'Vải nhẹ, màu sáng; nắng gắt cho cảm giác rực rỡ.',
        ],
        [
            'id' => 'tropical-garden', 'name' => 'Vườn nhiệt đới', 'theme' => 'Nhiệt đới', 'season' => 'Hè',
            'palette' => ['#3f6b45', '#7fa06a', '#e6e2d3'], 'light' => 'dappled',
            'props' => ['Lá chuối', 'Sỏi trắng', 'Ghế mây'],
            'prompt' => 'lush tropical garden with large banana and monstera leaves, dappled sunlight through foliage, humid green ambience',
            'tip' => 'In hoa, màu tươi; ánh sáng lốm đốm tạo chiều sâu.',
        ],
        [
            'id' => 'saigon-cafe', 'name' => 'Quán cà phê Sài Gòn', 'theme' => 'Đô thị', 'season' => 'Quanh năm',
            'palette' => ['#6b4b32', '#a67c52', '#2f3a34'], 'light' => 'window-soft',
            'props' => ['Cửa kính lớn', 'Cây trong nhà', 'Bàn gỗ'],
            'prompt' => 'modern Saigon cafe interior, big glass windows with sheer curtains, indoor plants, warm wood and concrete, soft directional daylight',
            'tip' => 'Hợp outfit công sở/đi chơi, ánh sáng có hướng.',
        ],
        [
            'id' => 'neon-night', 'name' => 'Đêm neon', 'theme' => 'Night out', 'season' => 'Quanh năm',
            'palette' => ['#ff2d78', '#2d6bff', '#12061f'], 'light' => 'neon-mix',
            'props' => ['Biển neon', 'Mặt đường ướt', 'Khói nhẹ'],
            'prompt' => 'night street scene lit by magenta and blue neon signs, wet asphalt reflections, light haze, cinematic night ambience',
            'tip' => 'Sản phẩm bóng/da; màu neon phản chiếu phải kiểm soát.',
        ],
        [
            'id' => 'tet-red', 'name' => 'Tết cổ truyền', 'theme' => 'Tết', 'season' => 'Xuân',
            'palette' => ['#c1121f', '#f2c14e', '#7a1f1f'], 'light' => 'window-soft',
            'props' => ['Mai vàng', 'Câu đối', 'Khay trà', 'Lụa đỏ'],
            'prompt' => 'traditional Vietnamese Tet setting, red lacquer and gold accents, apricot blossoms, silk textures, warm festive daylight',
            'tip' => 'Bộ sưu tập Tết: đỏ son, vàng mai, lụa.',
        ],
        [
            'id' => 'minimal-office', 'name' => 'Công sở tối giản', 'theme' => 'Công sở', 'season' => 'Quanh năm',
            'palette' => ['#e7e6e2', '#c3c7c2', '#5b5f5c'], 'light' => 'softbox-even',
            'props' => ['Bê tông', 'Kính', 'Bàn làm việc gọn'],
            'prompt' => 'minimal contemporary office interior, concrete and glass surfaces, clean architectural lines, even soft daylight, calm neutral palette',
            'tip' => 'Chuẩn cho bộ sưu tập công sở: gọn, sạch, dễ bán.',
        ],
        [
            'id' => 'highland-tea', 'name' => 'Đồi chè Tây Bắc', 'theme' => 'Núi rừng', 'season' => 'Thu',
            'palette' => ['#5c7a4a', '#9bb184', '#c8c2ad'], 'light' => 'golden-hour',
            'props' => ['Đồi chè', 'Sương sớm', 'Đường đất'],
            'prompt' => 'terraced tea hills of northwest Vietnam, layered green ridges fading into morning mist, soft golden light, wide natural depth',
            'tip' => 'Ảnh có không gian rộng; hợp vải dày, tông đất.',
        ],
        [
            'id' => 'runway', 'name' => 'Sàn diễn (Runway)', 'theme' => 'Runway', 'season' => 'Theo mùa',
            'palette' => ['#1a1a1a', '#f5f5f5', '#8a8a8a'], 'light' => 'runway-flash',
            'props' => ['Sàn bóng', 'Khán giả mờ', 'Flash dọc'],
            'prompt' => 'fashion runway show, glossy reflective floor, blurred audience in the dark, direct flash from the photographers pit, high-fashion catwalk energy',
            'tip' => 'Dáng bước đi, ảnh dọc 4:5 hoặc 9:16.',
        ],
        [
            'id' => 'film-vintage', 'name' => 'Vintage phim', 'theme' => 'Hoài cổ', 'season' => 'Quanh năm',
            'palette' => ['#c2a27a', '#8c6f4e', '#4a3f35'], 'light' => 'window-soft',
            'props' => ['Tường gạch', 'Rèm voan', 'Đồ gỗ cũ'],
            'prompt' => 'vintage analogue film look, warm faded tones, subtle grain, old brick wall and gauze curtains, nostalgic soft light',
            'tip' => 'Chọn phong cách hậu kỳ "Vintage phim" để grade khớp nền.',
        ],
    ];

    /** SƠ ĐỒ ĐÈN — quyết định độ tương phản và chất liệu lên ảnh. */
    private const LIGHTING = [
        ['id' => 'softbox-even', 'name' => 'Softbox đều (thương mại)', 'prompt' => 'even softbox lighting from both sides, gentle shadow under the chin, clean commercial look, no harsh contrast', 'tip' => 'An toàn nhất cho ảnh bán hàng.'],
        ['id' => 'beauty-dish', 'name' => 'Beauty dish (chân dung)', 'prompt' => 'single beauty dish from above and slightly to the side, sculpted cheekbones, soft-but-defined shadow, editorial portrait light', 'tip' => 'Nổi khối khuôn mặt và vai.'],
        ['id' => 'window-soft', 'name' => 'Cửa sổ mềm', 'prompt' => 'large window light from the side, soft wraparound falloff, natural daylight mood, gentle shadow gradient across the garment', 'tip' => 'Tự nhiên, hợp lookbook.'],
        ['id' => 'golden-hour', 'name' => 'Nắng vàng cuối ngày', 'prompt' => 'low golden-hour sun from behind the subject, warm rim light on hair and shoulders, long soft shadows, glowing warm atmosphere', 'tip' => 'Ấm, lãng mạn; coi chừng cháy sáng.'],
        ['id' => 'harsh-noon', 'name' => 'Nắng gắt giữa trưa', 'prompt' => 'hard midday sunlight with crisp defined shadows, high contrast, vivid colours, sun-flare edge', 'tip' => 'Rực rỡ, hợp ảnh biển.'],
        ['id' => 'hard-editorial', 'name' => 'Đèn cứng editorial', 'prompt' => 'single hard light source, sharp defined shadow edge, high-contrast editorial lighting, glossy highlight on fabric', 'tip' => 'Kịch tính; hợp campaign.'],
        ['id' => 'neon-mix', 'name' => 'Neon pha màu', 'prompt' => 'mixed magenta and cyan neon lighting, coloured reflections on skin and fabric, night ambience, controlled colour cast', 'tip' => 'Kiểm tra lại màu sản phẩm sau khi chụp.'],
        ['id' => 'runway-flash', 'name' => 'Flash sàn diễn', 'prompt' => 'direct on-camera flash, hard shadow behind the subject, glossy skin, candid catwalk snapshot feel', 'tip' => 'Cảm giác hậu trường/street style.'],
        ['id' => 'dappled', 'name' => 'Nắng lốm đốm qua lá', 'prompt' => 'dappled sunlight filtered through leaves, irregular light patches on the garment, natural outdoor ambience', 'tip' => 'Có chiều sâu; tránh che mất chi tiết sản phẩm.'],
    ];

    /** ỐNG KÍNH & KHUNG HÌNH. */
    private const CAMERAS = [
        ['id' => '85-18', 'name' => '85mm f/1.8 — chân dung nén', 'prompt' => 'shot on 85mm at f/1.8, compressed perspective, creamy background separation'],
        ['id' => '50-28', 'name' => '50mm f/2.8 — trung thực', 'prompt' => 'shot on 50mm at f/2.8, natural perspective, true-to-eye proportions'],
        ['id' => '35-40', 'name' => '35mm f/4 — bối cảnh rộng', 'prompt' => 'shot on 35mm at f/4, wider environmental context, garment and set both readable'],
        ['id' => 'mf-80', 'name' => 'Medium format f/8 — thương mại nét căng', 'prompt' => 'medium-format look at f/8, extremely detailed fabric texture, edge-to-edge sharpness, commercial catalogue quality'],
        ['id' => 'film-35', 'name' => 'Phim 35mm — hạt mịn', 'prompt' => '35mm film emulation, visible fine grain, slightly muted highlights, analogue colour response'],
        ['id' => 'tele-200', 'name' => 'Tele 200mm — catwalk', 'prompt' => 'telephoto 200mm at f/2.8, strong compression, subject isolated from the runway crowd'],
    ];

    /** DÁNG & HƯỚNG NGƯỜI MẪU. */
    private const POSES = [
        ['id' => 'stand-34', 'name' => 'Đứng nghiêng 3/4', 'prompt' => 'model standing in a three-quarter turn, weight on the back leg, relaxed shoulders, chin slightly down'],
        ['id' => 'walking', 'name' => 'Bước đi', 'prompt' => 'model walking toward the camera mid-stride, fabric caught in motion, natural arm swing'],
        ['id' => 'front-still', 'name' => 'Đứng thẳng (e-commerce)', 'prompt' => 'model standing straight facing the camera, arms relaxed at the sides, neutral expression, symmetric framing'],
        ['id' => 'hand-pocket', 'name' => 'Tay trong túi', 'prompt' => 'model with one hand in the pocket, other hand relaxed, confident casual stance, slight hip shift'],
        ['id' => 'lean-wall', 'name' => 'Tựa tường', 'prompt' => 'model leaning lightly against the wall with a shoulder, one knee bent, relaxed editorial attitude'],
        ['id' => 'over-shoulder', 'name' => 'Quay lưng nhìn qua vai', 'prompt' => 'model turned away from the camera looking back over the shoulder, showing the back of the garment'],
        ['id' => 'seated', 'name' => 'Ngồi', 'prompt' => 'model seated on a simple stool, legs crossed elegantly, upright posture, garment draping naturally over the lap'],
        ['id' => 'hair-touch', 'name' => 'Tay chạm tóc', 'prompt' => 'model lifting one hand to the hair, soft expression, head slightly tilted, elegant movement'],
    ];

    /** DANH SÁCH ẢNH (shot list) — mỗi mục là MỘT tấm ảnh trong bộ. */
    private const SHOTS = [
        ['id' => 'full-body', 'name' => 'Toàn thân', 'ratio' => '4:5', 'framing' => 'full body from head to toe, entire outfit visible, feet included', 'use' => 'Ảnh chính của bộ'],
        ['id' => 'three-quarter', 'name' => 'Ba phần tư', 'ratio' => '4:5', 'framing' => 'three-quarter shot from above the knee, balanced presentation of the whole look', 'use' => 'Lookbook, ảnh thứ 2'],
        ['id' => 'close-face', 'name' => 'Cận mặt & vai', 'ratio' => '1:1', 'framing' => 'close-up from the chest up, focus on face and neckline detail', 'use' => 'Ảnh phụ kiện/cổ áo'],
        ['id' => 'fabric-detail', 'name' => 'Cận chi tiết vải', 'ratio' => '1:1', 'framing' => 'tight macro detail of the fabric weave, stitching and finish, the garment fills the frame', 'use' => 'Chứng minh chất lượng'],
        ['id' => 'accessory', 'name' => 'Chi tiết phụ kiện', 'ratio' => '1:1', 'framing' => 'detail shot of accessories — buttons, belt, bag or shoes — with the garment context visible', 'use' => 'Bán thêm (cross-sell)'],
        ['id' => 'back-view', 'name' => 'Mặt sau', 'ratio' => '4:5', 'framing' => 'back view of the full look, revealing the back construction and seams', 'use' => 'Bảng chi tiết sản phẩm'],
        ['id' => 'ecommerce', 'name' => 'Sàn TMĐT (1:1)', 'ratio' => '1:1', 'framing' => 'clean centred e-commerce frame, garment unobstructed, straight-on, minimal shadow', 'use' => 'Ảnh đăng sàn'],
        ['id' => 'lookbook-pair', 'name' => 'Lookbook 2 món', 'ratio' => '4:3', 'framing' => 'wider framing showing the look together with a coordinating piece, styled as a complete outfit', 'use' => 'Bán theo bộ'],
        ['id' => 'movement', 'name' => 'Chuyển động', 'ratio' => '9:16', 'framing' => 'motion frame capturing the garment in movement, fabric flying, dynamic composition', 'use' => 'Story / TikTok'],
    ];

    /** PHONG CÁCH HẬU KỲ — quyết định ảnh dùng để BÁN hay để KỂ CHUYỆN. */
    private const STYLES = [
        ['id' => 'ecommerce-clean', 'name' => 'Sàn TMĐT sạch', 'prompt' => 'clean commercial retouching, true-to-life colours, neutral white balance, minimal retouch, product-first', 'tip' => 'Màu sát thực tế nhất — dùng làm ảnh chính.'],
        ['id' => 'magazine', 'name' => 'Editorial tạp chí', 'prompt' => 'high-fashion magazine editorial grade, rich contrast, cinematic colour, stylised but believable', 'tip' => 'Đẹp để truyền thông, màu có thể lệch nhẹ.'],
        ['id' => 'lookbook-season', 'name' => 'Lookbook theo mùa', 'prompt' => 'seasonal lookbook grade, soft airy tones, natural skin, gentle film-like rolloff, cohesive set', 'tip' => 'Giữ cả bộ ảnh đồng màu.'],
        ['id' => 'campaign-luxury', 'name' => 'Campaign cao cấp', 'prompt' => 'luxury campaign grade, deep shadows, glossy highlights, refined contrast, premium feel', 'tip' => 'Hợp phân khúc premium.'],
        ['id' => 'street', 'name' => 'Street style', 'prompt' => 'candid street-style grade, slightly desaturated, documentary energy, natural imperfection', 'tip' => 'Tự nhiên, gần khách trẻ.'],
        ['id' => 'catalogue-tech', 'name' => 'Catalogue xưởng', 'prompt' => 'technical catalogue grade, flat even exposure, maximum garment legibility, zero styling distraction', 'tip' => 'Cho xưởng/đối tác xem chi tiết.'],
    ];

    /** @return array<string, list<array<string, mixed>>> */
    public function catalog(): array
    {
        $out = [
            'backdrops' => self::BACKDROPS,
            'lighting' => self::LIGHTING,
            'cameras' => self::CAMERAS,
            'poses' => self::POSES,
            'shots' => self::SHOTS,
            'styles' => self::STYLES,
            'ratios' => self::RATIOS,
        ];

        // Gợi ý ghép sẵn: bối cảnh nào đi với sơ đồ đèn nào (mỗi bối cảnh đã có 'light' mặc định).
        foreach ($out['backdrops'] as $index => $backdrop) {
            $out['backdrops'][$index]['light_name'] = $this->nameOf(self::LIGHTING, (string) $backdrop['light']);
        }

        return $out;
    }

    public function backdrops(): array { return self::BACKDROPS; }
    public function lightingOptions(): array { return self::LIGHTING; }
    public function shots(): array { return self::SHOTS; }

    /**
     * Dựng DANH SÁCH ẢNH của một buổi chụp: mỗi tấm một prompt hoàn chỉnh, dùng chung một "look signature".
     *
     * @param  array  $setup  {backdrop, lighting, camera, pose, style, shots[], ratio, collection, model_note, garment_note, extra, variants}
     * @param  int    $imageCount  số ảnh tham chiếu người dùng đã chọn (>=2 mới chạy được)
     * @param  bool   $imageReady  đã cấu hình model tạo/sửa ảnh chưa (để nói thật chế độ demo)
     * @param  int    $creditPerImage  credit mỗi ảnh (theo gói) — để ước tính trước khi chạy
     */
    public function plan(array $setup, int $imageCount = 2, bool $imageReady = true, int $creditPerImage = 1): array
    {
        $backdrop = $this->find(self::BACKDROPS, (string) ($setup['backdrop'] ?? ''));
        $customBackdrop = trim((string) ($setup['backdrop_note'] ?? ''));
        if ($backdrop === null && $customBackdrop === '') {
            $backdrop = self::BACKDROPS[0];   // mặc định: studio trắng (an toàn cho ảnh bán hàng)
        }
        $lighting = $this->find(self::LIGHTING, (string) ($setup['lighting'] ?? ''))
            ?? $this->find(self::LIGHTING, (string) ($backdrop['light'] ?? ''))
            ?? self::LIGHTING[0];
        $camera = $this->find(self::CAMERAS, (string) ($setup['camera'] ?? '')) ?? self::CAMERAS[1];
        $pose = $this->find(self::POSES, (string) ($setup['pose'] ?? '')) ?? self::POSES[0];
        $style = $this->find(self::STYLES, (string) ($setup['style'] ?? '')) ?? self::STYLES[0];

        $collection = Str::limit(trim((string) ($setup['collection'] ?? '')), 120, '');
        $modelNote = Str::limit(trim((string) ($setup['model_note'] ?? '')), 300, '');
        $garmentNote = Str::limit(trim((string) ($setup['garment_note'] ?? '')), 300, '');
        $extra = Str::limit(trim((string) ($setup['extra'] ?? '')), 400, '');
        $variants = max(1, min(3, (int) ($setup['variants'] ?? 1)));
        $defaultRatio = in_array((string) ($setup['ratio'] ?? ''), array_column(self::RATIOS, 'id'), true)
            ? (string) $setup['ratio'] : '';

        // LOOK SIGNATURE: câu giống hệt nhau ở MỌI tấm ⇒ bộ ảnh trông như cùng một buổi chụp.
        $lookId = 'LOOK-'.strtoupper(substr(sha1(json_encode([
            $backdrop['id'] ?? 'custom', $customBackdrop, $lighting['id'], $camera['id'], $pose['id'],
            $style['id'], $collection, $modelNote, $defaultRatio,
        ], JSON_UNESCAPED_UNICODE)), 0, 6));
        $signature = 'CONSISTENCY — this image is one frame of the SAME continuous photoshoot ('.$lookId.'): '
            .'the same model (identical face, hair, makeup and body proportions), the same set and background, '
            .'the same lighting direction and colour temperature, and the same colour grading as every other frame of this shoot.';

        $requested = array_values(array_unique(array_map('strval', (array) ($setup['shots'] ?? []))));
        $chosen = [];
        foreach (self::SHOTS as $shot) {
            if ($requested === [] || in_array($shot['id'], $requested, true)) {
                $chosen[] = $shot;
            }
        }
        if ($chosen === []) {
            $chosen = [self::SHOTS[0]];
        }
        $capped = count($chosen) > self::MAX_SHOTS;
        $chosen = array_slice($chosen, 0, self::MAX_SHOTS);

        $shots = [];
        foreach ($chosen as $shot) {
            $ratio = $defaultRatio !== '' ? $defaultRatio : (string) $shot['ratio'];
            $shots[] = [
                'id' => $shot['id'],
                'name' => $shot['name'],
                'use' => $shot['use'],
                'ratio' => $ratio,
                'ratio_name' => $this->nameOf(self::RATIOS, $ratio),
                'variants' => $variants,
                'prompt' => $this->shotPrompt($shot, $backdrop, $customBackdrop, $lighting, $camera, $pose, $style, $signature, [
                    'collection' => $collection, 'model_note' => $modelNote, 'garment_note' => $garmentNote, 'extra' => $extra,
                ]),
            ];
        }

        $totalImages = count($shots) * $variants;

        return [
            'engine' => 'photo-studio-v1',
            'look_id' => $lookId,
            'look_signature' => $signature,
            'setup' => [
                'backdrop' => $backdrop['id'] ?? 'custom',
                'backdrop_name' => $backdrop['name'] ?? 'Bối cảnh tự nhập',
                'backdrop_note' => $customBackdrop,
                'backdrop_theme' => $backdrop['theme'] ?? '',
                'palette' => $backdrop['palette'] ?? [],
                'lighting' => $lighting['id'],
                'lighting_name' => $lighting['name'],
                'camera' => $camera['id'],
                'camera_name' => $camera['name'],
                'pose' => $pose['id'],
                'pose_name' => $pose['name'],
                'style' => $style['id'],
                'style_name' => $style['name'],
                'collection' => $collection,
                'model_note' => $modelNote,
                'garment_note' => $garmentNote,
                'extra' => $extra,
                'variants' => $variants,
                'ratio' => $defaultRatio ?: null,
            ],
            'shots' => $shots,
            'total_shots' => count($shots),
            'total_images' => $totalImages,
            'credit_per_image' => $creditPerImage,
            'total_credits' => $totalImages * $creditPerImage,
            'image_ready' => $imageReady,
            'warnings' => $this->warnings($imageCount, $imageReady, $capped, $customBackdrop, $backdrop),
            'notes' => $this->notes(),
        ];
    }

    /** Prompt hoàn chỉnh cho MỘT tấm: giữ trang phục nguyên vẹn là điều kiện số một. */
    private function shotPrompt(array $shot, ?array $backdrop, string $customBackdrop, array $lighting, array $camera, array $pose, array $style, string $signature, array $ctx): string
    {
        $scene = $customBackdrop !== '' ? $customBackdrop : (string) ($backdrop['prompt'] ?? '');
        $brand = $ctx['collection'] !== '' ? ' Bộ sưu tập: "'.$ctx['collection'].'".' : '';

        $lines = [
            'Professional fashion photograph for a lookbook. The model wears the EXACT garment shown in the reference image — reproduce its fabric, colour, print, cut, seams, trims and proportions with 100% fidelity; do NOT redesign, restyle or change the garment.',
            'FRAME: '.$shot['framing'].'.',
            'MODEL: '.$pose['prompt'].($ctx['model_note'] !== '' ? ' '.$ctx['model_note'].'.' : ''),
            'BACKDROP: '.$scene.'.',
            'LIGHTING: '.$lighting['prompt'].'.',
            'CAMERA: '.$camera['prompt'].'.',
            'STYLE: '.$style['prompt'].'.',
            $signature.' Bộ ảnh: '.$shot['name'].' ('.(string) $shot['ratio'].').'.$brand,
        ];

        if ($ctx['garment_note'] !== '') {
            $lines[] = 'GARMENT NOTE: '.$ctx['garment_note'].'.';
        }
        if ($ctx['extra'] !== '') {
            $lines[] = 'EXTRA DIRECTION: '.$ctx['extra'].'.';
        }
        $lines[] = 'Photorealistic, sharp fabric texture, natural skin, believable anatomy, professional retouching. '
            .'No text, no watermark, no logo, no extra limbs, no distorted hands, garment colours must stay accurate.';

        return implode(' ', $lines);
    }

    /** @return list<array{level: string, message: string}> */
    private function warnings(int $imageCount, bool $imageReady, bool $capped, string $customBackdrop, ?array $backdrop): array
    {
        $out = [];
        if ($imageCount < 2) {
            $out[] = ['level' => 'error', 'message' => 'Cần ít nhất 2 ảnh: 1 ảnh TRANG PHẢI giữ nguyên + 1 ảnh người mẫu/dáng tham chiếu.'];
        }
        if (! $imageReady) {
            $out[] = ['level' => 'warning', 'message' => 'Chưa cấu hình model tạo/sửa ảnh (nhóm "edit") — buổi chụp sẽ chạy ở chế độ demo và trả ảnh mẫu, KHÔNG phải ảnh do AI tạo.'];
        }
        if ($capped) {
            $out[] = ['level' => 'warning', 'message' => 'Chỉ nhận tối đa '.self::MAX_SHOTS.' ảnh mỗi buổi chụp để tránh tốn credit ngoài ý muốn.'];
        }
        if ($customBackdrop !== '' && $backdrop === null) {
            $out[] = ['level' => 'info', 'message' => 'Đang dùng bối cảnh tự nhập — preset sẽ không áp bảng màu/đèn gợi ý.'];
        }

        return $out;
    }

    /** @return list<string> */
    private function notes(): array
    {
        return [
            'Mọi tấm trong buổi chụp dùng CHUNG một "look signature" (cùng người mẫu · nền · hướng sáng · grade) nên bộ ảnh đồng nhất như chụp một lần.',
            'Ảnh đầu tiên là ẢNH TRANG PHỤC và được giữ nguyên — AI chỉ đổi bối cảnh, ánh sáng và dáng, không thiết kế lại sản phẩm.',
            'Nên chạy 1–2 tấm trước để duyệt bối cảnh, rồi mới chạy cả danh sách ảnh.',
            'Tỉ lệ khung gợi ý theo từng loại ảnh: 1:1 cho sàn TMĐT, 4:5 cho lookbook, 9:16 cho story.',
        ];
    }

    private function find(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function nameOf(array $rows, string $id): string
    {
        return (string) ($this->find($rows, $id)['name'] ?? $id);
    }
}
