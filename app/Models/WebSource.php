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
     * BỐN KIỂU nguồn:
     *   · rss    — feed tin (RSS/Atom);
     *   · json   — API trả JSON, khai ánh xạ trường qua items_path/*_field;
     *   · search — API TÌM KIẾM theo TỪ KHOÁ (2026-09-21): URL có chỗ điền `{query}`, và khoá API
     *              KHÔNG nằm trong URL — nó đọc từ bảng API key (provider = slug của nguồn) lúc gọi;
     *   · page   — ĐỊA CHỈ WEB BÌNH THƯỜNG (2026-09-26): một TRANG CHUYÊN MỤC (danh sách bài) không có
     *              RSS, hoặc TRANG TÌM KIẾM của chính website đó nếu URL có chỗ điền `{query}`
     *              (vd tuoitre.vn/tim-kiem.htm?keywords={query}) — KHÔNG cần API, KHÔNG cần khoá.
     *              Bộ đọc chỉ nhận liên kết TRÔNG NHƯ BÀI VIẾT (cùng tên miền, đường dẫn dài, tiêu đề đủ
     *              dài); đa số trang danh sách KHÔNG có ngày đăng nên item để published_at = null.
     *
     *   · tavily — API TÌM KIẾM WEB CHO AGENT của Tavily (2026-09-26): `POST https://api.tavily.com/search`,
     *              từ khoá đi trong BODY, tuỳ chọn cấu hình nằm ở query string của URL
     *              (`?topic=news&time_range=week&depth=basic`). Chạy được **KHÔNG CẦN KHOÁ** (chế độ
     *              keyless của Tavily, có giới hạn nhịp); có khoá thì dùng hạn mức riêng (miễn phí
     *              1.000 credit/tháng) — đổi qua lại KHÔNG phải sửa mã. Trả về cả **ngày đăng**, nên bộ lọc
     *              độ mới của dự án chạy được thật (RSS không có ngày, Google CSE cũng không trả).
     *
     * Vì sao cần kiểu TÌM KIẾM: đo thật trên production — Google News RSS (kiểu rss) CHỈ có tin tức, nên câu
     * hỏi tra cứu thường ("cách giặt vải linen") và câu hỏi thương mại ("giá vải linen") đều trả về **0 kết
     * quả** (đọc được 16-23 tin nhưng đều quá cũ). Muốn tra được WEB CHUNG thì phải gọi một API tìm kiếm thật.
     */
    public const KINDS = ['rss', 'json', 'search', 'page', 'tavily'];

    /**
     * Cột `items_path` mang HAI nghĩa tuỳ loại nguồn — nói rõ ở đây vì cùng một ô nhập trên màn Cài đặt:
     *   · json/search (API): ĐƯỜNG DẪN tới mảng kết quả trong JSON (vd `items`, `data.results`);
     *   · page (địa chỉ web thường): TIỀN TỐ ĐƯỜNG DẪN BẮT BUỘC của bài viết (vd `/thoi-trang-c13/`).
     *     Khai càng hẹp càng ít rác — đo thật: không khai thì bộ bóc lấy cả bài của mục khác (khối
     *     "đọc nhiều" toàn site) và nguồn báo "10 tin" trong khi nội dung sai.
     */
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
