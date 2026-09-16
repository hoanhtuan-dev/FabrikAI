<?php

namespace App\Services;

use App\Models\StylistGarmentType;
use App\Models\StylistPreset;
use App\Models\StylistQuestion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * StylistCatalog — dữ liệu xương sống của ✨ Thuật sỹ (module hóa, dễ kế thừa/mở rộng).
 * Đọc từ DB (quản trị qua trang /studio/stylist-data); fallback về dữ liệu mặc định khi DB rỗng.
 */
class StylistCatalog
{
    /**
     * Tự tạo bảng + seed khi chưa có (shared hosting có thể không chạy được `php artisan migrate`).
     * Chỉ chạy thật ở lần đầu; các lần sau Schema::hasTable trả true nên gần như không tốn gì.
     */
    public function ensureTables(): void
    {
        $hasAll = false;
        try {
            $hasAll = Schema::hasTable('stylist_garment_types') && Schema::hasTable('stylist_questions') && Schema::hasTable('stylist_presets');
        } catch (\Throwable $e) {
            $hasAll = false;
        }

        if (! $hasAll) {
        try {
            Schema::create('stylist_garment_types', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 40)->unique();
                $table->string('name', 120);
                $table->string('emoji', 8)->default('');
                $table->string('color', 20)->default('#4a7a90');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            // bảng đã tồn tại (race) — bỏ qua
        }

        try {
            Schema::create('stylist_questions', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();
                $table->string('question', 500);
                $table->json('options');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            // bảng đã tồn tại (race) — bỏ qua
        }

        try {
            Schema::create('stylist_presets', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->text('prompt');
                $table->string('type', 40)->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        } catch (\Throwable $e) {
            // bảng đã tồn tại (race) — bỏ qua
        }
        }

        // Seed nếu rỗng (idempotent) — chạy MỌI lần để bù dữ liệu mặc định khi rỗng.
        try {
            if (! StylistGarmentType::exists()) {
                foreach ($this->defaultGarmentTypes() as $i => $t) {
                    StylistGarmentType::firstOrCreate(
                        ['slug' => $t['id']],
                        ['name' => $t['name'], 'emoji' => $t['emoji'], 'color' => $t['color'], 'sort_order' => $i],
                    );
                }
            }
            if (! StylistQuestion::exists()) {
                foreach ($this->defaultQuestions() as $i => $q) {
                    StylistQuestion::firstOrCreate(
                        ['key' => $q['key']],
                        ['question' => $q['q'], 'options' => $q['opts'], 'sort_order' => $i],
                    );
                }
            }
            // Seed preset mặc định (1 prompt gốc cho mỗi loại) để danh sách Preset không bao giờ rỗng.
            if (! StylistPreset::exists()) {
                foreach ($this->defaultGarmentTypes() as $i => $t) {
                    StylistPreset::firstOrCreate(
                        ['prompt' => $this->basePrompt((string) $t['name'])],
                        ['name' => $t['name'], 'type' => $t['id'], 'sort_order' => $i],
                    );
                }
            }
        } catch (\Throwable $e) {
            // seed lỗi — không chặn luồng chính
        }
    }

    public function garmentTypes(): array
    {
        $this->ensureTables();
        try {
            $rows = StylistGarmentType::orderBy('sort_order')->orderBy('id')->get();
        } catch (\Throwable $e) {
            $rows = collect(); // bảng chưa migrate -> dùng dữ liệu mặc định
        }

        if ($rows->isEmpty()) {
            $items = collect($this->defaultGarmentTypes());
        } else {
            $items = $rows->map(fn ($r) => ['id' => $r->slug, 'name' => $r->name, 'emoji' => $r->emoji, 'color' => $r->color]);
        }

        return $items->map(fn ($t) => $this->hydrateType(
            (string) $t['id'],
            (string) $t['name'],
            (string) ($t['emoji'] ?? ''),
            (string) ($t['color'] ?? ''),
        ))->values()->all();
    }

    protected function hydrateType(string $id, string $name, string $emoji, string $color): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'emoji' => $emoji,
            'color' => $color,
            'img' => '/garment/'.$id,
            'thumb' => '/garment/'.$id.'/thumb',
        ];
    }

    public function nameOf(string $id): string
    {
        foreach ($this->garmentTypes() as $t) { if ($t['id'] === $id) { return $t['name']; } }
        return ucfirst($id);
    }

    /**
     * Ma trận câu hỏi xương sườn (khảo sát nhanh) — điều chỉnh theo loại trang phục.
     */
    public function questions(string $type): array
    {
        $this->ensureTables();
        $g = $this->nameOf($type);
        try {
            $rows = StylistQuestion::orderBy('sort_order')->orderBy('id')->get();
        } catch (\Throwable $e) {
            $rows = collect(); // bảng chưa migrate -> dùng dữ liệu mặc định
        }

        if ($rows->isEmpty()) {
            $items = collect($this->defaultQuestions());
        } else {
            $items = $rows->map(fn ($r) => ['key' => $r->key, 'q' => $r->question, 'opts' => $r->options ?? []]);
        }

        return $items->map(fn ($r) => [
            'key' => (string) $r['key'],
            'q' => str_replace('{name}', $g, (string) $r['q']),
            'opts' => array_values((array) ($r['opts'] ?? [])),
        ])->values()->all();
    }

    /** 18 loại trang phục dựng sẵn (không trùng lặp). Dùng làm seed mặc định. */
    public function defaultGarmentTypes(): array
    {
        return [
            ['id' => 'dress',        'name' => 'Đầm',              'emoji' => '👗', 'color' => '#e8577d'],
            ['id' => 'top',          'name' => 'Áo sơ mi / Blouse','emoji' => '👚', 'color' => '#7aa7e0'],
            ['id' => 'pants',        'name' => 'Quần',             'emoji' => '👖', 'color' => '#6a8f6a'],
            ['id' => 'skirt',        'name' => 'Chân váy',         'emoji' => '🩰', 'color' => '#b57bd0'],
            ['id' => 'shorts',       'name' => 'Quần short',       'emoji' => '🩳', 'color' => '#e0a95a'],
            ['id' => 'jacket',       'name' => 'Áo khoác',         'emoji' => '🧥', 'color' => '#8a6a4a'],
            ['id' => 'aodai',        'name' => 'Áo dài',           'emoji' => '💃', 'color' => '#d04a4a'],
            ['id' => 'set',          'name' => 'Set đồ',           'emoji' => '🧥', 'color' => '#4a7a90'],
            ['id' => 'tshirt',       'name' => 'Áo thun',          'emoji' => '👕', 'color' => '#5aa0c8'],
            ['id' => 'hoodie',       'name' => 'Áo hoodie',        'emoji' => '🧶', 'color' => '#9a7ad0'],
            ['id' => 'denimjacket',  'name' => 'Áo denim',         'emoji' => '🧥', 'color' => '#5a8a9a'],
            ['id' => 'blazer',       'name' => 'Áo blazer',        'emoji' => '🕴️', 'color' => '#6a7a8a'],
            ['id' => 'weddingdress', 'name' => 'Đầm cưới',         'emoji' => '💍', 'color' => '#e8e0d8'],
            ['id' => 'bodysuit',     'name' => 'Bodysuit',         'emoji' => '🩱', 'color' => '#c88aa0'],
            ['id' => 'jogger',       'name' => 'Quần jogger',      'emoji' => '👖', 'color' => '#8aa06a'],
            ['id' => 'windbreaker',  'name' => 'Áo gió',           'emoji' => '🧥', 'color' => '#6aa0b0'],
            ['id' => 'tanktop',      'name' => 'Áo ba lỗ',         'emoji' => '🎽', 'color' => '#e0b06a'],
            ['id' => 'bomber',       'name' => 'Áo bomber',        'emoji' => '🧥', 'color' => '#7a6a5a'],
        ];
    }

    /** Ma trận câu hỏi mặc định (seed). 'silhouette' dùng placeholder {name} theo loại trang phục. */
    public function defaultQuestions(): array
    {
        return [
            ['key' => 'model', 'q' => 'Người mẫu nữ (Việt):', 'opts' => [
                'Trẻ trung 18-25, thanh mảnh, tóc dài đen, da sáng',
                'Thanh xuân 25-32, cao 1m68+, tóc xoăn, da nâu vàng',
                'Trưởng thành 32-40, đầy đặn, tóc ngắn cá tính, da ngăm',
                'Cận trung niên 40-50, quyến rũ, tóc búi, da sáng',
            ]],
            ['key' => 'silhouette', 'q' => 'Phom/ silhouette {name} (kỹ thuật dựng):', 'opts' => [
                'Ôm sát (fitted): may co giãn 2-4%, tôn dáng, đường may princess tách eo',
                'Suông (straight): cắt đơn giản, rộng vừa, không chiết ly, phom H',
                'A-line: xòe dần từ eo, độ rộng gấu +8-12cm, phom A mềm mại',
                'Xòe cong (flare): chân váy xòe rộng, dún hoặc chéo sợi 45°',
                'Oversized/boxy: rộng rộng, tay rộng, phong cách street',
            ]],
            ['key' => 'fabric', 'q' => 'Chất liệu (kỹ thuật dệt):', 'opts' => [
                'Lụa satin mềm (độ bóng cao, rũ đẹp, 12-16 momme)',
                'Chiffon mỏng nhẹ (xếp lớp 2-3 lớp, bay, không bóng)',
                'Cotton thoáng (GSM 180-240, ít nhăn, dễ bảo quản)',
                'Dệt kim co giãn (lycra 5-8%, không nhăn, mềm)',
                'Voan lưới (polyester, xòe cứng cáp, giữ phom)',
                'Denim (cotton 12-14oz, chà xát, bền)',
                'Da / faux leather (mềm, bóng nhẹ, có độ cứng)',
            ]],
            ['key' => 'color', 'q' => 'Màu nhuộm (xu hướng VN):', 'opts' => [
                'Đen huyền bí', 'Đỏ rượu vang (đô)', 'Pastel hồng/be (nhãn)', 'Xanh navy thanh lịch', 'Trắng/cream sạch sẽ', 'Xanh rêu/military',
            ]],
            ['key' => 'details', 'q' => 'Chi tiết may (kỹ thuật):', 'opts' => [
                'Cổ chữ V sâu + gọng ngực ẩn', 'Tay phồng bồng (xếp ly cánh tay, độ phồng 2 lần vai)', 'Corset thắt eo (xương mềm + ren)', 'Viền ren + dún lưng (độ rũ 1.5 lần)', 'Khuy thắt + phối màu tương phản', 'Xếp tầng 3-4 lớp (layer)', 'Túi hộp may nổi + nẹp viền',
            ]],
            ['key' => 'occasion', 'q' => 'Dịp & bối cảnh (thị trường VN):', 'opts' => [
                'Tiệc tối sang trọng', 'Đi làm thanh lịch', 'Dạo phố trẻ trung', 'Sự kiện/catwalk', 'Hẹn hò lãng mạn', 'Lễ cưới / dự lễ',
            ]],
            ['key' => 'setting', 'q' => 'Bối cảnh & ánh sáng (chụp):', 'opts' => [
                'Studio tối giản, ánh sáng mềm (softbox)', 'Phố cổ / kiến trúc, nắng vàng (golden hour)', 'Thiên nhiên xanh, ánh sáng tự nhiên', 'Catwalk / sàn diễn, đèn neon', 'Trong nhà sang trọng, ánh đèn chùm',
            ]],
            ['key' => 'style', 'q' => 'Phong cách / cảm hứng (trend):', 'opts' => [
                'Minimal thanh lịch', 'Luxury couture', 'Boho tự do', 'Streetwear hiện đại', 'Retro/cổ điển', 'Futuristic avant-garde',
            ]],
        ];
    }

    // ── Presets (prompt đã lưu từ Trợ lý thiết kế) ──

    /** Prompt EN cơ bản cho một loại trang phục (dùng để seed preset mặc định). */
    public function basePrompt(string $name): string
    {
        return 'A high-fashion editorial photo of a women\'s '.$name.', an elegant contemporary design, worn by a young Vietnamese woman (slim, fair skin, long black hair), styled for an elegant occasion, refined editorial aesthetic, set in a clean minimal studio, premium Vogue editorial, full-body, refined silhouette, soft even studio lighting, ultra detailed, 4k';
    }

    public function savePreset(string $name, string $prompt, string $type = ''): void
    {
        $this->ensureTables();
        try {
            if (StylistPreset::where('prompt', $prompt)->exists()) {
                return; // dedup
            }
            StylistPreset::create([
                'name' => $name,
                'prompt' => $prompt,
                'type' => $type,
                'sort_order' => (int) StylistPreset::max('sort_order') + 1,
            ]);
        } catch (\Throwable $e) {
            // bỏ qua lỗi lưu preset — không chặn luồng tạo prompt
        }
    }

    public function presets(): array
    {
        $this->ensureTables();
        try {
            return StylistPreset::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'prompt' => $p->prompt, 'type' => $p->type])
                ->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function deletePreset(int $id): void
    {
        $this->ensureTables();
        try {
            StylistPreset::where('id', $id)->delete();
        } catch (\Throwable $e) {
            // bỏ qua
        }
    }
}
