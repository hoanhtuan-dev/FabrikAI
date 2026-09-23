<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Support\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * GẮN MODULE VÀO GÓI BẰNG MỘT LỆNH — VÀ KIỂM TRA LỆCH GIỮA CÁC GÓI (2026-09-26).
 *
 * VÌ SAO CẦN LỆNH NÀY (đo thật trên production, không phải giả định):
 * Bản khai module là MỘT nguồn duy nhất, nhưng "gói nào cấp module nào" lại nằm trong DỮ LIỆU
 * (`plans.modules`) — dữ liệu thì chỉ đúng ở thời điểm nó được ghi. Khi một module MỚI được thêm vào bản
 * khai (hoặc một module cũ được gắn thêm phụ thuộc), các gói đã cấu hình từ trước KHÔNG tự biết. Đo trên
 * production 2026-09-26 bằng chính lệnh này:
 *
 *   · ba gói trả tiền (pro · studio · factory_season) đều ĐÃ có 'stylist' (Agent thiết kế) nhưng THIẾU
 *     'trend_radar' và 'collection_bot' — mà 'stylist' `depends_on` đúng hai module đó. Hệ quả:
 *     module_allowed('stylist') = false ⇒ KHÁCH TRẢ TIỀN MỞ AGENT STUDIO BỊ CHẶN, còn quản trị
 *     (super_admin được miễn công tắc gói) thì vẫn vào được — nên lỗi im lặng, không ai thấy.
 *   · chat của trợ lý nằm trong 'collection_bot' ⇒ khách cũng bị chặn chat y như vậy.
 *
 * Vì sao không sửa bằng migration: đây là DỮ LIỆU KINH DOANH (gói nào bán gì), không phải cấu trúc bảng.
 * Migration chạy một lần rồi thôi, còn chủ dự án cần một việc LÀM LẠI ĐƯỢC mỗi lần thêm module mới.
 *
 * BA VIỆC CỦA LỆNH:
 *   1. CẤP module cho gói — mặc định theo ĐỀ XUẤT của bản khai (`ModuleRegistry::MODULES[...]['plans']`),
 *      hoặc chỉ định `--plans=`, hoặc `--all-plans`;
 *   2. CẤP KÈM MODULE PHỤ THUỘC (mặc định BẬT): một gói không thể cấp tính năng con mà thiếu cha — cấp
 *      'stylist' mà thiếu 'trend_radar' là cấp một quyền KHÔNG DÙNG ĐƯỢC;
 *   3. `--check`: rà MỌI gói đang mở bán và báo lệch. Trả mã lỗi khi có gói VI PHẠM PHỤ THUỘC (lỗi thật);
 *      phần "thiếu so với đề xuất" chỉ là GỢI Ý (chủ dự án có quyền cố ý không bán một tính năng).
 *
 * AN TOÀN: gói có `modules = NULL` nghĩa là "chưa cấu hình ⇒ ĐỦ module" (xem Plan::modules()). Lệnh này
 * KHÔNG BAO GIỜ ghi vào những gói đó — ghi vào là biến "đủ mọi thứ" thành một danh sách cụ thể, tức là
 * ÂM THẦM CẮT tính năng của khách đang dùng.
 *
 * Dùng:
 *   php artisan studio:modules-grant --check
 *   php artisan studio:modules-grant collection_bot --dry-run
 *   php artisan studio:modules-grant collection_bot,trend_radar
 *   php artisan studio:modules-grant collection_bot --plans=pro,studio,factory_season
 *   php artisan studio:modules-grant stylist --all-plans
 */
class ModuleGrantCommand extends Command
{
    protected $signature = 'studio:modules-grant
        {modules?* : id module cần cấp (cách nhau dấu phẩy, hoặc viết nhiều tham số)}
        {--plans= : slug các gói nhận module (cách nhau dấu phẩy). Bỏ trống = dùng ĐỀ XUẤT của bản khai}
        {--all-plans : cấp cho MỌI gói đang mở bán (bỏ qua đề xuất)}
        {--no-deps : KHÔNG cấp kèm module phụ thuộc (mặc định là CÓ)}
        {--dry-run : chỉ in ra sẽ đổi gì, KHÔNG ghi}
        {--check : chỉ KIỂM TRA lệch giữa các gói, KHÔNG ghi}';

    protected $description = 'Gắn module (tính năng) vào gói: theo đề xuất của bản khai, kèm module phụ thuộc; có --check để rà lệch.';

    public function handle(): int
    {
        if ((bool) $this->option('check')) {
            return $this->runCheck();
        }

        $ids = $this->requestedIds();
        if ($ids === []) {
            $this->error('Chưa nêu module nào. Ví dụ: php artisan studio:modules-grant collection_bot');

            return self::FAILURE;
        }

        $unknown = array_values(array_diff($ids, ModuleRegistry::ids()));
        if ($unknown !== []) {
            $this->error('Không có module nào tên: '.implode(', ', $unknown));
            $this->line('Id hợp lệ: '.implode(', ', ModuleRegistry::ids()));

            return self::FAILURE;
        }

        // PHỤ THUỘC cấp kèm: đóng bao nhiêu lần cũng được, dừng khi không thêm được gì nữa (bản khai có
        // thể khai chuỗi nhiều tầng: con → cha → ông).
        $grant = $ids;
        if (! (bool) $this->option('no-deps')) {
            $grant = $this->withDependencies($grant);
            $added = array_values(array_diff($grant, $ids));
            if ($added !== []) {
                $this->line('<info>Cấp kèm module phụ thuộc:</info> '.implode(', ', $added)
                    .' — thiếu chúng thì module được cấp KHÔNG dùng được (xem depends_on trong bản khai).');
            }
        }

        $plans = $this->targetPlans($ids);
        if ($plans === []) {
            $this->warn('Không tìm thấy gói nào để cấp. Kiểm lại --plans, hoặc dùng --all-plans.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        foreach ($plans as $plan) {
            $stored = $this->storedModules($plan);

            if ($stored === null) {
                $this->line('<comment>· '.$plan->slug.'</comment>: chưa cấu hình ⇒ đang cấp ĐỦ module, '
                    .'KHÔNG ghi gì (ghi vào là cắt bớt tính năng đang có).');

                continue;
            }

            $missing = array_values(array_diff($grant, $stored));
            if ($missing === []) {
                $this->line('· '.$plan->slug.': đã có đủ '.count($grant).' module, không đổi.');

                continue;
            }

            // Giữ thứ tự theo BẢN KHAI (dữ liệu đọc được, so sánh được) — cùng lối với màn Quản trị.
            $next = array_values(array_filter(ModuleRegistry::ids(), fn (string $id) => in_array($id, array_merge($stored, $grant), true)));

            $this->line(($dry ? '<comment>[thử]</comment> ' : '').'· '.$plan->slug.': +'.count($missing).' module ('
                .implode(', ', $missing).') ⇒ '.count($next).' module.');

            if (! $dry) {
                $plan->forceFill(['modules' => $next])->save();
                $changed++;
            }
        }

        if ($dry) {
            $this->line('Chạy lại KHÔNG có --dry-run để ghi thật.');

            return self::SUCCESS;
        }

        $this->info($changed === 0 ? 'Không có gói nào cần đổi.' : 'Đã cập nhật '.$changed.' gói.');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function requestedIds(): array
    {
        $out = [];
        foreach ((array) $this->argument('modules') as $raw) {
            foreach (explode(',', (string) $raw) as $one) {
                $one = trim($one);
                if ($one !== '' && ! in_array($one, $out, true)) {
                    $out[] = $one;
                }
            }
        }

        return $out;
    }

    /**
     * Đóng kín PHỤ THUỘC của danh sách module (con ⇒ mọi cha, nhiều tầng).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function withDependencies(array $ids): array
    {
        $out = $ids;

        for ($round = 0; $round < 10; $round++) {
            $before = count($out);
            foreach ($out as $id) {
                foreach ((array) (ModuleRegistry::get($id)['depends_on'] ?? []) as $parent) {
                    $parent = (string) $parent;
                    if ($parent !== '' && ! in_array($parent, $out, true)) {
                        $out[] = $parent;
                    }
                }
            }
            if (count($out) === $before) {
                break;
            }
        }

        // Trả về theo thứ tự bản khai để câu in ra đọc được.
        return array_values(array_filter(ModuleRegistry::ids(), fn (string $id) => in_array($id, $out, true)));
    }

    /**
     * Gói nhận module: `--plans` chỉ định tay, `--all-plans` là mọi gói đang mở bán, còn mặc định là
     * ĐỀ XUẤT của bản khai cho từng module ('*' = mọi gói đang mở bán).
     *
     * @param  list<string>  $ids
     * @return \Illuminate\Support\Collection<int, Plan>
     */
    private function targetPlans(array $ids)
    {
        $active = Plan::query()->where('is_active', true)->orderBy('sort')->get();

        if ((bool) $this->option('all-plans')) {
            return $active;
        }

        $slugs = [];
        $raw = trim((string) $this->option('plans'));

        if ($raw !== '') {
            foreach (explode(',', $raw) as $slug) {
                $slug = trim($slug);
                if ($slug !== '') {
                    $slugs[] = $slug;
                }
            }
        } else {
            foreach ($ids as $id) {
                foreach (ModuleRegistry::defaultPlanSlugs($id) as $slug) {
                    if ($slug === '*') {
                        return $active;
                    }
                    $slugs[] = (string) $slug;
                }
            }
        }

        $slugs = array_values(array_unique($slugs));

        return $active->filter(fn (Plan $p) => in_array((string) $p->slug, $slugs, true))->values();
    }

    /**
     * Danh sách module ĐANG LƯU của gói; `null` = chưa cấu hình (đủ module — xem Plan::modules()).
     *
     * @return list<string>|null
     */
    private function storedModules(Plan $plan): ?array
    {
        if (! is_array($plan->modules)) {
            return null;
        }

        return array_values(array_filter(array_map('strval', $plan->modules)));
    }

    /**
     * RÀ LỆCH giữa các gói. Hai mức, cố ý KHÁC NHAU về mức nghiêm trọng:
     *   · THIẾU PHỤ THUỘC = LỖI (mã trả về khác 0): gói cấp tính năng con mà thiếu cha ⇒ khách thấy nút
     *     nhưng backend chặn, đúng loại lỗi im lặng mà dự án cấm;
     *   · THIẾU SO VỚI ĐỀ XUẤT = GỢI Ý: bản khai đề xuất, chủ dự án có quyền cố ý không bán.
     */
    private function runCheck(): int
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();
        $errors = 0;
        $hints = 0;

        $this->line('RÀ LỆCH MODULE GIỮA CÁC GÓI ĐANG MỞ BÁN');
        $this->line(str_repeat('─', 64));

        foreach ($plans as $plan) {
            $slug = (string) $plan->slug;
            $stored = $this->storedModules($plan);

            if ($stored === null) {
                $this->line('<comment>· '.$slug.'</comment>: chưa cấu hình ⇒ ĐỦ module (mặc định). Không rà.');

                continue;
            }

            $missingDeps = [];
            foreach ($stored as $id) {
                foreach ($this->withDependencies([$id]) as $need) {
                    if (! in_array($need, $stored, true)) {
                        $missingDeps[] = $id.' → '.$need;
                    }
                }
            }
            $missingDeps = array_values(array_unique($missingDeps));

            $suggested = ModuleRegistry::suggestedForPlan($slug);
            $missingSuggested = array_values(array_diff($suggested, $stored));

            $this->line('· '.$slug.': '.count($stored).' module'
                .($missingDeps === [] ? ' · phụ thuộc ĐỦ' : ' · <error>THIẾU PHỤ THUỘC '.count($missingDeps).'</error>')
                .($missingSuggested === [] ? '' : ' · thiếu so với đề xuất: '.implode(', ', $missingSuggested)));

            foreach ($missingDeps as $line) {
                $this->line('    <error>LỖI</error> '.$line.' — cấp con mà thiếu cha thì con KHÔNG dùng được.');
            }

            $errors += count($missingDeps);
            $hints += count($missingSuggested);
        }

        $this->line(str_repeat('─', 64));

        if ($errors > 0) {
            $this->error('Có '.$errors.' chỗ thiếu module phụ thuộc. Sửa: php artisan studio:modules-grant --all-plans <id module con>');

            return self::FAILURE;
        }

        $this->info('Không gói nào vi phạm phụ thuộc.'
            .($hints > 0 ? ' Còn '.$hints.' gợi ý thiếu so với bản khai (không phải lỗi).' : ''));

        return self::SUCCESS;
    }
}
