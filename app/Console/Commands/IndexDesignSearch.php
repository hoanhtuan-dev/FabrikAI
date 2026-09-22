<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DesignSearchService;
use Illuminate\Console\Command;

/**
 * LẬP CHỈ MỤC TÌM KIẾM THIẾT KẾ CŨ (Việc #9 · 2026-09-26).
 *
 * Vì sao cần lệnh chạy nền: mỗi tài liệu là một phần của một lời gọi ra NGOÀI (nhúng văn bản). Làm việc đó
 * trong lúc người dùng bấm tìm là bắt họ chờ vài chục giây cho lượt tìm ĐẦU TIÊN. Lệnh này chạy 04:30 mỗi
 * ngày và chỉ nhúng những gì CHƯA có hoặc ĐÃ ĐỔI CHỮ (so bằng text_hash) — chạy lại không tốn thêm.
 */
class IndexDesignSearch extends Command
{
    protected $signature = 'studio:search:index
        {--user= : Chỉ lập chỉ mục cho MỘT tài khoản (id)}
        {--limit=300 : Trần số tài liệu nhúng trong lượt này}
        {--dry-run : Chỉ đếm xem còn bao nhiêu tài liệu cần nhúng, KHÔNG gọi ra ngoài}';

    protected $description = 'Nhúng văn bản cho chỉ mục tìm kiếm thiết kế cũ (việc #9)';

    public function handle(DesignSearchService $search): int
    {
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        $users = $this->option('user')
            ? User::query()->whereKey((int) $this->option('user'))->get()
            : User::query()->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->warn('Không có tài khoản nào để lập chỉ mục.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $result = $search->index($user, $limit, $dry);
            $line = sprintf(
                '#%d %s — %d tài liệu · đã nhúng %d · còn lại %d',
                $user->id,
                (string) $user->email,
                (int) $result['total'],
                (int) $result['indexed'],
                (int) $result['pending'],
            );

            if (! empty($result['skipped'])) {
                $line .= ' · BỎ QUA '.$result['skipped'].' văn bản (nhà cung cấp từ chối riêng chúng) — sẽ thử lại lượt sau';
            }

            if (! empty($result['unavailable'])) {
                // NÓI THẬT: không có nhà cung cấp nhúng ⇒ chỉ mục đứng yên, và tìm kiếm sẽ chạy chế độ từ khoá.
                $line .= ' · KHÔNG nhúng được (chưa có nhà cung cấp /embeddings) — tìm kiếm sẽ dùng chế độ từ khoá';
            } elseif (! empty($result['provider']['model'])) {
                $line .= ' · '.$result['provider']['provider'].':'.$result['provider']['model'];
            }

            $this->line($line);
        }

        return self::SUCCESS;
    }
}
