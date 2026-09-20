<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * BÁO CÁO CHI PHÍ THEO NHÓM (2026-09-23) — việc còn nợ trong DEPLOY_LOG: "báo cáo chi phí/tiến độ
 * theo nhóm cho chủ doanh nghiệp".
 *
 * Vì sao cần: gói cước đã có GHẾ (users.team_owner_id) — cả nhóm dùng chung credit — nhưng chủ nhóm
 * không có chỗ nào nhìn ra "nhóm tôi đã tiêu bao nhiêu, ai tiêu nhiều nhất". Không có con số thì
 * không giao việc và không kiểm soát được chi phí.
 *
 * BA QUYẾT ĐỊNH ĐÁNG GHI:
 *
 * 1. Số liệu lấy từ CHÍNH bảng generations (cột credits_cost) — cùng nguồn với việc trừ credit khi
 *    tạo ảnh, nên báo cáo không thể lệch với thực tế. KHÔNG đếm lại ở client, KHÔNG đọc từ sổ cái
 *    (sổ cái ghi cả cấp/thu hồi, không phải "chi phí theo nhóm").
 *
 * 2. CHỈ chủ nhóm (team_owner_id IS NULL) là một dòng báo cáo; thành viên được cộng vào nhóm của họ.
 *    Một người vừa là chủ nhóm vừa có ảnh riêng thì ảnh của chính họ cũng nằm trong nhóm đó — đúng
 *    nghĩa "chi phí của nhóm".
 *
 * 3. Cấp OWNER (auth + admin): đây là số liệu của TOÀN hệ thống (mọi nhóm). Chủ nhóm xem số của
 *    nhóm mình ở trang Bộ sưu tập/credit; trang này để người vận hành đối chiếu.
 */
class ReportController extends Controller
{
    /** Khoảng thời gian cho phép — whitelist cứng, không nhận số ngày tuỳ ý từ URL. */
    public const PERIODS = [7, 30, 90];

    private const MAX_USERS = 5000;

    public function teamCosts(Request $request): View
    {
        $days = (int) $request->query('days', 30);
        if (! in_array($days, self::PERIODS, true)) {
            $days = 30;
        }
        $since = now()->subDays($days);

        // Nhóm = chủ nhóm (không có team_owner_id) + các thành viên trỏ về chủ đó.
        $owners = User::query()
            ->whereNull('team_owner_id')
            ->orderBy('name')
            ->limit(self::MAX_USERS)
            ->get(['id', 'name', 'email', 'plan_id']);

        $memberIds = [];
        foreach (User::query()->whereNotNull('team_owner_id')->get(['id', 'team_owner_id']) as $m) {
            $memberIds[(int) $m->team_owner_id][] = (int) $m->id;
        }

        // Một lượt gom theo user_id rồi cộng vào nhóm — không N+1 theo từng nhóm.
        $usage = Generation::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('user_id, COUNT(*) as images, COALESCE(SUM(credits_cost), 0) as credits, MAX(created_at) as last_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $rows = [];
        foreach ($owners as $owner) {
            $team = array_merge([(int) $owner->id], $memberIds[(int) $owner->id] ?? []);
            $images = 0;
            $credits = 0;
            $lastAt = null;
            foreach ($team as $uid) {
                $hit = $usage->get($uid);
                if (! $hit) {
                    continue;
                }
                $images += (int) $hit->images;
                $credits += (int) $hit->credits;
                if ($hit->last_at && (! $lastAt || $hit->last_at > $lastAt)) {
                    $lastAt = $hit->last_at;
                }
            }

            $rows[] = [
                'owner' => $owner,
                'seats' => count($team),
                'members' => count($team) - 1,
                'images' => $images,
                'credits' => $credits,
                'last_at' => $lastAt,
            ];
        }

        // Nhóm tiêu nhiều nhất lên đầu — đó là câu hỏi mà chủ doanh nghiệp thực sự hỏi.
        usort($rows, fn (array $a, array $b) => $b['credits'] <=> $a['credits']);

        return view('studio.team-costs', [
            'rows' => $rows,
            'days' => $days,
            'periods' => self::PERIODS,
            'totalCredits' => array_sum(array_column($rows, 'credits')),
            'totalImages' => array_sum(array_column($rows, 'images')),
            'since' => $since,
        ]);
    }
}
