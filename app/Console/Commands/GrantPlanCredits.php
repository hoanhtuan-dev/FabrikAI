<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlanService;
use Illuminate\Console\Command;

/**
 * Cấp credit theo CHU KỲ GÓI (plans.credits_per_month) — đường dành cho cron hPanel.
 *
 * Vì sao cần: trước đây credits_per_month chỉ được HIỂN THỊ, không chỗ nào cấp ⇒ khách trả tiền
 * gói "350 credit/tháng" mà không nhận được credit nào sau tháng đầu. Lệnh này cấp cho mọi người
 * đã tới kỳ, và AN TOÀN khi chạy trùng với đường lazy trong app (PlanService dùng CAS + transaction).
 *
 * Chạy hằng ngày trên host (hPanel → Cron):
 *     php /home/u310846799/domains/fabrikai.shop/artisan studio:grant-plan-credits
 */
class GrantPlanCredits extends Command
{
    protected $signature = 'studio:grant-plan-credits
        {--dry-run : Chỉ liệt kê người sắp được cấp, không ghi gì}
        {--limit=500 : Số người dùng xét mỗi lượt}';

    protected $description = 'Cấp credit theo chu kỳ gói (credits_per_month) — idempotent, chạy được cùng cron khác.';

    public function handle(PlanService $plans): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $users = User::query()
            ->whereNotNull('plan_id')
            ->with('plan')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $granted = 0;
        $skipped = 0;
        $credits = 0;

        foreach ($users as $user) {
            $plan = $user->activePlan();
            if (! $plan || (int) $plan->credits_per_month <= 0) {
                $skipped++;

                continue;
            }

            if ($dry) {
                $cycleStart = $plans->cycleStartFor($user);
                $due = ! $user->plan_credits_granted_at || $user->plan_credits_granted_at->lt($cycleStart);
                if ($due) {
                    $granted++;
                    $credits += (int) $plan->credits_per_month;
                    $this->line(sprintf(
                        '[dry-run] SẼ CẤP  #%d %s — %s +%s credit (kỳ từ %s)',
                        $user->id, $user->email, $plan->name,
                        number_format((int) $plan->credits_per_month, 0, ',', '.'), $cycleStart->format('d/m/Y')
                    ));
                } else {
                    $skipped++;
                }

                continue;
            }

            $tx = $plans->syncCycleCredits($user);
            if ($tx) {
                $granted++;
                $credits += (int) $tx->amount;
                $this->info(sprintf('+%s → #%d %s (%s)', number_format((int) $tx->amount, 0, ',', '.'), $user->id, $user->email, $plan->name));
            } else {
                $skipped++;
            }
        }

        $this->info(sprintf(
            '%sĐã cấp: %d người · bỏ qua: %d · tổng credit: %s',
            $dry ? '[dry-run] ' : '', $granted, $skipped, number_format($credits, 0, ',', '.')
        ));

        return self::SUCCESS;
    }
}
