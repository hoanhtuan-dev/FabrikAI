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
        /* [2026-09-23] KHÔNG còn gradient nhận diện: mọi card dùng CHUNG bề mặt của theme
           (.card → base-100). Trước đây mỗi card có một gradient riêng nên 9 card là 9 sắc thái. */
        $this->assertSame(0, substr_count($style, 'linear-gradient'),
            'Card không được tự khai gradient/nền riêng — bề mặt do lớp .card và token của theme quyết định.');

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

        /* [2026-09-23] NGOẠI LỆ "MÀU NHẤN RIÊNG CỦA CARD" ĐÃ ĐƯỢC GỠ HẲN.
           Trước đây ba card (RefImageCard · ConceptCard · InpaintCard) được phép dùng emerald-400 làm
           viền nút vì chúng "có màu nhấn riêng". Hệ quả thật: cùng một trạng thái "đang chọn" mà chỗ
           thì xanh lá thương hiệu, chỗ thì emerald — người dùng phải học hai lần, và bảng màu có thêm
           một họ màu không thuộc hệ. Nay cả ba đã được thiết kế lại:
             · "đang chọn"  -> border-brand-500 + bg-brand-600/20 + ring-brand-500/40
             · "khối chứa"  -> border-ink-700 + bg-ink-900
             · "thành công" -> token ngữ nghĩa ok (border-ok/40 · bg-ok/10 · text-ok)
           Nên KHÔNG còn danh sách miễn trừ nào: bất kỳ token emerald nào làm viền nút là ĐỎ. */

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

        /* [Đợt 31 — 2026-09-23] TỪ VỰNG PHẢI ĐÓNG CẢ Ở LỚP CSS.
           Lỗ thật: test chỉ quét file .vue, nên token viền khai trong app.css thoát khỏi từ vựng —
           '.tool-btn.is-active' dùng border-brand-500/70 trong khi thẻ hướng dùng border-brand-500,
           tức là CÙNG trạng thái "đang chọn" mà hai kiểu viền trên một màn hình. Nay quét cả app.css. */
        // Ở lớp CSS có thêm hai token hợp lệ KHÔNG thuộc bảng "viền nút": khối CHỨA dùng border-ink-700
        // (§5.1) và ô NHẬP dùng focus:border-brand-400. Cạnh viền thuần (border-l, border-r-0…) không có
        // màu nên không tính.
        $cssAllowed = $allowed + ['border-ink-700' => true, 'focus:border-brand-400' => true];
        preg_match_all('/@apply[^;]*/', $css, $applies);
        $cssViolations = [];
        foreach ($applies[0] as $apply) {
            preg_match_all('/(?:[a-z-]+:)*!?border(?:-(?!dashed|solid)[a-z0-9\/\[\]#._-]+)?/', $apply, $found);
            foreach ($found[0] as $token) {
                $token = str_replace('!', '', $token);
                if ($token === 'border' || isset($cssAllowed[$token])) {
                    continue;
                }
                if (preg_match('/^border-[ltrbxy](-\d+)?$/', $token)) {
                    continue;   // cạnh viền không màu: không thuộc bảng token màu
                }
                $cssViolations[] = $token;
            }
        }
        $this->assertSame([], array_values(array_unique($cssViolations)),
            'Lớp CSS dùng token viền NGOÀI từ vựng §5.1 (đây là lỗ đã để lọt border-brand-500/70).');
    }

    /**
     * MỌI CARD DÙNG CHUNG MỘT BỀ MẶT (yêu cầu 2026-09-23).
     *
     * Trước: mỗi card tự khai một gradient nhận diện RIÊNG bằng style inline — 9 card là 9 sắc thái
     * (xanh · tím · cam · xanh dương…), màu nằm ngoài bảng màu, và người dùng phải "học" lại từng card.
     * Nay: chỉ lớp `.card` + token của theme quyết định bề mặt; không card nào tự khai nền.
     */
    public function test_every_card_uses_the_one_shared_surface(): void
    {
        $violations = [];

        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('js/studio/components')) as $file) {
            if ($file->getExtension() !== 'vue') continue;
            $src = (string) file_get_contents($file->getPathname());

            // (a) Không tự khai nền bằng style inline (gradient nhận diện của card).
            //     Miễn trừ: ô bàn cờ "trong suốt" của layer — đó là CHỈ BÁO TRONG SUỐT (môi trường ảnh,
            //     cùng nhóm với .canvas-bg-*), không phải bề mặt card.
            if (preg_match_all('/style="background:(?!\s*repeating-conic-gradient)[^"]*"/', $src, $m)) {
                foreach ($m[0] as $hit) {
                    $violations[] = $file->getFilename().': '.substr($hit, 0, 44);
                }
            }

            // (b) Không có gradient nhận diện trong <style scoped>.
            //     Miễn trừ: gradient của hiệu ứng TẢI (skeleton) — đó là chuyển động, không phải bề mặt card.
            if (preg_match('/<style scoped>(.*?)<\/style>/s', $src, $sm) && str_contains($sm[1], 'linear-gradient')) {
                if (! str_contains($sm[1], 'skeleton') && ! str_contains($src, 'skeleton')) {
                    $violations[] = $file->getFilename().': gradient trong <style scoped>';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            "Card tự khai nền/màu riêng. Mọi card dùng CHUNG lớp .card (bề mặt base-100 của theme) — "
            .'xem docs/DESIGN_SYSTEM.md §2 (một bề mặt) và §4 (trình bày cho người mới).');
    }

    /**
     * NÚT CHÍNH BỊ KHOÁ PHẢI NÓI RÕ LÝ DO (docs/DESIGN_SYSTEM.md §4 quy tắc 4).
     *
     * Đo trước khi sửa (2026-09-23): 12 nút chính bị khoá mà không có dòng lý do — người dùng chỉ
     * thấy một nút mờ và phải tự đoán thiếu gì (thiếu ảnh nguồn? thiếu mô tả? chưa chọn ảnh?).
     *
     * Bất biến: nút chính (`btn-brand`) bị khoá bởi điều kiện có PHỦ ĐỊNH một thứ KHÔNG phải trạng
     * thái "đang chạy" thì ngay dưới nó phải có dòng lý do bắt đầu bằng ↳.
     * Miễn trừ hợp lệ: điều kiện CHỈ là cờ đang-chạy (nhãn nút đã đổi thành "Đang gửi…") hoặc chỉ là
     * chốt theo bước (step === 'brief' && đang tải) — không phải điều kiện người dùng cần gỡ.
     */
    public function test_blocked_primary_buttons_explain_the_reason(): void
    {
        // Cờ "đang chạy": khoá nút trong lúc chờ, KHÔNG cần dòng lý do (nhãn nút tự nói).
        $busyFlags = ['busy', 'loading', 'saving', 'sending', 'running', 'generating', 'inpainting', 'upscaling',
            'shareBusy', 'editorBusy', 'reviewBusy', 'videoBusy', 'shopSaving', 'planLoading',
            'collectionBriefLoading', 'renderBusy', 'savingSettings'];

        $violations = [];
        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('js/studio/components')) as $file) {
            if ($file->getExtension() !== 'vue') continue;
            $name = $file->getFilename();
            $src = (string) file_get_contents($file->getPathname());

            preg_match_all('/<(button|a)\b[^>]*>/s', $src, $tags, PREG_OFFSET_CAPTURE);
            foreach ($tags[0] as [$tag, $offset]) {
                if (! str_contains($tag, 'btn-brand')) continue;
                if (! preg_match('/:disabled="([^"]*)"/', $tag, $dm)) continue;

                // Có phủ định một thứ KHÔNG phải cờ đang-chạy ⇒ đây là điều kiện người dùng cần gỡ.
                preg_match_all('/!\s*([a-zA-Z_][a-zA-Z0-9_.]*)/', $dm[1], $neg);
                $needsReason = false;
                foreach ($neg[1] as $flag) {
                    $bare = str_replace(['store.', '.value'], '', $flag);
                    if (! in_array($bare, $busyFlags, true)) { $needsReason = true; break; }
                }
                if (! $needsReason) continue;

                // Cửa sổ đo tính tới HẾT thẻ nút rồi cộng thêm 400 ký tự: nút có thể nhiều dòng
                // (thuộc tính + span bên trong), nên cửa sổ cố định dễ trượt qua dòng lý do.
                $closeAt = strpos($src, '</button>', $offset);
                $after = substr($src, $offset, ($closeAt === false ? 900 : $closeAt - $offset + 400));
                if (! str_contains($after, '↳')) {
                    $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                    $violations[] = $name.':'.$line.' — '.trim(preg_replace('/\s+/', ' ', $dm[1]));
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            "Nút chính bị khoá mà KHÔNG nói vì sao. Xem docs/DESIGN_SYSTEM.md §4 quy tắc 4 — thêm ngay dưới nút "
            .'một dòng ↳ nói rõ đang thiếu gì, lấy từ MỘT computed blockReason để điều kiện khoá và câu giải thích không lệch nhau.');
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
