<?php

namespace App\Services;

use App\Models\BrandRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRÍ NHỚ THỦ TỤC (Procedural Memory — GĐ2): quy tắc "khi <tình huống> thì <cách làm>" của chủ shop.
 *
 * VÌ SAO LÀ MỘT LOẠI TRÍ NHỚ RIÊNG, không gộp vào brand_dna: brand_dna là SỞ THÍCH PHẲNG (phong cách,
 * màu, chất liệu, thứ không làm) — nó không diễn đạt được QUAN HỆ ĐIỀU KIỆN. Câu *"khi làm đồ công sở
 * thì ưu tiên màu trung tính và chất liệu ít nhăn"* chỉ có nghĩa khi biết "khi nào"; tách khỏi điều kiện
 * là nó thành một lời khuyên chung chung áp cho mọi bộ sưu tập — đúng thứ làm brief "nghe hợp lý" mà vô dụng.
 *
 * TRẦN NẰM Ở ĐÂY (MỘT nguồn): dữ liệu này đi thẳng vào prompt nên không được phình. Controller và giao
 * diện đọc trần từ đây (BrandRuleService::limits()) thay vì chép lại — hai bản chép sẽ lệch nhau.
 */
class BrandRuleService
{
    /** Trần số quy tắc mỗi tài khoản. Đủ cho một chủ shop thật; vượt là prompt loãng và không ai đọc. */
    public const MAX_RULES = 20;

    public const TRIGGER_MAX = 120;

    public const ACTION_MAX = 240;

    public const WEIGHT_MIN = 1;

    public const WEIGHT_MAX = 10;

    public const DEFAULT_WEIGHT = 5;

    /** 'owner' = chủ shop tự đặt · 'learned' = agent rút ra từ kinh nghiệm (đường học, chưa nối ở bản này). */
    public const SOURCES = [BrandRule::SOURCE_OWNER, BrandRule::SOURCE_LEARNED];

    public const SOURCE_LABELS = [
        BrandRule::SOURCE_OWNER => 'Do bạn đặt',
        BrandRule::SOURCE_LEARNED => 'Agent rút ra',
    ];

    /** Hình dạng + trần cho controller và giao diện — MỘT nguồn khai báo. */
    public static function limits(): array
    {
        return [
            'max_rules' => self::MAX_RULES,
            'trigger_max' => self::TRIGGER_MAX,
            'action_max' => self::ACTION_MAX,
            'weight' => ['min' => self::WEIGHT_MIN, 'max' => self::WEIGHT_MAX, 'default' => self::DEFAULT_WEIGHT],
            'sources' => self::SOURCES,
            'source_labels' => self::SOURCE_LABELS,
        ];
    }

    /**
     * Chuẩn hoá danh sách quy tắc — HÀM THUẦN (không DB) nên test được trực tiếp.
     *
     * Luật: cắt khoảng trắng, cắt theo trần, bỏ dòng THIẾU một trong hai vế, bỏ trùng theo (trigger,
     * action) GIỮ BẢN ĐẦU, chặn ở MAX_RULES, và gán `sort` theo đúng thứ tự người dùng đang thấy.
     *
     * @param  array<int, mixed>  $rows
     * @return array{rows: list<array<string, mixed>>, dropped: int, truncated: int}
     */
    public static function normalizeRows(array $rows): array
    {
        $out = [];
        $seen = [];
        $dropped = 0;
        $truncated = 0;

        foreach ($rows as $raw) {
            if (! is_array($raw)) {
                $dropped++;
                continue;
            }

            $trigger = Str::limit(trim((string) ($raw['trigger'] ?? '')), self::TRIGGER_MAX, '');
            $action = Str::limit(trim((string) ($raw['action'] ?? '')), self::ACTION_MAX, '');

            // Thiếu một vế = quy tắc không dùng được ("khi nào" mà không có "làm gì", hoặc ngược lại).
            // Bỏ và ĐẾM để nơi gọi nói được với người dùng là đã bỏ bao nhiêu — im lặng bỏ là ghi đè
            // công sức người ta gõ mà không ai biết.
            if ($trigger === '' || $action === '') {
                $dropped++;
                continue;
            }

            // Dấu vân tay của một quy tắc — dùng json_encode cho CHÍNH XÁC (mọi dấu phân cách tự chế
            // đều có ngày đụng phải chữ người dùng gõ).
            $signature = json_encode([mb_strtolower($trigger), mb_strtolower($action)], JSON_UNESCAPED_UNICODE);
            if (isset($seen[$signature])) {
                $dropped++;
                continue;
            }
            $seen[$signature] = true;

            if (count($out) >= self::MAX_RULES) {
                $truncated++;
                continue;
            }

            $weight = (int) ($raw['weight'] ?? self::DEFAULT_WEIGHT);
            $source = (string) ($raw['source'] ?? BrandRule::SOURCE_OWNER);
            $id = $raw['id'] ?? null;

            $out[] = [
                'id' => is_numeric($id) ? (int) $id : null,
                'trigger' => $trigger,
                'action' => $action,
                'weight' => max(self::WEIGHT_MIN, min(self::WEIGHT_MAX, $weight)),
                'source' => in_array($source, self::SOURCES, true) ? $source : BrandRule::SOURCE_OWNER,
                'is_active' => ! array_key_exists('is_active', $raw) || (bool) $raw['is_active'],
                'sort' => count($out),
            ];
        }

        return ['rows' => $out, 'dropped' => $dropped, 'truncated' => $truncated];
    }

    /**
     * TOÀN BỘ quy tắc của một người (giao diện biên tập cần thấy cả hàng đang tắt).
     *
     * @return list<array<string, mixed>>
     */
    public function all(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return BrandRule::query()
            ->where('user_id', $user->id)
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->map(fn (BrandRule $rule) => $this->present($rule))
            ->all();
    }

    /**
     * Quy tắc ĐANG BẬT, gọn để nhét vào prompt: chỉ chữ + trọng số, ưu tiên cao trước.
     *
     * Không trả id/user_id/thời gian: prompt chỉ cần nội dung, và mọi khoá thừa là token thừa.
     *
     * @return list<array{trigger: string, action: string, weight: int}>
     */
    public function active(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return BrandRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('weight')->orderBy('sort')->orderBy('id')
            ->limit(self::MAX_RULES)
            ->get(['trigger', 'action', 'weight'])
            ->map(fn (BrandRule $rule) => [
                'trigger' => (string) $rule->trigger,
                'action' => (string) $rule->action,
                'weight' => (int) $rule->weight,
            ])
            ->all();
    }

    /**
     * Ghi danh sách quy tắc của CHÍNH người dùng đang đăng nhập.
     *
     * Ngữ nghĩa: GHI ĐÈ ĐÚNG TẬP HỢP ĐANG THẤY. Hàng có `id` khớp thì cập nhật tại chỗ (giữ
     * created_at); hàng mới thì tạo; hàng CÓ TRONG DB mà KHÔNG có trong payload thì xoá — vì giao diện
     * nạp ĐỦ danh sách (all()) trước khi sửa, nên "không gửi lên" nghĩa là "người dùng đã xoá".
     * Cách này không xoá oan hàng do đường khác ghi vào mà giao diện chưa từng thấy.
     *
     * @param  array<int, mixed>  $rows
     * @return array{rules: list<array<string, mixed>>, dropped: int, truncated: int}
     */
    public function save(?User $user, array $rows): array
    {
        if (! $user) {
            return ['rules' => [], 'dropped' => 0, 'truncated' => 0];
        }

        $normalized = self::normalizeRows($rows);

        DB::transaction(function () use ($user, $normalized) {
            $keep = [];
            foreach ($normalized['rows'] as $row) {
                $attributes = [
                    'trigger' => $row['trigger'],
                    'action' => $row['action'],
                    'weight' => $row['weight'],
                    'source' => $row['source'],
                    'is_active' => $row['is_active'],
                    'sort' => $row['sort'],
                ];

                $existing = $row['id'] !== null
                    ? BrandRule::query()->where('user_id', $user->id)->whereKey($row['id'])->first()
                    : null;

                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $keep[] = $existing->id;
                    continue;
                }

                $created = BrandRule::create($attributes + ['user_id' => $user->id]);
                $keep[] = $created->id;
            }

            BrandRule::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $keep === [] ? [0] : $keep)
                ->delete();
        });

        return [
            'rules' => $this->all($user),
            'dropped' => $normalized['dropped'],
            'truncated' => $normalized['truncated'],
        ];
    }

    /** Xoá hết quy tắc của một người (KHÔNG đụng DNA, dữ liệu bán hàng hay trí nhớ sự kiện). */
    public function reset(?User $user): array
    {
        if ($user) {
            BrandRule::query()->where('user_id', $user->id)->delete();
        }

        return ['rules' => $this->all($user), 'dropped' => 0, 'truncated' => 0];
    }

    /** Hình dạng trả ra giao diện cho MỘT hàng — một nơi, không chép ở ba chỗ. */
    private function present(BrandRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'trigger' => (string) $rule->trigger,
            'action' => (string) $rule->action,
            'weight' => (int) $rule->weight,
            'source' => (string) $rule->source,
            'source_label' => self::SOURCE_LABELS[$rule->source] ?? $rule->source,
            'is_active' => (bool) $rule->is_active,
            'sort' => (int) $rule->sort,
        ];
    }
}
