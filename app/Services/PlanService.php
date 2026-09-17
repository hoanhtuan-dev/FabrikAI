<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gán gói cước cho người dùng — đường DUY NHẤT thay đổi plan_id / plan_expires_at.
 *
 * Quy ước:
 *   · Gói MIỄN PHÍ (price_vnd = 0): plan_expires_at = null (vĩnh viễn tới khi đổi gói).
 *   · Gói TRẢ PHÍ: plan_expires_at = now() + N tháng. Khi gia hạn cùng gói còn hạn thì cộng dồn.
 *   · bonus_credits: tặng MỘT LẦN khi LẦN ĐẦU chuyển sang gói (không tặng lại khi gia hạn).
 *
 * Cấp credit theo chu kỳ (credits_per_month) — bù lỗ hổng phát hiện 2026-09-19: xem
 * syncCycleCredits(). Việc cấp đi qua HAI đường (cron + lazy khi vào app) nhưng idempotent nhờ
 * cột mốc users.plan_credits_granted_at.
 */
class PlanService
{
    public function __construct(private CreditService $credit) {}

    /**
     * @param  int  $units  SỐ ĐƠN VỊ MUA: gói tháng ⇒ số tháng, gói theo vụ ⇒ số vụ (Q3, 2026-09-19).
     *                      Hạn gói = units × plans.unit_months. Gói miễn phí bỏ qua.
     */
    public function assign(User $user, Plan $plan, int $units = 1, ?string $note = null): void
    {
        $isRenewal = $user->plan_id === $plan->id && ! $plan->isFree();
        $isFirstTime = $user->plan_id !== $plan->id;

        $fill = ['plan_id' => $plan->id];

        if ($plan->isFree()) {
            $fill['plan_expires_at'] = null;
        } else {
            $base = ($isRenewal && $user->plan_expires_at && $user->plan_expires_at->isFuture())
                ? $user->plan_expires_at
                : now();
            // Gói theo VỤ: một đơn vị = 3 tháng ⇒ mua 1 vụ là hạn +3 tháng, không phải +1 tháng.
            $fill['plan_expires_at'] = $base->copy()->addMonths($plan->monthsFor($units));
        }

        $user->forceFill($fill)->save();

        // Tặng credit một lần khi lần đầu chuyển sang gói có bonus.
        if ($isFirstTime && (int) $plan->bonus_credits > 0) {
            $this->credit->apply($user, (int) $plan->bonus_credits, 'grant', [
                'reference_type' => 'plan',
                'reference_id' => $plan->id,
                'note' => $note ?: 'Tặng credit khi đăng ký gói '.$plan->name,
            ]);
        } elseif ($isRenewal) {
            $this->credit->record($user, 0, 'renew', [
                'reference_type' => 'plan',
                'reference_id' => $plan->id,
                'note' => $note ?: 'Gia hạn gói '.$plan->name,
            ]);
        }

        // Đổi/gia hạn gói xong ⇒ cấp credit của chu kỳ MỚI ngay, không phải chờ cron.
        $this->syncCycleCredits($user->fresh());
    }

    /**
     * Mốc bắt đầu chu kỳ credit đang chạy.
     *
     * · Gói trả phí (có hạn): chu kỳ = 1 tháng tính ngược từ plan_expires_at
     *   ⇒ gia hạn làm mốc này tiến lên, nên chu kỳ mới được cấp credit mới.
     * · Gói miễn phí / gán vĩnh viễn: chu kỳ = tháng dương lịch (cấp đầu mỗi tháng).
     */
    public function cycleStartFor(User $user, ?Plan $plan = null): Carbon
    {
        if ($user->plan_expires_at) {
            // Chu kỳ = độ dài CỦA GÓI: gói tháng trừ 1 tháng, gói theo vụ trừ 3 tháng ⇒ credit của cả
            // vụ được cấp MỘT LẦN (một bể dùng cho cả vụ), không nhỏ giọt theo tháng.
            $plan = $plan ?? $user->activePlan();

            return $user->plan_expires_at->copy()->subMonths($plan?->cycleMonths() ?? 1);
        }

        return now()->startOfMonth();
    }

    /**
     * Cấp credit của chu kỳ hiện tại nếu chưa cấp. Trả về dòng sổ cái vừa ghi, hoặc null nếu
     * không cần cấp (chưa có gói · gói hết hạn · gói không có credit/tháng · đã cấp chu kỳ này).
     *
     * Vì sao tồn tại: plans.credits_per_month trước đây CHỈ được hiển thị — không có chỗ nào cấp
     * ⇒ khách trả 499.000 ₫ cho "350 credit/tháng" mà không nhận được credit nào sau tháng đầu.
     *
     * An toàn khi chạy song song: quyền cấp được GIÀNH bằng một UPDATE có điều kiện (CAS), nằm
     * cùng transaction với việc cộng credit + ghi sổ cái ⇒ cron và request chạy chồng không cấp đúp.
     */
    public function syncCycleCredits(User $user): ?CreditTransaction
    {
        // [Q4 — 2026-09-19] Thành viên nhóm dùng gói của chủ nhóm ⇒ credit chu kỳ phải cấp cho CHỦ NHÓM,
        // không cấp vào tài khoản thành viên. (Lỗi bắt được bằng test: gọi với thành viên thì tài khoản
        // phụ nhận thêm 350 credit của gói nhóm — tiền bị nhân bản.)
        $user = $user->billingUser();

        $plan = $user->activePlan();
        if (! $plan || (int) $plan->credits_per_month <= 0) {
            return null;
        }

        $cycleStart = $this->cycleStartFor($user, $plan);
        $grantedAt = $user->plan_credits_granted_at;

        if ($grantedAt && $grantedAt->greaterThanOrEqualTo($cycleStart)) {
            return null;
        }

        return DB::transaction(function () use ($user, $plan, $cycleStart) {
            $claimed = User::whereKey($user->id)
                ->where(function ($q) use ($cycleStart) {
                    $q->whereNull('plan_credits_granted_at')
                        ->orWhere('plan_credits_granted_at', '<', $cycleStart);
                })
                ->update(['plan_credits_granted_at' => now()]);

            if ($claimed === 0) {
                return null;
            }

            $user->refresh();

            return $this->credit->apply($user, (int) $plan->credits_per_month, CreditTransaction::TYPE_PLAN_GRANT, [
                'reference_type' => 'plan',
                'reference_id' => $plan->id,
                'note' => 'Cấp '.number_format((int) $plan->credits_per_month, 0, ',', '.').' credit theo gói '
                    .$plan->name.' (kỳ từ '.$cycleStart->format('d/m/Y').')',
            ]);
        });
    }
}
