<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\User;

/**
 * Sổ cái credit — ĐIỂM DUY NHẤT được phép biến động credits_balance.
 *
 * Lý do tồn tại (đúng triết lý "một đường, có kiểm soát" của repo):
 *   · credits_balance là "số dư", credit_transactions là "sổ kế toán". Mọi cộng/trừ đều phải đi qua
 *     mutate() để ghi đúng một dòng sổ cái, không thể quên.
 *   · increment()/decrement() literal chỉ được phép nằm TRONG class này (có test khoá bất biến).
 *     Các call-site (StudioController trừ credit khi tạo ảnh, helpers hoàn credit khi lỗi/huỷ,
 *     AdminController cấp/trừ credit) đều uỷ quyền về đây.
 *
 * Ghi chú về tính nhất quán:
 *   · mutate() dùng increment/decrement nguyên tử của DB (an toàn khi chạy song song).
 *   · balance_after đọc lại giá trị sau khi mutate — trong transaction sẽ thấy đúng giá trị của
 *     chính transaction đó; ngoài transaction là giá trị đã commit.
 */
class CreditService
{
    /**
     * Biến động số dư (có dấu) mà KHÔNG ghi sổ cái riêng — dùng khi muốn ghi sổ cái sau
     * (vd: đường tiêu credit cần reference tới generation vừa mới tạo).
     */
    public function mutate(User $user, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            $user->increment('credits_balance', $delta);
        } else {
            $user->decrement('credits_balance', abs($delta));
        }

        $user->refresh();
    }

    /**
     * Ghi một dòng sổ cái. Không tự biến động số dư — balance_after lấy từ trạng thái hiện tại
     * của model (phải đã được mutate hoặc được truyền tường minh).
     */
    public function record(User $user, int $amount, string $type, array $meta = []): CreditTransaction
    {
        return CreditTransaction::create([
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $meta['balance_after'] ?? (int) $user->credits_balance,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'admin_id' => $meta['admin_id'] ?? null,
            'note' => $meta['note'] ?? null,
        ]);
    }

    /**
     * Biến động số dư + ghi sổ cái trong một bước. Dùng cho các đường đã biết sẵn reference
     * (hoàn credit, cấp/trừ bởi admin, tặng credit đăng ký…).
     */
    public function apply(User $user, int $delta, string $type, array $meta = []): CreditTransaction
    {
        $this->mutate($user, $delta);

        return $this->record($user, $delta, $type, $meta);
    }
}
