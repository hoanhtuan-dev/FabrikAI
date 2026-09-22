<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebFinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SỔ NGUỒN ĐÃ TÌM ĐƯỢC — mắt xích BIẾN VIỆC TRA CỨU THÀNH VÒNG KHÉP KÍN (2026-09-26).
 *
 * Vấn đề của bản trước: \`web_search\` chạy thật và trả kết quả thật, nhưng kết quả chỉ sống trong ĐÚNG một
 * lời gọi model. Tra xong là quên — nên lượt sau hỏi lại đúng câu đó vẫn phải đi mạng lại, giao diện chỉ
 * có con số đếm chứ không có nguồn nào để bấm vào, và KHÔNG có chỗ nào để người dùng giữ một nguồn hay.
 *
 * Vòng khép kín mà lớp này giữ:
 *   1. VÀO   — mọi công cụ cần dữ kiện ngoài đều đi qua một cổng (AgentToolbox -> WebSearchTool).
 *   2. LƯU   — \`remember()\`: mỗi nguồn tìm được ghi vào sổ kèm từ khoá, vùng, thời điểm, đoạn trích.
 *   3. DÙNG LẠI — \`recall()\`: hỏi lại câu cũ (hoặc mạng hỏng) thì trả nguồn từ sổ, KHÔNG đi mạng lại;
 *      \`evidenceItems()\`: nguồn đã tìm quay về khối DỮ LIỆU của Agent Studio ở lượt chạy sau.
 *   4. NGƯỜI DÙNG — \`markSaved()\`: nguồn người dùng LƯU được xếp TRƯỚC trong mọi lần dùng lại.
 *
 * Hai nguyên tắc giữ cho sổ không nói dối:
 *   · \`reused\` là cờ SỰ THẬT — nguồn lấy từ sổ không bao giờ được trình bày như vừa đi mạng;
 *   · hỏng DB (bảng chưa migrate) KHÔNG được giết lượt chạy, nhưng cũng KHÔNG được im lặng: mọi hàm trả
 *     về số đo hoặc \`error\` để tầng trên nói đúng "không ghi được sổ nguồn", thay vì hứa "đã lưu".
 */
class WebFindingService
{
    /** Nguồn cũ hơn mức này thì KHÔNG dùng lại — xu hướng/thị trường cũ 30 ngày là thông tin sai lệch. */
    public const KEEP_DAYS = 30;

    /** Trần nguồn trả về cho một lần dùng lại (đi thẳng vào prompt nên phải ngắn). */
    public const RECALL_LIMIT = 6;

    private const TITLE_CHARS = 200;

    private const SNIPPET_CHARS = 400;

    private const QUERY_CHARS = 160;

    /**
     * KHOÁ DÙNG LẠI của một từ khoá: thường hoá + gộp khoảng trắng.
     *
     * Vì sao không so khớp nguyên văn: model hỏi lại cùng một ý với chữ hoa/thừa khoảng trắng khác nhau
     * ("Xu hướng áo dạ tweed 2026" vs "xu hướng  áo dạ tweed 2026") — so nguyên văn thì sổ không bao giờ
     * khớp và tính năng dùng lại trở thành trang trí.
     */
    public static function queryKey(string $query): string
    {
        $query = (string) preg_replace('/\s+/u', ' ', mb_strtolower(strip_tags($query)));

        return Str::limit(trim($query), self::QUERY_CHARS, '');
    }

    /**
     * GHI SỔ những nguồn vừa tìm được cho MỘT từ khoá.
     *
     * Gặp lại cùng URL: CẬP NHẬT (tăng \`hits\`, làm mới \`last_seen_at\`, bổ sung tiêu đề/đoạn trích nếu lần
     * này đọc được nhiều hơn) — không đẻ hàng trùng, vì "nguồn này xuất hiện nhiều lần" tự nó là thông tin.
     *
     * @param  list<array<string, mixed>>  $items  item theo hình dạng của WebSourceService
     * @return array{stored:int, updated:int, error:?string}
     */
    public function remember(?User $user, string $query, string $region, array $items): array
    {
        $out = ['stored' => 0, 'updated' => 0, 'error' => null];

        if ($user === null || $items === []) {
            return $out;
        }

        $key = self::queryKey($query);
        if ($key === '') {
            return $out;
        }

        $region = trim($region) !== '' ? trim($region) : 'all';

        try {
            foreach ($items as $item) {
                $url = trim((string) ($item['url'] ?? ''));
                // Sổ này về sau được ĐỌC LẠI rồi đưa vào prompt: chỉ nhận địa chỉ công khai, không nhận
                // javascript:/mailto:/đường dẫn nội bộ — nếu không thì sổ thành đường bơm dữ liệu bẩn.
                if (! preg_match('#^https?://#i', $url)) {
                    continue;
                }

                $hash = md5($url);
                $row = WebFinding::query()->where('user_id', $user->id)->where('url_hash', $hash)->first();

                $fresh = [
                    'query' => Str::limit($query, self::QUERY_CHARS, ''),
                    'query_key' => $key,
                    'region' => $region,
                    'url' => Str::limit($url, 500, ''),
                    'title' => Str::limit(trim((string) ($item['title'] ?? '')), self::TITLE_CHARS, ''),
                    'source_name' => Str::limit(trim((string) ($item['source_name'] ?? $item['source'] ?? '')), 120, ''),
                    'snippet' => Str::limit(trim((string) ($item['summary'] ?? $item['snippet'] ?? '')), self::SNIPPET_CHARS, ''),
                    'published_at' => $this->moment($item['published_at'] ?? null),
                    'last_seen_at' => now(),
                ];

                if ($row === null) {
                    WebFinding::query()->create($fresh + [
                        'user_id' => $user->id,
                        'url_hash' => $hash,
                        'hits' => 1,
                        'first_seen_at' => now(),
                    ]);
                    $out['stored']++;
                    continue;
                }

                // KHÔNG ghi đè bằng giá trị RỖNG: lần sau nguồn trả về thiếu tiêu đề/đoạn trích thì không
                // được xoá thứ đã đọc được ở lần trước — nếu không, "dùng lại" trả về nguồn cụt thông tin.
                $row->fill(array_filter($fresh, fn ($value) => $value !== null && $value !== ''));
                $row->hits = (int) $row->hits + 1;
                $row->save();
                $out['updated']++;
            }
        } catch (\Throwable $e) {
            // Bảng chưa migrate / DB hỏng: sổ nguồn là tính năng PHỤ, không được giết lượt chạy — nhưng
            // phải NÓI RA là không ghi được để tầng trên không hứa "đã lưu nguồn".
            $out['error'] = 'không ghi được sổ nguồn: '.class_basename($e);
        }

        return $out;
    }

    /**
     * DÙNG LẠI nguồn đã tra cho ĐÚNG từ khoá này — đường trả lời khi mạng không có gì hoặc đã hỏi câu cũ.
     *
     * @return list<array<string, mixed>>
     */
    public function recall(?User $user, string $query, string $region, int $limit = self::RECALL_LIMIT): array
    {
        if ($user === null) {
            return [];
        }

        $key = self::queryKey($query);
        if ($key === '') {
            return [];
        }

        $region = trim($region) !== '' ? trim($region) : 'all';

        try {
            return WebFinding::query()
                ->where('user_id', $user->id)
                ->where('query_key', $key)
                ->whereIn('region', array_values(array_unique(['all', $region])))
                ->where('last_seen_at', '>=', now()->subDays(self::KEEP_DAYS))
                // Nguồn NGƯỜI DÙNG ĐÃ LƯU đứng trước: đó là tín hiệu mạnh nhất có trong sổ. \`saved_at IS NULL\`
                // xếp 0 (đã lưu) lên trước 1 (chưa lưu) ở CẢ MySQL lẫn SQLite.
                ->orderByRaw('saved_at IS NULL')
                ->orderByDesc('saved_at')
                ->orderByDesc('last_seen_at')
                ->limit(max(1, min(20, $limit)))
                ->get()
                ->map(fn (WebFinding $row) => $row->toItem())
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * NGUỒN GẦN ĐÂY của một tài khoản — cho giao diện Agent Studio (khối "AI đã tra được gì").
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?User $user, string $region = 'all', int $limit = 20, bool $savedOnly = false): array
    {
        if ($user === null) {
            return [];
        }

        try {
            $query = WebFinding::query()
                ->where('user_id', $user->id)
                ->where('last_seen_at', '>=', now()->subDays(self::KEEP_DAYS));

            // 'all' = mọi vùng; vùng cụ thể = nguồn của vùng đó HOẶC nguồn dùng chung.
            if (trim($region) !== '' && $region !== 'all') {
                $query->whereIn('region', ['all', $region]);
            }
            if ($savedOnly) {
                $query->whereNotNull('saved_at');
            }

            return $query
                ->orderByRaw('saved_at IS NULL')
                ->orderByDesc('saved_at')
                ->orderByDesc('last_seen_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (WebFinding $row) => $row->toItem() + [
                    'id' => $row->id,
                    'hits' => (int) $row->hits,
                    'region' => (string) $row->region,
                    'first_seen_at' => optional($row->first_seen_at)->toISOString(),
                    'last_seen_at' => optional($row->last_seen_at)->toISOString(),
                    'saved_at' => optional($row->saved_at)->toISOString(),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * NGUỒN ĐÃ TÌM quay lại khối DỮ LIỆU của Agent Studio (mắt xích "về lại phục vụ Agent Studio").
     *
     * Hình dạng item GIỐNG HỆT nguồn ngoài (title · url · published_at · summary · source_name) và chỉ
     * THÊM dấu vết \`found_by\`/\`found_query\` — nhờ vậy prompt, bộ khử trùng và giao diện đều dùng lại
     * nguyên xi, không phải sinh một đường xử lý thứ hai (hai đường là hai chỗ để lệch nhau).
     *
     * @return list<array<string, mixed>>
     */
    public function evidenceItems(?User $user, string $region, int $limit = 6): array
    {
        return array_map(fn (array $row) => [
            'title' => (string) ($row['title'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'published_at' => $row['published_at'] ?? null,
            'summary' => (string) ($row['summary'] ?? ''),
            'source' => '',
            'source_name' => (string) ($row['source_name'] ?? ''),
            'found_by' => 'ai_search',
            'found_query' => (string) ($row['found_query'] ?? ''),
            'finding_id' => $row['finding_id'] ?? null,
            'saved' => (bool) ($row['saved'] ?? false),
        ], $this->recent($user, $region, $limit));
    }

    /**
     * NGƯỜI DÙNG lưu/bỏ lưu một nguồn — hành động duy nhất trong sổ mà máy KHÔNG được tự làm.
     *
     * @return array<string, mixed>|null  null = không có nguồn đó trong sổ CỦA NGƯỜI NÀY (không phải 404 mù)
     */
    public function markSaved(?User $user, int $id, bool $saved = true): ?array
    {
        if ($user === null) {
            return null;
        }

        try {
            // Truy vấn theo CẢ user_id: id của người khác phải là "không tìm thấy", không phải sửa được.
            $row = WebFinding::query()->where('user_id', $user->id)->find($id);
            if ($row === null) {
                return null;
            }

            $row->saved_at = $saved ? now() : null;
            $row->save();

            return ['id' => $row->id, 'saved' => $row->isSaved(), 'saved_at' => optional($row->saved_at)->toISOString()];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Bỏ một nguồn khỏi sổ (người dùng thấy nguồn rác). Trả về true khi CÓ hàng bị xoá. */
    public function forget(?User $user, int $id): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            return WebFinding::query()->where('user_id', $user->id)->whereKey($id)->delete() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * SỐ ĐO của sổ cho một tài khoản — giao diện đọc để nói thật "đã lưu bao nhiêu nguồn".
     *
     * @return array{total:int, saved:int, fresh:int}
     */
    public function stats(?User $user): array
    {
        $empty = ['total' => 0, 'saved' => 0, 'fresh' => 0];

        if ($user === null) {
            return $empty;
        }

        try {
            $base = fn () => WebFinding::query()->where('user_id', $user->id);

            return [
                'total' => (int) $base()->count(),
                'saved' => (int) $base()->whereNotNull('saved_at')->count(),
                'fresh' => (int) $base()->where('last_seen_at', '>=', now()->subDays(self::KEEP_DAYS))->count(),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** Ngày công bố về Carbon; chuỗi rác ⇒ null (KHÔNG ném — đây là dữ liệu người ngoài viết). */
    private function moment(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
