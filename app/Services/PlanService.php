<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;

/**
 * Gán gói cước cho người dùng — đường DUY NHẤT thay đổi plan_id / plan_expires_at.
 *
 * Quy ước:
 *   · Gói MIỄN PHÍ (price_vnd = 0): plan_expires_at = null (vĩnh viễn tới khi đổi gói).
 *   · Gói TRẢ PHÍ: plan_expires_at = now() + N tháng. Khi gia hạn cùng gói còn hạn thì cộng dồn.
 *   · bonus_credits: tặng MỘT LẦN khi LẦN ĐẦU chuyển sang gói (không tặng lại khi gia hạn).
 */
class PlanService
{
    public function __construct(private CreditService $credit) {}

    /**
     * @param  int  $months  số chu kỳ gia hạn (mặc định 1). Gói miễn phí bỏ qua.
     */
    public function assign(User $user, Plan $plan, int $months = 1, ?string $note = null): void
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
            $fill['plan_expires_at'] = $base->copy()->addMonths(max(1, $months));
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
    }
}
