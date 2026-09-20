<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * HƯỚNG DẪN PHONG CÁCH THIẾT KẾ CHUNG (docs/DESIGN_SYSTEM.md) — khoá lại bằng máy.
 *
 * Vì sao có file test này: hướng dẫn thiết kế chỉ có giá trị khi nó (a) TỒN TẠI, (b) KHÔNG NÓI SAI
 * (mọi component nó bảo dùng phải thật sự có), và (c) những luật dễ vi phạm nhất được máy giữ hộ.
 *
 * Ba luật dễ vi phạm nhất, mỗi luật đều đã từng xảy ra thật ở card "Gợi ý từ ảnh" trước 2026-09-22:
 *   · TỰ CHẾ TIẾN TRÌNH: card có 2 bộ chấm/thanh tiến trình riêng (~90 dòng CSS gần trùng nhau)
 *     trong khi app đã có <LoadingSpinner> dùng chung;
 *   · EMOJI TRONG CHROME: 5 chip chế độ + 1 nút + 8 nhãn đặc điểm đều dùng emoji ⇒ mỗi hệ điều hành
 *     vẽ một kiểu, không theo bảng màu, và trình đọc màn hình đọc tên emoji thành tiếng;
 *   · BẢNG MÀU RIÊNG: <style scoped> tự khai 40 mã rgba() ⇒ card lệch hẳn tông của app.
 */
class DesignSystemTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(base_path($rel));
    }

    private function guide(): string
    {
        return $this->src('docs/DESIGN_SYSTEM.md');
    }

    public function test_the_shared_style_guide_exists_and_covers_the_required_ground(): void
    {
        $this->assertFileExists(base_path('docs/DESIGN_SYSTEM.md'),
            'Thiếu hướng dẫn phong cách thiết kế chung — người sau lại tự nghĩ ra chuẩn riêng.');

        foreach ([
            '## 1. Một nguồn chân lý',
            '## 2. Class dùng chung',
            '## 3. Component dùng chung',
            '## 4. Trình bày cho NGƯỜI MỚI',
            '## 5. Viền — một nghĩa, MỘT token',
            '## 6. Thông báo · chỉ báo · tiến trình',
            '## 7. Bố cục & cuộn',
            '## 8. Trợ năng',
            '## 9. Icon & emoji',
            '## 10. Checklist',
        ] as $section) {
            $this->assertStringContainsString($section, $this->guide(), 'Hướng dẫn thiết kế thiếu mục: '.$section);
        }

        // Sáu quy tắc cho người mới phải còn nguyên (đây là phần dễ bị "viết lại cho gọn" rồi mất).
        foreach (['MỘT hành động chính', 'NÓI RÕ LÝ DO', 'mặc định ĐÓNG', 'bắt buộc'] as $rule) {
            $this->assertStringContainsString($rule, $this->guide(), 'Thiếu quy tắc trình bày: '.$rule);
        }

        // Hướng dẫn phải trỏ tới đúng hai bộ test đang giữ luật này.
        $this->assertStringContainsString('DesignSystemTest', $this->guide(), 'Hướng dẫn phải nói rõ luật nào được máy giữ.');
        $this->assertStringContainsString('ToolbarAreaTest', $this->guide(), 'Hướng dẫn phải trỏ tới test khoá vùng toolbar.');
    }

    public function test_every_shared_component_named_in_the_guide_really_exists(): void
    {
        // Chống mục "tài liệu mục": hướng dẫn bảo dùng component nào thì component đó phải có thật.
        // Tên component trong hướng dẫn ghi kèm ".vue" (cũng để không lẫn với lớp PHP).
        preg_match_all('/\x60([A-Z][A-Za-z]+\.vue)\x60/', $this->guide(), $m);
        $named = array_unique($m[1] ?? []);
        $this->assertGreaterThanOrEqual(5, count($named), 'Không đọc được danh sách component dùng chung trong hướng dẫn.');

        // Tìm trong TOÀN BỘ resources/js/studio (component nằm ở components/, app gốc nằm ở ngoài).
        $onDisk = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('js/studio')) as $f) {
            $onDisk[$f->getFilename()] = true;
        }

        foreach ($named as $file) {
            $this->assertArrayHasKey($file, $onDisk,
                'Hướng dẫn nhắc tới '.$file.' nhưng file này không tồn tại trong resources/js/studio.');
        }

        // Con số icon trong hướng dẫn phải khớp icons.json (nguồn duy nhất của cả Vue lẫn PHP).
        $icons = json_decode((string) file_get_contents(resource_path('js/studio/icons.json')), true);
        $this->assertIsArray($icons);
        $this->assertStringContainsString('**'.count($icons).' icon**', $this->guide(),
            'Số icon ghi trong hướng dẫn đã lệch với icons.json — sửa tài liệu, đừng để nó nói sai.');
    }

    public function test_the_suggest_card_does_not_build_its_own_progress_widget(): void
    {
        $card = $this->src('resources/js/studio/components/SuggestCard.vue');

        // Phải DÙNG component tiến trình dùng chung…
        $this->assertStringContainsString("import LoadingSpinner from './LoadingSpinner.vue'", $card,
            'Card phải dùng <LoadingSpinner> dùng chung thay vì tự vẽ tiến trình.');
        $this->assertStringContainsString('<LoadingSpinner', $card, 'Import rồi mà không render thì vô nghĩa.');

        // …và KHÔNG được dựng lại bộ chấm/thanh riêng (đúng thứ đã bị gỡ ở đợt 2026-09-22).
        foreach (['genflow-step', 'genflow-dot', 'genflow-bar', 'suggest-stage', 'suggest-bar', 'suggest-connector'] as $dead) {
            $this->assertStringNotContainsString($dead, $card,
                'Card lại tự dựng tiến trình riêng ('.$dead.') — hãy dùng LoadingSpinner.');
        }
    }

    public function test_the_suggest_card_has_no_emoji_and_no_private_palette(): void
    {
        $card = $this->src('resources/js/studio/components/SuggestCard.vue');

        // (1) Không emoji trong chrome. (Mũi tên kiểu chữ → ↳ không tính — đó là quy ước chung.)
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $card),
            'Card còn emoji — dùng <StudioIcon> (icons.json) thay thế.');

        // (2) Không bảng màu riêng: <style scoped> phải NGẮN, chỉ một gradient nhận diện.
        // Neo ở ĐẦU DÒNG: chuỗi "<style scoped>" có thể xuất hiện trong chú thích của script.
        preg_match('/^<style scoped>\n(.*?)^<\/style>/ms', $card, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được khối <style scoped> của card.');
        $style = $m[1];
        $lines = substr_count(trim($style), "\n") + 1;

        $this->assertLessThanOrEqual(35, $lines,
            'Khối <style scoped> phình to ('.$lines.' dòng): giao diện phải dựng bằng class dùng chung + token, không phải CSS riêng.');
        $this->assertSame(1, substr_count($style, 'linear-gradient'),
            'Card chỉ được có ĐÚNG MỘT gradient nhận diện — thêm nữa là tự tạo bảng màu riêng.');

        // Màu phải lấy từ token, không phải mã màu hex tự nghĩ.
        $this->assertSame(0, preg_match('/#[0-9a-fA-F]{6}\b/', $style),
            'Khối style tự khai mã màu hex — dùng var(--color-…) hoặc tiện ích Tailwind.');
    }

    public function test_the_suggest_card_keeps_one_primary_action_and_explains_blocked_buttons(): void
    {
        $card = $this->src('resources/js/studio/components/SuggestCard.vue');

        // Quy tắc 3: chỉ MỘT nút chính tại một thời điểm ⇒ nút ở bước ③ phải LÙI về thứ yếu khi có kết quả.
        $this->assertMatchesRegularExpression(
            "/:class=\"hasResult \? '[^']*btn-ghost[^']*' : 'btn-brand'\"/",
            $card,
            'Nút phân tích phải tự lùi về thứ yếu khi đã có kết quả — nếu không sẽ có HAI nút chính cùng lúc.'
        );

        // Quy tắc 4: nút bị khoá phải nói rõ lý do ngay dưới.
        $this->assertStringContainsString('const blockReason = computed', $card, 'Thiếu lý do khoá nút.');
        $this->assertMatchesRegularExpression('/\{\{ blockReason \}\}/', $card, 'Lý do khoá nút chưa được hiển thị.');

        // Quy tắc 5: thứ nâng cao/ít dùng thu vào <details> đóng sẵn (Nâng cao + Gợi ý gần đây).
        $this->assertGreaterThanOrEqual(2, substr_count($card, '<details'),
            'Thứ nâng cao và danh sách lịch sử phải gấp trong <details> để card không dài trước mắt người mới.');
        $this->assertStringNotContainsString('<details open', $card, 'Không mở sẵn khối nâng cao.');

        // Quy tắc 1: có đánh số bước.
        $this->assertStringContainsString('Ảnh nguồn', $card);
        $this->assertStringContainsString('Kiểu gợi ý', $card);
    }

    /**
     * VIỀN CỦA NÚT — một nghĩa chỉ có ĐÚNG MỘT token.
     *
     * Đo trước khi đồng bộ (2026-09-22): **30 biến thể viền** trên các phần tử bấm được, trong đó
     * cùng một nghĩa "nút nghỉ" bị viết bằng HAI token (border-ink-700 và border-ink-600 — 58 vs 54
     * chỗ), "đang chọn" bằng cả border-brand-400 lẫn border-brand-500 (21 vs 18 chỗ), và mỗi màu
     * ngữ nghĩa bị rải ra 3–4 mức alpha (red-500 /30 /40 /60, amber-500/40 /50, emerald-400 /40 /50 /80…).
     * Hệ quả: hai nút cạnh nhau lệch màu viền mà không ai cố ý; sửa "gu" viền phải đi tìm từng chỗ.
     *
     * Bài test này là bộ TỪ VỰNG đóng: thêm một token viền mới cho nút là ĐỎ ngay, buộc người viết
     * chọn một token đã có (hoặc cập nhật bảng trong docs/DESIGN_SYSTEM.md §5 rồi mở rộng danh sách
     * này một cách có ý thức).
     */
    public function test_button_borders_use_one_token_per_meaning(): void
    {
        // Từ vựng ĐÓNG của viền trên phần tử bấm được (khoá => nghĩa).
        $allowed = [
            // Trung tính
            'border' => true, 'border-2' => true, 'border-4' => true, 'border-dashed' => true, 'border-transparent' => true,
            'border-ink-600' => true,                                  // nút nghỉ
            'hover:border-ink-500' => true,                            // hover "êm" cho nút rất phụ
            // Nhấn / đang chọn
            'border-brand-500' => true,                                // ĐANG CHỌN
            'hover:border-brand-400' => true,                          // hover nút chưa chọn
            // Ngữ nghĩa
            'border-red-500/40' => true,   'hover:border-red-500' => true,   'border-red-500' => true,   // nguy hiểm
            'border-amber-500/40' => true,                                                              // cảnh báo
            'border-emerald-500/40' => true,                                                            // thành công
            'border-sky-500/40' => true,                                                                // thông tin
            // Ngoại lệ có lý do (đã ghi trong tài liệu)
            'border-cream-300/50' => true, 'hover:border-cream-200' => true,   // checkbox chọn ảnh (nổi trên mọi ảnh)
            'hover:border-cream-300' => true,                                  // nút kiểu btn-outline trên nền tối
        ];

        /* Màu NHẤN RIÊNG của card — ngoại lệ DUY NHẤT, và phải dùng nhất quán trong cả card
           (viền + nền + icon cùng một họ màu), KHÔNG bao giờ dùng cho nút hành động chung:
             · RefImageCard.vue — bộ chọn khuôn mặt/dáng/tư thế (49 token emerald: viền, nền, icon)
             · ConceptCard.vue  — khối "Tư thế người mẫu (kế thừa từ chip Thử đồ)"
             · InpaintCard.vue  — nút bật/tắt vùng chọn
           Danh sách này ĐÓNG: card khác dùng emerald-400 làm viền nút là ĐỎ. */
        $accentAllowed = ['RefImageCard.vue' => true, 'ConceptCard.vue' => true, 'InpaintCard.vue' => true];
        $accentTokens = ['border-emerald-400', 'border-emerald-400/40', 'hover:border-emerald-400'];

        $violations = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('js/studio')) as $file) {
            if ($file->getExtension() !== 'vue') continue;
            $name = $file->getFilename();
            $html = (string) file_get_contents($file->getPathname());

            preg_match_all('/<(button|a|label)\b[^>]*>/s', $html, $tags);
            foreach ($tags[0] as $tag) {
                preg_match_all('/:?class="([^"]*)"/', $tag, $attrs);
                foreach ($attrs[1] as $classStr) {
                    // Bỏ tiền tố "!" (important) trước khi tra từ vựng.
                    preg_match_all('/(?:[a-z-]+:)*!?border(?:-(?!dashed|solid)[a-z0-9\/\[\]#._-]+)?/', $classStr, $found);
                    foreach ($found[0] as $token) {
                        $token = str_replace('!', '', $token);
                        if (isset($allowed[$token])) continue;
                        if (in_array($token, $accentTokens, true) && isset($accentAllowed[$name])) continue;
                        $violations[] = $name.': '.$token;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            "Viền nút dùng token NGOÀI từ vựng chung. Xem docs/DESIGN_SYSTEM.md §5 \"Viền\" — "
            .'nút nghỉ = border-ink-600 · đang chọn = border-brand-500 · hover = hover:border-brand-400 · '
            .'nguy hiểm = border-red-500/40 (+ hover:border-red-500) · cảnh báo/thành công/thông tin = .../500/40.');

        // Class DÙNG CHUNG cũng phải theo đúng từ vựng đó (nút dùng .tool-btn không được lệch với nút viết tay).
        $css = $this->src('resources/css/app.css');
        $this->assertStringContainsString('.tool-btn { @apply inline-flex items-center gap-1.5 rounded-md border border-ink-600',
            $css, 'Nút nghỉ của .tool-btn phải là border-ink-600 — lệch với nút viết tay là hai nút cạnh nhau khác viền.');
        $this->assertStringNotContainsString('rounded-md border border-ink-700 bg-ink-800 px-2.5 py-1.5',
            $css, 'Đã quay lại viền nghỉ border-ink-700 cho .tool-btn.');
    }

    /**
     * KHÔNG EMOJI TRONG CHROME — khoá lại sau khi dọn hết nợ (2026-09-23).
     *
     * Đo trước khi dọn: 134 lần xuất hiện trên 23 file (ConceptCard 43 · store.js 18 · AdminApp 7 …).
     * Ba lý do thật của luật này (docs/DESIGN_SYSTEM.md §9): mỗi hệ điều hành vẽ một kiểu nên bố cục
     * lệch nhau; emoji không theo bảng màu nên phá tông của card; trình đọc màn hình đọc tên emoji
     * thành tiếng, chen vào giữa nhãn.
     *
     * KÝ HIỆU CHỮ được phép (không phải emoji hình, và tài liệu §8 dùng chúng làm tín hiệu phi màu):
     * ✓ (đã lưu) · ✕ (đóng) · ✗ · ★. Emoji trong NỘI DUNG do người dùng/AI sinh ra cũng ngoài phạm vi
     * test này (test chỉ quét mã giao diện).
     */
    public function test_no_pictographic_emoji_in_studio_chrome(): void
    {
        // Nháy ĐÔI: PHP chỉ hiểu \u{…} trong chuỗi nháy đôi (chuỗi nháy đơn là văn bản thô).
        $allowed = ["\u{2713}", "\u{2715}", "\u{2717}", "\u{2605}", "\u{2606}"];
        $pattern = '/[\x{1F300}-\x{1FAFF}\x{2B00}-\x{2BFF}\x{FE0F}]|[\x{2600}-\x{27BF}]/u';

        $violations = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('js/studio')) as $file) {
            if (! in_array($file->getExtension(), ['vue', 'js'], true)) continue;
            $body = (string) file_get_contents($file->getPathname());
            if (preg_match_all($pattern, $body, $m)) {
                foreach (array_unique($m[0]) as $hit) {
                    if (in_array($hit, $allowed, true)) continue;
                    $violations[] = $file->getFilename().': '.$hit;
                }
            }
        }
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
            $body = (string) file_get_contents($file->getPathname());
            if (preg_match_all($pattern, $body, $m)) {
                foreach (array_unique($m[0]) as $hit) {
                    if (in_array($hit, $allowed, true)) continue;
                    $violations[] = 'views/'.$file->getFilename().': '.$hit;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            "Có emoji trong chrome của studio. Xem docs/DESIGN_SYSTEM.md §9 — dùng <StudioIcon> (icons.json) "
            .'thay cho emoji; emoji chỉ còn chấp nhận trong nội dung do người dùng/AI sinh ra.');
    }

    /**
     * NỀN CỦA NÚT — một trạng thái, MỘT token.
     *
     * Đo trước khi đồng bộ (2026-09-23): nút nghỉ có BA kiểu nền khác nhau — bg-ink-800 (đa số),
     * "kính mờ" bg-white/5 (sau đợt theme đổi thành bg-cream-50/5) và bg-ink-900/90 — nên hai nút
     * cạnh nhau lệch nền mà không ai cố ý. Đây đúng là vết lặp của lỗi VIỀN (§5.2) đã sửa trước đó.
     *
     * Từ vựng: nút nghỉ = bg-ink-800 · hover = bg-ink-700 · nền màu/nhấn = token ngữ nghĩa
     * (bg-brand-600 · bg-brand-500 · bg-danger/10 …). Nút đặt TRÊN ẢNH dùng token cố định
     * bg-scrim/NN + text-scrim-content (§1.1 quy tắc 5) — đó là môi trường ảnh, không phải bề mặt.
     */
    public function test_button_backgrounds_use_one_token_per_state(): void
    {
        // Nền "kính mờ"/alpha lạ: KHÔNG bao giờ dùng cho trạng thái nghỉ của nút.
        $forbidden = [
            'bg-ink-900/90', 'bg-ink-900/95', 'bg-ink-900/85', 'bg-ink-900/80', 'bg-ink-900/70',
            'bg-ink-800/90', 'bg-ink-800/80', 'bg-ink-800/70', 'bg-ink-800/60',
            'bg-cream-50/5', 'bg-cream-50/10', 'bg-cream-50/15', 'bg-cream-50/20',
            'bg-white/5', 'bg-white/10', 'bg-white/20',
        ];

        $violations = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('js/studio')) as $file) {
            if ($file->getExtension() !== 'vue') continue;
            $html = (string) file_get_contents($file->getPathname());
            preg_match_all('/<(button|a|label)\b[^>]*>/s', $html, $tags);
            foreach ($tags[0] as $tag) {
                foreach ($forbidden as $bad) {
                    if (str_contains($tag, $bad)) {
                        $violations[] = $file->getFilename().': '.$bad;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            "Nút dùng nền 'kính mờ'/alpha cho trạng thái NGHỈ ⇒ hai nút cạnh nhau lệch nền. "
            .'Xem docs/DESIGN_SYSTEM.md §5.3 — nút nghỉ = bg-ink-800 · hover = bg-ink-700 · nút trên ẢNH = bg-scrim/NN.');

        // Nút dùng CHUNG cũng phải theo đúng nền đó (không lệch với nút viết tay).
        $css = $this->src('resources/css/app.css');
        $this->assertStringContainsString('.tool-btn { @apply inline-flex items-center gap-1.5 rounded-md border border-ink-600 bg-ink-800',
            $css, '.tool-btn phải dùng nền nghỉ bg-ink-800 — lệch với nút viết tay là hai nút cạnh nhau khác nền.');
    }
}
