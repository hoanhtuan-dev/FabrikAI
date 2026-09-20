<?php

namespace App\Http\Controllers;

use App\Models\Theme;
use App\Support\ThemeLibrary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * THƯ VIỆN THEME — nơi Quản trị viên dán liên kết daisyUI Theme Generator vào (2026-09-25).
 *
 * Ba quyết định đáng ghi lại:
 *
 *  1. BIỂU MẪU THẬT, KHÔNG PHẢI FETCH TỪ VUE. Đây là thao tác thay đổi bảng màu của TOÀN BỘ sản phẩm,
 *     làm vài lần một năm. Một form POST + redirect kèm thông báo là đủ, và nó chạy được kể cả khi
 *     bundle JS hỏng — trong khi một nút "gọi API rồi tự vẽ lại" thì không.
 *
 *  2. LỖI ĐỌC LIÊN KẾT TRẢ VỀ 422 KÈM CÂU GIẢI THÍCH, không phải một trang lỗi 500. Người dùng dán
 *     thiếu một đoạn của liên kết là chuyện thường; họ cần biết "vướng ở đâu" (RuntimeException của
 *     DaisyThemeLink/DaisyTheme đã viết đúng câu đó) chứ không cần một mã lỗi.
 *
 *  3. MỌI ĐƯỜNG GHI ĐỀU Ở ĐÂY, không có đường thứ hai trong JS. Nhờ vậy "ai đổi bảng màu của sản
 *     phẩm" chỉ có một câu trả lời để kiểm tra, và mọi bất biến (chỉ một theme bật cho mỗi chế độ ·
 *     không lưu trùng · theme đang bật bị xoá thì chế độ đó quay về theme gốc) nằm trong ThemeLibrary.
 */
class ThemeLibraryController extends Controller
{
    /** Dán MỘT HOẶC NHIỀU liên kết (mỗi liên kết một dòng) rồi import. */
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'link' => ['required', 'string', 'max:20000'],
        ], [
            'link.required' => 'Hãy dán liên kết theme từ daisyui.com/theme-generator.',
            'link.max' => 'Nội dung dán quá dài — mỗi liên kết theme chỉ khoảng 2 KB.',
        ]);

        try {
            $result = ThemeLibrary::import($data['link'], $request->user()?->id);
        } catch (RuntimeException $e) {
            // Câu giải thích của lớp đọc liên kết được đưa NGUYÊN VĂN cho người dùng: nó đã nói rõ
            // thiếu gì / sai ở đâu, dịch lại lần nữa chỉ làm mất thông tin.
            throw ValidationException::withMessages(['link' => $e->getMessage()]);
        }

        return back()->with('theme_status', $this->summary($result));
    }

    /** Bật một theme cho chính chế độ của nó (Sáng hoặc Tối). */
    public function activate(Theme $theme): RedirectResponse
    {
        ThemeLibrary::activate($theme);

        return back()->with('theme_status', [
            'kind' => 'ok',
            'title' => 'Đã bật "'.$theme->name.'" cho chế độ '.$this->schemeLabel($theme->scheme).'.',
            'lines' => ['Mọi trang đang mở sẽ thấy bảng màu mới sau khi tải lại.'],
        ]);
    }

    /** Trả một chế độ về bảng màu GỐC của sản phẩm. */
    public function reset(string $scheme): RedirectResponse
    {
        abort_unless(in_array($scheme, ThemeLibrary::SCHEMES, true), 404);

        ThemeLibrary::reset($scheme);

        return back()->with('theme_status', [
            'kind' => 'ok',
            'title' => 'Chế độ '.$this->schemeLabel($scheme).' đã quay về bảng màu gốc của FabrikAI.',
            'lines' => [],
        ]);
    }

    public function destroy(Theme $theme): RedirectResponse
    {
        $name = $theme->name;
        ThemeLibrary::remove($theme);

        return back()->with('theme_status', [
            'kind' => 'ok',
            'title' => 'Đã xoá theme "'.$name.'" khỏi thư viện.',
            'lines' => [],
        ]);
    }

    /**
     * Hoàn tác cả một LÔ import.
     *
     * Vì sao cần: một lần dán sinh ra nhiều theme (bản gốc + bản đối ứng Sáng/Tối). Xoá từng dòng một
     * để quay lại trạng thái trước khi dán là việc mà người dùng không nên phải làm bằng tay.
     */
    public function destroyBatch(string $batch): RedirectResponse
    {
        $themes = Theme::where('batch', $batch)->get();
        foreach ($themes as $theme) {
            ThemeLibrary::remove($theme);
        }

        return back()->with('theme_status', [
            'kind' => 'ok',
            'title' => 'Đã xoá '.$themes->count().' theme của lần import đó.',
            'lines' => [],
        ]);
    }

    /**
     * Câu thông báo sau khi import — nói ĐỦ ba việc: thêm được gì, bỏ qua gì, và có phải chỉnh màu
     * nào cho đạt chuẩn không.
     *
     * Vì sao phải nói cả phần "đã chỉnh màu": theme của người dùng có thể chứa cặp màu chữ/nền không
     * đạt WCAG AA (daisyUI không ràng buộc điều đó — chính theme mặc định của nó cũng không đạt).
     * Im lặng chỉnh rồi báo "đã import thành công" là để người dùng tự phát hiện ra sự khác biệt.
     *
     * @param array{batch:string,saved:list<Theme>,skipped:list<string>,adjusted:array<string,list<string>>,activated:list<string>} $result
     * @return array{kind:string,title:string,lines:list<string>}
     */
    private function summary(array $result): array
    {
        $lines = [];

        foreach ($result['saved'] as $theme) {
            $lines[] = '• '.$theme->name.' — chế độ '.$this->schemeLabel($theme->scheme)
                .($theme->isDerived() ? ' (bản đối ứng tự sinh)' : ' (bản gốc từ liên kết)');
        }

        if ($result['skipped'] !== []) {
            $lines[] = 'Đã có trong thư viện nên bỏ qua: '.implode(', ', $result['skipped']).'.';
        }

        foreach ($result['adjusted'] as $name => $keys) {
            $lines[] = 'Đã chỉnh cho đạt WCAG AA (bản Sáng tự sinh của "'.$name.'"): '
                .implode(', ', array_map(fn (string $k) => str_replace('--color-', '', $k), $keys)).'.';
        }

        if ($result['activated'] !== []) {
            $lines[] = 'Đã tự bật cho chế độ: '.implode(', ', array_map(fn (string $s) => $this->schemeLabel($s), $result['activated']))
                .' (trước đó chế độ này chưa chọn theme nào).';
        }

        return [
            'kind' => $result['saved'] === [] ? 'warn' : 'ok',
            'title' => $result['saved'] === []
                ? 'Không có theme mới nào được thêm.'
                : 'Đã import và lưu '.count($result['saved']).' theme vào thư viện.',
            'lines' => $lines,
        ];
    }

    private function schemeLabel(string $scheme): string
    {
        return $scheme === 'light' ? 'Sáng' : 'Tối';
    }
}
