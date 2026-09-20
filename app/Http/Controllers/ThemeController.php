<?php

namespace App\Http\Controllers;

use App\Support\ThemePalette;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * TÙY CHỌN GIAO DIỆN (theme) CỦA CHÍNH NGƯỜI ĐANG ĐĂNG NHẬP (2026-09-23).
 *
 * Hợp đồng:
 *   PUT /api/theme   body {"theme": "light" | "dark" | "system"}  ->  200 {"theme": "..."}
 *
 * Ba quyết định đáng ghi lại:
 *
 *  1. Whitelist CỨNG bằng Rule::in — giá trị lạ (kể cả chuỗi dài, HTML, tên class) bị 422.
 *     Cột users.theme được render thẳng vào thuộc tính data-theme của thẻ <html> ở mọi
 *     blade, nên một giá trị tự do ở đây là một lỗ XSS tiềm năng (thuộc tính do người dùng
 *     kiểm soát). Giá trị đi vào DB LUÔN là một trong ba chuỗi kể trên.
 *
 *  2. Ghi bằng phép gán thuộc tính tường minh (không mass-assign, không nới $fillable của
 *     User): đúng quy ước của repo — quyền/tiền không bao giờ đi qua fillable, và theme thì
 *     dùng chung một đường ghi rõ ràng.
 *
 *  3. Không có tham số userId nào: mỗi người chỉ đổi được theme của CHÍNH MÌNH (admin cũng
 *     vậy). Đây là tùy chọn hiển thị cá nhân, không phải cấu hình toàn cục; cấu hình toàn
 *     cục nằm ở khu Quản trị.
 */
class ThemeController extends Controller
{
    /** Ba lựa chọn của người dùng. 'system' = theo hệ điều hành. */
    public const THEMES = ['light', 'dark', 'system'];

    public function update(Request $request): JsonResponse
    {
        $data = Validator::make($request->only('theme'), [
            'theme' => ['required', 'string', 'in:'.implode(',', self::THEMES)],
        ])->validate();

        $user = $request->user();
        $user->theme = $data['theme'];
        $user->save();

        return response()->json(['theme' => $user->theme]);
    }

    /**
     * TRANG XEM TOKEN cho người thiết kế (2026-09-23) — cấp OWNER.
     *
     * Vì sao cần: nợ đã ghi trong DEPLOY_LOG — "số liệu tương phản chỉ nằm trong test". Người thiết kế
     * muốn biết bậc chữ nào đang đạt AA phải mở mã test ra đọc. Nay có một trang đọc thẳng token từ
     * resources/css/app.css qua App\Support\ThemePalette — CÙNG lớp mà ThemeSystemTest dùng, nên
     * trang và test không thể nói hai con số khác nhau.
     *
     * Vì sao server-render: đây là trang ĐỌC số liệu của chính mã nguồn, không có tương tác nào; thêm
     * một app JS nữa chỉ để vẽ bảng là thêm một chỗ có thể lệch.
     */
    public function tokensPage(): View
    {
        return view('studio.design-tokens', [
            'themes' => [
                'Tối' => ThemePalette::matrix('dark'),
                'Sáng' => ThemePalette::matrix('light'),
            ],
            'accents' => [
                'Tối' => ThemePalette::accents('dark'),
                'Sáng' => ThemePalette::accents('light'),
            ],
            'surfaces' => ThemePalette::SURFACES,
            'fixed' => ThemePalette::FIXED,
            'fixedValues' => ThemePalette::resolved('dark'),
            'aa' => ThemePalette::AA,
        ]);
    }
}
