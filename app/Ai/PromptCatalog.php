<?php

namespace App\Ai;

use App\Models\PromptTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DANH MỤC CHỈ DẪN (PROMPT) CẤU HÌNH ĐƯỢC — MỘT nguồn sự thật duy nhất (2026-09-22).
 *
 * Vì sao có lớp này: khoá chỉ dẫn từng bị khai báo LẶP ở hai nơi (lệnh artisan và — nếu tôi viết ẩu —
 * giao diện quản trị). Lệch nhau một ký tự là giao diện sửa khoá A còn hệ thống đọc khoá B: sửa xong
 * "không có tác dụng" mà không ai biết vì sao. Mọi đường (CLI, HTTP, tương lai) đều đọc từ ĐÂY.
 *
 * Kèm theo đó là BẢN MẶC ĐỊNH TRONG MÃ: chỉ dẫn radar dài hơn 3.000 ký tự và được lắp từ nhiều mảnh
 * ngay trong DesignAgentService. Chủ dự án không thể sửa một chỉ dẫn mà không nhìn thấy bản gốc —
 * nên mỗi lần hệ thống chạy bằng bản mặc định, ta GHI NHỚ lại chính chuỗi đã dựng (xem rememberDefault)
 * để giao diện hiển thị "bản đang chạy" và cho phép nhân bản rồi sửa.
 */
final class PromptCatalog
{
    /** Tiền tố cache giữ bản mặc định gần nhất ĐÃ THỰC SỰ chạy cho mỗi khoá. */
    public const CACHE_PREFIX = 'studio.prompt.default.';

    /** Không giữ lâu hơn mức này: bản mặc định đổi theo mã nguồn, giữ mãi sẽ hiển thị bản cũ. */
    private const DEFAULT_TTL_DAYS = 30;

    /**
     * Khoá => mô tả cho NGƯỜI ĐỌC. Thứ tự ở đây là thứ tự hiển thị.
     * 'vars' là các placeholder {ten} mà chỉ dẫn nhận được — giao diện liệt kê để owner biết chèn gì.
     */
    private const ENTRIES = [
        'agent.radar.instruction' => [
            'label' => 'Radar xu hướng — câu lệnh quét',
            'flow' => 'Quét xu hướng',
            'what' => 'Câu lệnh gửi model khi quét xu hướng thị trường và trả về JSON các hướng thiết kế.',
            'vars' => ['today', 'region', 'region_name'],
        ],
        'agent.collection_brief.instruction' => [
            'label' => 'Bộ sưu tập — bản tóm tắt',
            'flow' => 'Bộ sưu tập',
            'what' => 'Câu lệnh viết bản tóm tắt (brief) cho một bộ sưu tập trước khi sinh ảnh.',
            'vars' => ['today', 'region', 'region_name', 'collection_prompt'],
        ],
        'agent.sample_prompt.instruction' => [
            'label' => 'Mẫu ảnh — câu lệnh viết prompt',
            'flow' => 'Mẫu ảnh',
            'what' => 'Câu lệnh viết prompt ảnh cho MỘT mẫu (seed) trong bộ sưu tập.',
            'vars' => ['today', 'sample_name', 'sample_category', 'collection_prompt'],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ENTRIES);
    }

    public static function has(string $key): bool
    {
        return isset(self::ENTRIES[$key]);
    }

    public static function entry(string $key): ?array
    {
        if (! isset(self::ENTRIES[$key])) {
            return null;
        }

        return ['key' => $key] + self::ENTRIES[$key];
    }

    /**
     * Toàn bộ danh mục kèm TRẠNG THÁI THẬT trong bảng prompt_templates.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[] = self::entry($key) + self::state($key);
        }

        return $out;
    }

    /** Trạng thái cấu hình của MỘT khoá: bản đang bật + lịch sử phiên bản + bản mặc định đã ghi nhận. */
    public static function state(string $key): array
    {
        $versions = self::versions($key);
        $active = null;
        foreach ($versions as $v) {
            if ($v['is_active']) {
                $active = $v;
                break; // versions() đã sắp version GIẢM dần.
            }
        }

        return [
            'configured' => $active !== null,
            'active_body' => $active['body'] ?? null,
            'active_version' => $active['version'] ?? null,
            'active_note' => $active['note'] ?? null,
            'active_at' => $active['created_at'] ?? null,
            'active_chars' => $active !== null ? mb_strlen((string) $active['body']) : 0,
            'versions' => $versions,
            'default_body' => self::defaultBody($key),
            'default_at' => self::defaultAt($key),
        ];
    }

    /**
     * Lịch sử phiên bản (mới nhất trước). Có 'body' để giao diện xem/khôi phục được bản cũ —
     * nhưng chỉ trả 20 bản gần nhất: chỉ dẫn radar 3.000 ký tự, trả hết là phình payload vô ích.
     *
     * @return list<array<string, mixed>>
     */
    public static function versions(string $key, int $limit = 20): array
    {
        return PromptTemplate::query()
            ->where('key', $key)
            ->orderByDesc('version')
            ->limit($limit)
            ->get()
            ->map(fn (PromptTemplate $r) => [
                'version' => (int) $r->version,
                'is_active' => (bool) $r->is_active,
                'body' => (string) $r->body,
                'chars' => mb_strlen((string) $r->body),
                'note' => $r->label,
                'created_at' => $r->created_at?->format('d/m/Y H:i'),
            ])
            ->all();
    }

    /**
     * Ghi lại bản MẶC ĐỊNH trong mã mà hệ thống VỪA dùng, để giao diện hiển thị được.
     *
     * Gọi ở đường chạy thật (DesignAgentService::instruction) khi khoá CHƯA được cấu hình. Rẻ: một lần
     * đọc cache, chỉ ghi khi nội dung đổi. Không có bước này thì owner phải sửa chỉ dẫn trong bóng tối.
     */
    public static function rememberDefault(string $key, string $built): void
    {
        $built = (string) $built;
        if (trim($built) === '') {
            return;
        }

        $cached = Cache::get(self::CACHE_PREFIX.$key);
        if (is_array($cached) && ($cached['body'] ?? null) === $built) {
            return;
        }

        // Giá trị đã thay placeholder theo lượt chạy — chỉ là ẢNH CHỤP để tham chiếu, không phải nguồn chạy.
        Cache::put(self::CACHE_PREFIX.$key, ['body' => $built, 'at' => now()->format('d/m/Y H:i')], now()->addDays(self::DEFAULT_TTL_DAYS));
    }

    public static function defaultBody(string $key): ?string
    {
        $cached = Cache::get(self::CACHE_PREFIX.$key);

        return is_array($cached) ? ($cached['body'] ?? null) : null;
    }

    public static function defaultAt(string $key): ?string
    {
        $cached = Cache::get(self::CACHE_PREFIX.$key);

        return is_array($cached) ? ($cached['at'] ?? null) : null;
    }

    /**
     * ĐẶT chỉ dẫn: LUÔN tạo phiên bản MỚI rồi bật nó lên (giữ bản cũ để đối chiếu/khôi phục).
     * Từ chối nội dung rỗng: chỉ dẫn rỗng làm lượt chạy mất hết chỉ dẫn mà vẫn "thành công".
     */
    public static function put(string $key, string $body, ?string $note = null): PromptTemplate
    {
        $body = (string) $body;
        if (trim($body) === '') {
            throw new \InvalidArgumentException('Chỉ dẫn rỗng — từ chối.');
        }
        if (! self::has($key)) {
            throw new \InvalidArgumentException('Khoá chỉ dẫn không có trong danh mục: '.$key);
        }

        // MỘT bản bật tại một thời điểm: nếu để nhiều bản cùng bật thì "bản đang chạy" phụ thuộc
        // thứ tự truy vấn, và thao tác khôi phục phiên bản cũ trở nên vô nghĩa.
        return DB::transaction(function () use ($key, $body, $note) {
            $next = (int) PromptTemplate::query()->where('key', $key)->max('version') + 1;

            PromptTemplate::query()->where('key', $key)->where('is_active', true)->update(['is_active' => false]);

            return PromptTemplate::create([
                'key' => $key,
                'body' => $body,
                'version' => $next,
                'is_active' => true,
                'label' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 120) : 'Sửa từ giao diện '.now()->format('d/m/Y H:i'),
            ]);
        });
    }

    /** Bật lại MỘT phiên bản cũ (tắt mọi bản khác của cùng khoá) — "khôi phục" trong giao diện. */
    public static function activate(string $key, int $version): bool
    {
        $row = PromptTemplate::query()->where('key', $key)->where('version', $version)->first();
        if ($row === null) {
            return false;
        }

        DB::transaction(function () use ($key, $row) {
            PromptTemplate::query()->where('key', $key)->where('is_active', true)->update(['is_active' => false]);
            // PHẢI là mass-update: $row->update() trên một model ĐANG có is_active=true không thấy
            // thuộc tính nào thay đổi nên KHÔNG chạy câu UPDATE nào — bản cũ không được bật lại (đã dính thật).
            PromptTemplate::query()->whereKey($row->getKey())->update(['is_active' => true]);
        });

        return true;
    }

    /** Tắt MỌI bản của khoá ⇒ hệ thống quay về chuỗi mặc định trong mã. Trả về số bản đã tắt. */
    public static function turnOff(string $key): int
    {
        $n = PromptTemplate::query()->where('key', $key)->where('is_active', true)->update(['is_active' => false]);

        // Quên ảnh chụp của CHÍNH khoá này: lượt chạy kế tiếp sẽ ghi lại bản mặc định hiện hành.
        // KHÔNG quên ảnh chụp của các khoá khác — chúng vẫn đang chạy đúng bản cũ.
        Cache::forget(self::CACHE_PREFIX.$key);

        return $n;
    }
}
