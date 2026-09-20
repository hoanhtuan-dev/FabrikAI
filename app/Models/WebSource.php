<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một NGUỒN dữ liệu ngoài (RSS/JSON/API) mà máy chủ sẽ tự đi lấy rồi đưa vào lời nhắc của agent.
 *
 * Xem WebSourceService để biết cách lấy/lọc/đệm, và docs/DESIGN_SYSTEM.md §19.
 * Đây là cấu hình TOÀN CỤC (quản trị viên khai), không phải dữ liệu của từng người dùng.
 */
class WebSource extends Model
{
    protected $table = 'web_sources';

    /**
     * Ba KIỂU nguồn:
     *   · rss    — feed tin (RSS/Atom);
     *   · json   — API trả JSON, khai ánh xạ trường qua items_path/*_field;
     *   · search — API TÌM KIẾM theo TỪ KHOÁ (2026-09-21): URL có chỗ điền `{query}`, và khoá API
     *              KHÔNG nằm trong URL — nó đọc từ bảng API key (provider = slug của nguồn) lúc gọi.
     *
     * Vì sao cần kiểu thứ ba: đo thật — Google News RSS (kiểu rss) CHỈ có tin tức, nên câu hỏi tra cứu
     * thường ("cách giặt vải linen") và câu hỏi thương mại ("xưởng may gia công ở Tân Bình") đều trả về
     * **0 kết quả**. Muốn tra được WEB CHUNG thì phải gọi một API tìm kiếm thật (vd Google Custom Search
     * JSON API), và API đó cần khoá.
     */
    public const KINDS = ['rss', 'json', 'search'];

    protected $fillable = [
        'slug', 'name', 'url', 'kind', 'enabled', 'priority', 'keywords', 'region', 'max_items',
        'items_path', 'title_field', 'link_field', 'date_field', 'summary_field', 'note',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'priority' => 'integer',
        'max_items' => 'integer',
    ];

    /**
     * Bind route theo SLUG (không phải id số): slug là khoá người dùng thấy trên giao diện và trong log,
     * nên URL cấu hình đọc được bằng mắt (/api/admin/web-sources/tuoi-tre-thoi-trang).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Danh sách từ khoá đã tách (rỗng = không lọc). */
    public function keywordList(): array
    {
        return array_values(array_filter(array_map(
            fn (string $word) => trim(mb_strtolower($word)),
            explode(',', (string) $this->keywords),
        ), 'strlen'));
    }

    /** Nguồn này có dùng cho vùng đang xét không? (rỗng = mọi vùng) */
    public function matchesRegion(string $region): bool
    {
        $own = trim((string) $this->region);

        return $own === '' || $own === $region;
    }
}
