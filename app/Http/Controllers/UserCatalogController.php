<?php

namespace App\Http\Controllers;

use App\Models\UserCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * CATALOG TÙY CHỈNH CẤP TÀI KHOẢN (Yêu cầu 2026-09-20).
 *
 * Hai trang /presets và /stylist-data trước đây lưu bản tùy chỉnh của người dùng trong
 * localStorage (resources/js/studio/composables/useLocalCatalog.js). Nay lưu trên SERVER theo
 * tài khoản: đổi máy không mất bản tùy chỉnh, hai thiết bị của cùng một người thấy như nhau, và
 * dữ liệu nằm trong tầm sao lưu của hệ thống.
 *
 * HỢP ĐỒNG (frontend đã trông đợi đúng như vầy):
 *   GET  /api/user-catalogs/{name}  -> 200 {name, data, updated_at}
 *   PUT  /api/user-catalogs/{name}  -> 200 {name, data, updated_at}  (body: {data: {...}})
 *
 * Bất biến bảo mật — MỌI truy vấn đều scope theo auth()->id():
 *   · người dùng A KHÔNG đọc và KHÔNG ghi được bản của B. Không có tham số userId nào trên
 *     đường vào, và không có nhánh "owner thấy tất cả" như các bảng dùng chung khác: catalog này
 *     là dữ liệu riêng tư thuần tuý, kể cả admin cũng chỉ thấy bản của chính mình — admin muốn
 *     sửa bản DÙNG CHUNG thì đi đường khác (nhóm ADMIN của /api/presets).
 *
 * Whitelist tên catalog là CỨNG (UserCatalog::NAMES): {name} là một đoạn URL do client chọn, nhận
 * tên tuỳ ý sẽ biến bảng này thành kho key-value không giới hạn của người dùng.
 */
class UserCatalogController extends Controller
{
    /**
     * Trần kích thước JSON đã mã hoá: 256 KB.
     *
     * Đây là DỮ LIỆU GIAO DIỆN (nhãn, prompt, thứ tự hiển thị) nên 256 KB là rộng rãi; nhưng không
     * có trần thì một tài khoản có thể PUT vài chục MB mỗi lần và làm phình DB + tốn băng thông
     * GET ở mọi lần mở trang. Đo trên chuỗi ĐÃ MÃ HOÁ (không phải số phần tử) vì đó mới là thứ
     * thực sự được ghi xuống và đọc lên.
     */
    private const MAX_BYTES = 262144;

    /** Trần số phần tử mỗi nhóm — đủ cho catalog thật, chặn được payload sinh tự động. */
    private const MAX_ITEMS = 500;

    /**
     * Đọc bản tùy chỉnh của CHÍNH người đang đăng nhập.
     *
     * Chưa từng lưu thì trả 200 với shape rỗng (KHÔNG phải 404): với frontend, "chưa tùy chỉnh"
     * và "tùy chỉnh rỗng" là cùng một trạng thái, và bắt nó phân biệt hai thứ đó chỉ đẻ thêm một
     * nhánh lỗi ở mỗi lần tải trang.
     */
    public function show(string $name): JsonResponse
    {
        $this->assertValidName($name);

        $row = $this->rowFor(auth()->id(), $name);

        return response()->json([
            'name' => $name,
            'data' => UserCatalog::normalizeData($row?->data),
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Lưu (tạo hoặc ghi đè) bản tùy chỉnh của CHÍNH người đang đăng nhập.
     *
     * Kiểm tra ở SERVER chứ không tin client: client cũ, client hỏng, hay người gọi API bằng tay
     * đều có thể gửi lên cấu trúc mà frontend không đọc nổi. Dữ liệu sai lọt vào DB thì lần GET
     * sau trả về cho CHÍNH người đó một bản làm vỡ trang, và họ không có cách nào tự sửa.
     */
    public function update(Request $request, string $name): JsonResponse
    {
        $this->assertValidName($name);

        $data = $request->input('data');

        // `data` bắt buộc phải là object/mảng. Gửi thiếu hẳn khoá này (hoặc gửi null) là lỗi của
        // người gọi, không phải "ý muốn xoá sạch": xoá sạch phải nói rõ bằng
        // {custom: [], edits: {}, hidden: []}. Đoán ý ở đây là đường mất dữ liệu.
        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'data' => ['Trường data phải là một object chứa custom / edits / hidden.'],
            ]);
        }

        $this->assertRawTypes($data);

        // Chuẩn hoá TRƯỚC khi đo kích thước: khoá lạ trong payload không được tính vào hạn mức
        // (nếu không, một khoá thừa khổng lồ bị bỏ đi vẫn làm request bị từ chối vô cớ).
        $normalized = UserCatalog::normalizeData($data);

        $this->assertShape($normalized);

        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'data' => ['Dữ liệu tùy chỉnh quá lớn (tối đa 256 KB).'],
            ]);
        }

        // updateOrCreate trên ĐÚNG cặp (user_id, name) — khoá này có UNIQUE ở DB nên ghi lại
        // nhiều lần vẫn chỉ có MỘT hàng. Đường ghi duy nhất của controller đi qua đây; không có
        // nhánh nào nhận user_id từ request.
        $row = UserCatalog::updateOrCreate(
            ['user_id' => auth()->id(), 'name' => $name],
            ['data' => $normalized],
        );

        return response()->json([
            'name' => $name,
            'data' => $row->data,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * {name} không thuộc whitelist ⇒ 404.
     *
     * Cố ý 404 chứ không 422: tên catalog là một PHẦN CỦA ĐƯỜNG DẪN, không phải trường trong
     * body. Với một tài nguyên không tồn tại, 404 là câu trả lời đúng và cũng không tiết lộ danh
     * sách tên hợp lệ cho người đang dò.
     */
    private function assertValidName(string $name): void
    {
        abort_unless(UserCatalog::isValidName($name), 404);
    }

    /** Hàng của MỘT người cho MỘT catalog — luôn kèm user_id, không có biến thể thiếu nó. */
    private function rowFor(int $userId, string $name): ?UserCatalog
    {
        return UserCatalog::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }

    /**
     * Kiểm cấu trúc sau chuẩn hoá: custom là mảng object có id chuỗi khác rỗng, edits là map
     * id => object, hidden là mảng chuỗi id; mỗi nhóm tối đa MAX_ITEMS phần tử.
     *
     * Vì sao id bắt buộc và phải là chuỗi: giao diện ghép baseline với bản của user THEO ID
     * (useLocalCatalog.merge). Một mục custom không có id thì không sửa được, không xoá được và
     * xuất hiện trùng lặp ở mọi lần ghép — dữ liệu đã ghi vào là ngõ cụt.
     */
    private function assertShape(array $data): void
    {
        $errors = [];

        if (count($data['custom']) > self::MAX_ITEMS) {
            $errors['data.custom'] = ['Tối đa '.self::MAX_ITEMS.' mục tùy chỉnh.'];
        } else {
            foreach ($data['custom'] as $i => $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if (! is_string($id) || trim($id) === '') {
                    $errors['data.custom.'.$i.'.id'] = ['Mỗi mục tùy chỉnh phải có id dạng chuỗi khác rỗng.'];
                    // Chỉ báo lỗi ĐẦU TIÊN: một payload sai kiểu thường sai ở mọi phần tử, liệt kê
                    // 500 dòng lỗi không giúp người gọi sửa nhanh hơn một dòng.
                    break;
                }
            }
        }

        if (count($data['edits']) > self::MAX_ITEMS) {
            $errors['data.edits'] = ['Tối đa '.self::MAX_ITEMS.' mục ghi đè.'];
        }

        if (count($data['hidden']) > self::MAX_ITEMS) {
            $errors['data.hidden'] = ['Tối đa '.self::MAX_ITEMS.' mục bị ẩn.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Kiểm KIỂU THÔ của ba khoá TRƯỚC khi chuẩn hoá.
     *
     * Vì sao phải tách khỏi assertShape(): normalizeData() cố tình chịu lỗi (ép kiểu, bỏ phần tử
     * lạ) để dữ liệu CŨ trong DB luôn đọc ra được. Nhưng khi GHI thì phải nghiêm: nếu ở đây cũng
     * chịu lỗi, client gửi custom = "abc" sẽ nhận 200 kèm custom = [] — người dùng tưởng đã lưu
     * thành công trong khi toàn bộ danh sách vừa bị nuốt im lặng. Khoá VẮNG thì vẫn hợp lệ (mặc
     * định rỗng), chỉ khoá CÓ MẶT mà sai kiểu mới bị từ chối.
     */
    private function assertRawTypes(array $data): void
    {
        $errors = [];

        foreach (["custom", "edits", "hidden"] as $key) {
            if (array_key_exists($key, $data) && ! is_array($data[$key])) {
                $errors["data.$key"] = ["Trường $key phải là " . ($key === "edits" ? "một object" : "một mảng") . "."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
