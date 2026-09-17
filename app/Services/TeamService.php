<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * NHÓM LÀM VIỆC THEO SỐ GHẾ CỦA GÓI (Q4 — 2026-09-19).
 *
 * Mô hình: chủ nhóm (người trả tiền) mời thành viên vào nhóm; thành viên dùng CHUNG gói, credit và
 * bộ sưu tập của chủ nhóm. Số thành viên bị chặn bởi `plans.seats`.
 *
 * Nguyên tắc an toàn (đều có test):
 *   · Mời thành viên TẠO TÀI KHOẢN MỚI (không âm thầm gắn một tài khoản đang tồn tại vào nhóm — làm vậy
 *     là chiếm credit của người khác). Mật khẩu tạm sinh ngẫu nhiên, trả về MỘT LẦN cho chủ nhóm.
 *   · Không ai tự thêm mình vào nhóm người khác; chỉ chủ nhóm hoặc Super Admin.
 *   · Thành viên không mua/đổi gói (việc của chủ nhóm) và không xoá bộ sưu tập.
 *   · Xoá ghế ⇒ tài khoản trở lại độc lập (KHÔNG xoá tài khoản, không mất ảnh đã tạo).
 */
class TeamService
{
    /** Chủ nhóm của một người dùng (chính họ nếu không thuộc nhóm nào). */
    public function ownerOf(?User $user): ?User
    {
        if (! $user) {
            return null;
        }

        if (! $user->team_owner_id) {
            return $user;
        }

        return $user->teamOwner ?: $user;
    }

    /** Người TRẢ TIỀN cho thao tác của `$user`: thành viên ⇒ chủ nhóm; ngược lại là chính họ. */
    public function billingUser(?User $user): ?User
    {
        return $this->ownerOf($user);
    }

    /** Danh sách thành viên (kèm chủ nhóm ở đầu). */
    public function members(User $owner): array
    {
        return User::query()
            ->where(fn ($q) => $q->whereKey($owner->id)->orWhere('team_owner_id', $owner->id))
            ->orderByRaw('case when id = ? then 0 else 1 end', [$owner->id])
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'phone', 'is_active', 'team_owner_id', 'created_at'])
            ->all();
    }

    /**
     * Số ghế đã dùng = chủ nhóm + thành viên.
     *
     * KHÔNG cache trong service: Laravel giữ nguyên container giữa các request trong test ⇒ cache sẽ
     * trả số ghế cũ sau khi vừa mời người (test bắt được ngay: mời người thứ ba khi đã hết ghế vẫn 201).
     * Chống đếm trùng bằng cách tính MỘT lần ở studio_team_seats() thay vì gọi hai hàm riêng.
     */
    public function usedSeats(User $owner): int
    {
        return 1 + User::where('team_owner_id', $owner->id)->count();
    }

    /** Tổng số ghế của gói đang có hiệu lực (gói chưa gán/hết hạn ⇒ 1 ghế). */
    public function seatLimit(User $owner): int
    {
        return max(1, (int) ($owner->activePlan()?->seats ?? 1));
    }

    public function remainingSeats(User $owner): int
    {
        return max(0, $this->seatLimit($owner) - $this->usedSeats($owner));
    }

    /**
     * Mời một thành viên mới vào nhóm.
     *
     * Trả về [`user`, `temp_password`] khi thành công; ném \RuntimeException kèm câu tiếng Việt
     * nói rõ lý do khi không mời được (hết ghế · email đã dùng · email không hợp lệ) — controller trả 422.
     */
    public function invite(User $owner, string $email, ?string $name = null, ?string $phone = null): array
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Email chưa hợp lệ — nhập email để thành viên đăng nhập.');
        }

        if (User::where('email', $email)->exists()) {
            throw new \RuntimeException('Email '.$email.' đã có tài khoản FabrikAI. Mỗi thành viên cần một email riêng.');
        }

        if ($this->remainingSeats($owner) < 1) {
            throw new \RuntimeException('Gói '.($owner->activePlan()?->name ?? 'hiện tại').' chỉ có '
                .$this->seatLimit($owner).' ghế và đã dùng hết. Nâng cấp gói để thêm người.');
        }

        $temp = Str::password(10, symbols: false);

        $member = DB::transaction(function () use ($owner, $email, $name, $phone, $temp) {
            $member = User::create([
                'name' => $name ?: Str::before($email, '@'),
                'email' => $email,
                'phone' => $phone,
                'password' => Hash::make($temp),
            ]);
            // team_owner_id và role là field đặc quyền ⇒ ghi tường minh, không mass-assign.
            $member->forceFill([
                'team_owner_id' => $owner->id,
                'role' => User::ROLE_CUSTOMER,
                'is_active' => true,
            ])->save();

            return $member;
        });

        return [$member, $temp];
    }

    /** Xoá một thành viên khỏi nhóm (giải phóng ghế). Tài khoản và ảnh đã tạo vẫn còn. */
    public function remove(User $owner, User $member): bool
    {
        if ((int) $member->team_owner_id !== (int) $owner->id) {
            return false;
        }

        $member->forceFill(['team_owner_id' => null])->save();

        return true;
    }

    /** Người này có phải chủ nhóm (được quản lý ghế · mua gói · xoá bộ sưu tập) không? */
    public function isOwner(User $user): bool
    {
        return $user->team_owner_id === null;
    }
}
