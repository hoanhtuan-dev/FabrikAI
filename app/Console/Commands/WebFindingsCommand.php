<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WebFindingService;
use Illuminate\Console\Command;

/**
 * SỔ NGUỒN ĐÃ TÌM — chạy từ SSH để KIỂM VÒNG KHÉP KÍN trên production (2026-09-26).
 *
 * Vì sao cần lệnh riêng: vòng khép kín có năm mắt xích (tìm · lưu · dùng lại · quay về · người dùng lưu),
 * và trên production KHÔNG có cách nào nhìn thấy nó có chạy hay không ngoài việc mở giao diện rồi đoán.
 * Lệnh này đọc thẳng SỔ và nói ra số đo: tài khoản nào đã tra được gì, nguồn nào người dùng đã giữ, còn
 * bao nhiêu nguồn dùng lại được. Không gọi model, không đi mạng — chỉ đọc bảng.
 *
 * Dùng:
 *   php artisan studio:web-findings                 # tổng quan toàn hệ thống
 *   php artisan studio:web-findings --user=5        # chi tiết sổ của MỘT tài khoản (id)
 *   php artisan studio:web-findings --saved         # chỉ liệt kê nguồn người dùng ĐÃ LƯU
 */
class WebFindingsCommand extends Command
{
    protected $signature = 'studio:web-findings
        {--user= : Id tài khoản cần xem chi tiết}
        {--saved : Chỉ liệt kê nguồn người dùng đã lưu}
        {--limit=20 : Trần số nguồn liệt kê}';

    protected $description = 'Xem SỔ NGUỒN mà công cụ tìm kiếm mang về (vòng khép kín của Agent Studio).';

    public function handle(WebFindingService $findings): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $userId = $this->option('user');

        if ($userId !== null && $userId !== '') {
            // Chi tiết MỘT tài khoản: đây là đường dùng thật khi khách hỏi "AI đã tra được gì cho tôi".
            $user = User::query()->find((int) $userId);
            if ($user === null) {
                $this->error('Không có tài khoản id='.$userId.'.');

                return self::FAILURE;
            }

            $stats = $findings->stats($user);
            $this->line('── SỔ NGUỒN của '.$user->email.' (id '.$user->id.') ──');
            $this->line('  tổng '.$stats['total'].' nguồn · đã lưu '.$stats['saved'].' · còn dùng lại được '.$stats['fresh']
                .' (hạn '.WebFindingService::KEEP_DAYS.' ngày)');

            $rows = $findings->recent($user, 'all', $limit, (bool) $this->option('saved'));
            if ($rows === []) {
                $this->warn('  Sổ đang rỗng (hoặc không có nguồn nào đã lưu): chưa lượt chạy nào gọi công cụ tìm kiếm.');

                return self::SUCCESS;
            }

            $this->line('── NGUỒN ──');
            foreach ($rows as $row) {
                $this->line(sprintf(
                    '  %s %s · gặp %d lần · tra vì "%s"',
                    $row['saved'] ? '[ĐÃ LƯU]' : '[      ]',
                    mb_substr((string) $row['title'] !== '' ? (string) $row['title'] : (string) $row['url'], 0, 80),
                    (int) ($row['hits'] ?? 0),
                    mb_substr((string) ($row['found_query'] ?? ''), 0, 40),
                ));
                $this->line('           '.$row['url']);
            }

            return self::SUCCESS;
        }

        // Tổng quan: đếm theo tài khoản — KHÔNG in từ khoá của mọi người ra màn hình dùng chung.
        $this->line('── TỔNG QUAN SỔ NGUỒN ──');
        $users = User::query()->orderBy('id')->get(['id', 'email']);
        $total = 0;
        $savedTotal = 0;
        foreach ($users as $user) {
            $stats = $findings->stats($user);
            if ($stats['total'] === 0) {
                continue;
            }
            $total += $stats['total'];
            $savedTotal += $stats['saved'];
            $this->line(sprintf('  #%d %-32s tổng %d · đã lưu %d · còn dùng lại %d',
                $user->id, $user->email, $stats['total'], $stats['saved'], $stats['fresh']));
        }

        if ($total === 0) {
            $this->warn('  Chưa tài khoản nào có nguồn trong sổ — công cụ tìm kiếm chưa chạy lượt nào (kiểm tra vai "Tìm kiếm nguồn ngoài" trong Cài đặt).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Tổng '.$total.' nguồn trong sổ · '.$savedTotal.' nguồn người dùng đã lưu · hạn dùng lại '.
            WebFindingService::KEEP_DAYS.' ngày · trần mỗi lần dùng lại '.WebFindingService::RECALL_LIMIT.' nguồn.');

        return self::SUCCESS;
    }
}
