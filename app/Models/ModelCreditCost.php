<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GIÁ BÁN theo MODEL — model này tốn bao nhiêu credit của khách.
 *
 * VÌ SAO TÁCH KHỎI `plans`: gói trả lời "khách có bao nhiêu credit"; bảng này trả lời "một việc
 * tốn mấy credit". Trộn hai câu hỏi vào một bảng thì mỗi lần đổi giá một model phải sửa MỌI gói —
 * và chắc chắn có gói bị quên.
 *
 * VÌ SAO CÓ `resolution`: fal tính tiền theo megapixel **làm tròn LÊN**, nên cùng một model mà
 * ảnh 1K 1:1 (2 MP) đắt gấp đôi ảnh 1K 4:5 (1 MP), và ảnh 2K 1:1 (5 MP) đắt gấp 5 lần. Không tách
 * theo cỡ thì tỉ lệ 1:1 là tỉ lệ lỗ.
 *
 * Quy ước tra: khớp CHÍNH XÁC (provider, model, resolution) trước; không có thì lấy dòng có
 * resolution = '' (mọi cỡ).
 */
class ModelCreditCost extends Model
{
    protected $table = 'model_credit_cost';

    protected $fillable = ['provider', 'model', 'credits', 'resolution', 'ratio', 'note'];

    protected $casts = ['credits' => 'integer'];

    /**
     * Chuẩn hoá khoá tra về CÙNG một dạng, để "1k" và "1K" không thành hai dòng giá khác nhau.
     * Resolution viết HOA; ratio giữ dạng 'W:H' (đã là dạng chuẩn của app).
     */
    public static function key(?string $provider, ?string $model, ?string $resolution = '', ?string $ratio = ''): array
    {
        return [
            strtolower(trim((string) $provider)),
            trim((string) $model),
            strtoupper(trim((string) $resolution)),
            trim((string) $ratio),
        ];
    }
}
