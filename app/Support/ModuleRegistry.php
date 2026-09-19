<?php

namespace App\Support;

/**
 * MODULE REGISTRY — MỘT NGUỒN DUY NHẤT cho mọi tính năng của FabrikAI (2026-09-19).
 *
 * Vì sao cần: tính năng của Studio nằm rải ở 5 nơi — nhãn/icon ở `StudioGuiConfig::DEFAULTS`, id→card ở
 * `ACTIVITY_CARDS` (StudioApp.vue), endpoint ở routes, quyền ở từng controller, và "gói nào có gì" thì
 * viết tay ở trang giá. Thêm một tính năng phải sửa đủ 5 chỗ, và quên một chỗ thì khách thấy nút mà bấm
 * vào không được (hoặc tệ hơn: gói rẻ vẫn gọi được API của gói đắt).
 *
 * Nay: KHAI MỘT LẦN ở đây. Từ bản khai này mà suy ra:
 *   · thanh công cụ Studio (mục nào hiện — kết hợp với cấu hình trình bày của owner),
 *   · công tắc BẬT/TẮT toàn cục từng module (chủ dự án tắt một tính năng mà không ảnh hưởng tính năng khác),
 *   · quyền theo GÓI: `plans.modules` liệt kê module gói đó cấp cho khách ⇒ gói chính là công tắc cấp
 *     phát tính năng (xem `Plan::modules()` và `module_allowed()`),
 *   · màn Quản trị (danh sách module + gói nào cấp module nào) và trang giá (mỗi gói được gì),
 *   · THỰC THI ở backend bằng middleware `module:<id>` — ẩn nút trên UI không phải là phân quyền.
 *
 * Mở rộng quy mô = thêm MỘT mục vào `MODULES` (+ card/route của nó). Test `ModuleRegistryTest` giữ cho
 * bản khai không lệch với GUI/route/gói, nên thêm module mới là mọi bề mặt tự cập nhật.
 *
 * Quy ước trường:
 *   id           khoá kỹ thuật, chữ thường + gạch dưới. Nếu `gui = true` thì PHẢI khớp id trong
 *                `StudioGuiConfig::DEFAULTS` (test đối chiếu cả hai chiều).
 *   kind         'panel' (nhóm card) · 'action' (popup) · 'menu' (menu cài đặt) · 'feature' (không có nút,
 *                nhưng vẫn là quyền cấp theo gói — vd xuất gói cho xưởng, chia sẻ cho khách).
 *   endpoints    tiền tố URI (không có 'api/') mà module phục vụ — dùng để gắn công tắc vào route và để
 *                test bắt lỗi "khai module mà không có endpoint nào" / "endpoint không thuộc module nào".
 *   depends_on   module phải có trước (vd 'batch' cần 'concept'); tắt module cha thì con cũng khoá.
 *   plans        ĐỀ XUẤT gói nào nên có module này (chủ dự án bấm "áp dụng đề xuất" trong Quản trị, hoặc
 *                tự tick từng gói). Cố ý KHÔNG dùng làm giá trị mặc định của `plans.modules`: hôm nay mọi
 *                gói đều dùng được mọi tính năng, nên nếu lấy đề xuất làm mặc định thì deploy xong là
 *                KHÁCH ĐANG TRẢ TIỀN MẤT TÍNH NĂNG. Mặc định = đủ module (giữ nguyên hành vi), chủ dự án
 *                siết dần bằng dữ liệu.
 */
class ModuleRegistry
{
    /** Setting (DB) chứa JSON danh sách module bị TẮT toàn cục: ["director","team_seats"]. */
    public const SETTING_DISABLED = 'studio_modules_disabled';

    public const KIND_PANEL = 'panel';
    public const KIND_ACTION = 'action';
    public const KIND_MENU = 'menu';
    public const KIND_FEATURE = 'feature';

    /**
     * BẢN KHAI DUY NHẤT. Thứ tự ở đây = thứ tự hiển thị gợi ý trong Quản trị/trang giá.
     * `plans` dùng slug gói; '*' = mọi gói đang mở bán.
     */
    public const MODULES = [
        // ── Không gian làm việc (có nút trên thanh công cụ) ────────────────────────────────
        [
            'id' => 'collections', 'name' => 'Bộ sưu tập', 'group' => 'Không gian làm việc',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'folderOpen',
            'summary' => 'Đang làm bộ nào · các bộ gần đây · việc đang chạy · tạo bộ sưu tập mới.',
            'endpoints' => ['projects'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'concept', 'name' => 'Tạo ảnh', 'group' => 'Tạo ảnh',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'sparkles',
            'summary' => 'Đọc ảnh mẫu để gợi ý prompt, phong cách và bối cảnh.',
            'endpoints' => ['suggest', 'suggest-library'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'variation', 'name' => 'Tạo biến thể ảnh', 'group' => 'Tạo ảnh',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'variations',
            'summary' => 'Nhiều biến thể từ một ảnh mẫu, giữ phom và chất liệu.',
            // Ảnh tham chiếu (kho ảnh mẫu dùng cho mọi biến thể) đi cùng module này.
            'endpoints' => ['refgen', 'ref-images', 'upload-ref'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'tryon', 'name' => 'Mặc thử đồ', 'group' => 'Tạo ảnh',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'hanger',
            'summary' => 'Người mẫu mặc đúng trang phục, giữ dáng và chất liệu.',
            'endpoints' => ['refgen', 'garment', 'swap-models', 'swap-poses', 'swap-backgrounds', 'outfit-settings'],
            'depends_on' => ['variation'],
            'plans' => ['starter', 'pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'inpaint', 'name' => 'Sửa ảnh', 'group' => 'Chỉnh ảnh',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'pencil',
            'summary' => 'Xoá/thay chi tiết trong ảnh bằng vùng chọn hoặc mô tả.',
            // Đường thật của Sửa ảnh: inpaint toàn ảnh · vùng chọn.
            // (Đường xóa nền đã gỡ cùng nút của nó trong bảng Lớp — xem ghi chú ở LayersPanel.vue.)
            'endpoints' => ['inpaint', 'generations/{generation}/inpaint', 'generations/{generation}/region'],
            'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'compose', 'name' => 'Ghép ảnh', 'group' => 'Chỉnh ảnh',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'layers',
            'summary' => 'Ghép nhiều ảnh thành một bố cục hoàn chỉnh.',
            // `layers` là nút "Lưu Output" của bảng Lớp (LayersPanel) — cùng không gian canvas với Ghép ảnh.
            'endpoints' => ['compose', 'layers'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            // [Yêu cầu 2026-09-22] TÁCH "Ghép trang phục" khỏi card "Ghép ảnh" thành card riêng.
            // Hai việc khác nhau về bản chất: Ghép ảnh = dựng BỐ CỤC (nền chính + ảnh ghép, không có
            // tham số thiết kế); Ghép trang phục = LAI TẠO BIẾN THỂ từ 2 trang phục nguồn, có phong
            // cách · mức trang trí · mức sáng tạo · preset theo tài khoản · biến thể theo trục.
            'id' => 'outfit', 'name' => 'Ghép trang phục', 'group' => 'Chỉnh ảnh',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'shirt',
            'summary' => 'Lai tạo biến thể trang phục mới từ 2 trang phục nguồn + bối cảnh (tùy chọn).',
            // Dùng CHUNG đường /compose và cài đặt /outfit-settings với card Ghép ảnh: cùng một
            // pipeline, hai cửa vào. Gói có MỘT trong hai module là gọi được (giống 'refgen' dùng
            // chung cho 'variation' + 'tryon').
            'endpoints' => ['compose', 'outfit-settings'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'upscale', 'name' => 'Upscale', 'group' => 'Chỉnh ảnh',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'maximize',
            'summary' => 'Nâng độ nét và kích thước ảnh để in.',
            'endpoints' => ['upscale'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'director', 'name' => 'Kịch bản quay', 'group' => 'Video',
            'kind' => self::KIND_PANEL, 'gui' => true, 'icon' => 'film',
            'summary' => 'Video catwalk ngắn từ ảnh đã có.',
            'endpoints' => ['video'], 'depends_on' => [],
            'plans' => ['starter', 'pro', 'studio', 'factory_season'],
        ],
        // ── Popup + menu ───────────────────────────────────────────────────────────────────
        [
            'id' => 'prompt', 'name' => 'Prompt Tạo Ảnh', 'group' => 'Nội dung',
            'kind' => self::KIND_ACTION, 'gui' => true, 'icon' => 'sparkles',
            'summary' => 'Thư viện prompt, tạo ảnh theo prompt, hàng loạt và mẫu việc theo ngành.',
            'endpoints' => ['generate', 'prompt-history', 'presets', 'user-catalogs', 'translate', 'preview-enrich'],
            'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            // [2026-09-20] Hợp nhất Trợ lý thiết kế + Canvas empty thành flow hai agent:
            // TrendRadar (radar xu hướng) → CollectionBot (brief/mood board/cấu trúc bộ).
            'id' => 'stylist', 'name' => 'Agent thiết kế', 'group' => 'Nội dung',
            'kind' => self::KIND_ACTION, 'gui' => true, 'icon' => 'bot',
            'summary' => 'TrendRadar phát hiện xu hướng; CollectionBot biến insight thành bộ sưu tập và prompt Canvas.',
            // 'user-catalogs' phục vụ CẢ /presets lẫn /stylist-data (bản tùy chỉnh của từng tài
            // khoản) ⇒ cả hai module đều là chủ sở hữu, đúng như 'refgen' dùng chung cho
            // 'variation' + 'tryon': gói có MỘT trong hai là dùng được.
            'endpoints' => ['stylist', 'stylist-data', 'user-catalogs'],
            'depends_on' => ['trend_radar', 'collection_bot'],
            'plans' => ['pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'trend_radar', 'name' => 'TrendRadar', 'group' => 'Nội dung',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'scan',
            'summary' => 'Radar xu hướng theo khu vực: màu sắc, dáng, chất liệu, giá và vòng đời.',
            'endpoints' => ['design-agent/radar'], 'depends_on' => [],
            'plans' => ['pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'collection_bot', 'name' => 'CollectionBot', 'group' => 'Nội dung',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'palette',
            'summary' => 'Brief, mood board, cấu trúc SKU, size và pricing từ prompt + TrendRadar.',
            'endpoints' => ['design-agent/collection', 'design-agent/plan', 'design-agent/shop-signals'], 'depends_on' => ['collections'],
            'plans' => ['pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'settings', 'name' => 'Cài đặt', 'group' => 'Hệ thống',
            'kind' => self::KIND_MENU, 'gui' => true, 'icon' => 'gear',
            'summary' => 'API key · provider · model · nhóm công việc (chỉ tài khoản quản trị).',
            'endpoints' => ['settings', 'settings-vue', 'keys', 'models'],
            'depends_on' => [],
            'plans' => ['*'], 'admin_only' => true,
        ],
        // ── Module không có nút riêng nhưng LÀ quyền cấp theo gói ──────────────────────────
        [
            'id' => 'batch', 'name' => 'Tạo ảnh hàng loạt', 'group' => 'Tạo ảnh',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'columns',
            'summary' => 'Một lượt ra ảnh cho cả bộ (nhiều SKU × nhiều biến thể), có chạy lại mục lỗi.',
            'endpoints' => [], 'depends_on' => ['prompt'],
            'plans' => ['starter', 'pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'job_templates', 'name' => 'Mẫu việc theo ngành', 'group' => 'Nội dung',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'template',
            'summary' => 'Lookbook · ảnh sàn TMĐT · mẫu kỹ thuật gửi xưởng · catalogue nhiều SKU.',
            'endpoints' => ['job-templates'], 'depends_on' => ['prompt'],
            'plans' => ['starter', 'pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'project_stats', 'name' => 'Chi phí & tiến độ', 'group' => 'Quản trị bộ sưu tập',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'activity',
            'summary' => 'Ảnh xong/đang chạy/lỗi · credit đã dùng · hạn còn lại · phản hồi của khách.',
            'endpoints' => ['projects/{project}/stats'], 'depends_on' => ['collections'],
            'plans' => ['starter', 'pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'project_export', 'name' => 'Xuất gói cho xưởng', 'group' => 'Quản trị bộ sưu tập',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'download',
            'summary' => 'ZIP: ảnh tham chiếu + phiếu kỹ thuật + bảng size + manifest.',
            'endpoints' => ['projects/{project}/export'], 'depends_on' => ['collections'],
            'plans' => ['pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'project_share', 'name' => 'Chia sẻ cho khách duyệt', 'group' => 'Quản trị bộ sưu tập',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'link',
            'summary' => 'Link công khai có hạn cho khách xem ảnh và gửi phản hồi (không cần tài khoản).',
            'endpoints' => ['projects/{project}/share'], 'depends_on' => ['collections'],
            'plans' => ['pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'shot_review', 'name' => 'Duyệt mẫu theo lô', 'group' => 'Quản trị bộ sưu tập',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'checkSquare',
            'summary' => 'Chốt/loại/đẩy bước cho nhiều ảnh trong một lượt, có phím tắt.',
            'endpoints' => ['projects/{project}/shots/review', 'generations/{generation}/shot-state'], 'depends_on' => ['collections'],
            'plans' => ['starter', 'pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'library', 'name' => 'Thư viện ảnh', 'group' => 'Quản trị bộ sưu tập',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'library',
            'summary' => 'Kho ảnh đã tạo: tìm, lọc, dọn rác, tải về.',
            // Quản lý ảnh đã tạo: danh sách, tải, xoá, đổi tên, huỷ, đổi màu chủ đạo.
            'endpoints' => ['library', 'latest', 'assets', 'uploads', 'generations'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'team_seats', 'name' => 'Nhóm làm việc (ghế)', 'group' => 'Đội nhóm',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'users',
            'summary' => 'Nhiều người dùng chung một gói: chung credit, chung bộ sưu tập.',
            'endpoints' => ['team'], 'depends_on' => [],
            'plans' => ['pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'face_pose_library', 'name' => 'Thư viện mặt & dáng', 'group' => 'Đội nhóm',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'user',
            'summary' => 'Lưu khuôn mặt người mẫu và dáng pose để tái dùng cho mọi bộ sưu tập.',
            'endpoints' => ['face-presets', 'pose-presets'], 'depends_on' => [],
            'plans' => ['pro', 'studio', 'factory_season'],
        ],
        [
            'id' => 'look', 'name' => 'Film Look / tone màu', 'group' => 'Chỉnh ảnh',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'palette',
            'summary' => 'Gán tone màu điện ảnh cho ảnh theo bộ sưu tập.',
            'endpoints' => ['look'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'reframe', 'name' => 'Cắt & mở rộng khung', 'group' => 'Chỉnh ảnh',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'crop',
            'summary' => 'Đổi tỉ lệ khung, mở rộng nền cho đúng chuẩn từng kênh bán.',
            'endpoints' => ['reframe'], 'depends_on' => [],
            'plans' => ['*'],
        ],
        [
            'id' => 'reimagine', 'name' => 'Tạo lại từ ảnh', 'group' => 'Tạo ảnh',
            'kind' => self::KIND_FEATURE, 'gui' => false, 'icon' => 'refresh',
            'summary' => 'Tạo phiên bản mới của một ảnh đã có (giữ bố cục, đổi chi tiết).',
            'endpoints' => ['reimagine'], 'depends_on' => [],
            'plans' => ['*'],
        ],
    ];

    /** Toàn bộ module (đã chuẩn hoá) — nguồn cho mọi bề mặt. */
    public static function all(): array
    {
        return self::MODULES;
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::MODULES, 'id');
    }

    public static function has(string $id): bool
    {
        return in_array($id, self::ids(), true);
    }

    public static function get(string $id): ?array
    {
        foreach (self::MODULES as $m) {
            if ($m['id'] === $id) {
                return self::normalize($m);
            }
        }

        return null;
    }

    /** Module có nút trên thanh công cụ Studio (panel/action/menu) — phải khớp StudioGuiConfig. */
    public static function guiIds(): array
    {
        return array_values(array_map(
            fn (array $m) => $m['id'],
            array_filter(self::MODULES, fn (array $m) => ! empty($m['gui']))
        ));
    }

    /** Module không có nút (quyền cấp theo gói, vd xuất gói cho xưởng). */
    public static function featureIds(): array
    {
        return array_values(array_map(
            fn (array $m) => $m['id'],
            array_filter(self::MODULES, fn (array $m) => empty($m['gui']))
        ));
    }

    /** Nhóm chức năng theo thứ tự khai báo (Quản trị + trang giá dùng để nhóm hiển thị). */
    public static function groups(): array
    {
        $out = [];
        foreach (self::MODULES as $m) {
            $out[$m['group']][] = $m['id'];
        }

        return $out;
    }

    /** Tên module theo id (fallback: chính id để không bao giờ hiện khoảng trắng). */
    public static function name(string $id): string
    {
        return self::get($id)['name'] ?? $id;
    }

    /** Tiền tố URI (không có 'api/') mà module phục vụ. */
    public static function endpoints(string $id): array
    {
        return self::get($id)['endpoints'] ?? [];
    }

    /**
     * Gói nào ĐƯỢC CẤP module này theo mặc định.
     * `'*'` = mọi gói (kể cả gói mở bán sau này) ⇒ thêm gói mới không phải sửa bản khai.
     */
    public static function defaultPlanSlugs(string $id): array
    {
        return self::get($id)['plans'] ?? [];
    }

    /** Id của MỌI module (mặc định khi gói chưa cấu hình ⇒ không lấy mất tính năng ai đang dùng). */
    public static function allIds(): array
    {
        return self::ids();
    }

    /** ĐỀ XUẤT: module mà một gói slug nên có (Quản trị hiện gợi ý này, chủ dự án quyết). */
    public static function suggestedForPlan(string $slug): array
    {
        $out = [];
        foreach (self::MODULES as $m) {
            $slugs = $m['plans'] ?? [];
            if (in_array('*', $slugs, true) || in_array($slug, $slugs, true)) {
                $out[] = $m['id'];
            }
        }

        return $out;
    }

    /** Module bị TẮT toàn cục (JSON trong settings) — chủ dự án bật/tắt không cần deploy. */
    public static function disabledGlobally(): array
    {
        $raw = setting(self::SETTING_DISABLED);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_intersect(array_map('strval', $decoded), self::ids())) : [];
    }

    /** Module đang BẬT toàn cục? */
    public static function enabledGlobally(string $id): bool
    {
        return self::has($id) && ! in_array($id, self::disabledGlobally(), true);
    }

    /**
     * Danh mục cho giao diện (Quản trị · trang giá · popup gói): chỉ dữ liệu hiển thị, KHÔNG chứa luật
     * cấp phép (luật nằm ở module_allowed() để chỉ có một chỗ quyết định).
     */
    public static function catalog(): array
    {
        return array_map(fn (array $m) => [
            'id' => $m['id'],
            'name' => $m['name'],
            'group' => $m['group'],
            'kind' => $m['kind'],
            'gui' => ! empty($m['gui']),
            'icon' => $m['icon'] ?? 'square',
            'summary' => $m['summary'] ?? '',
            'depends_on' => $m['depends_on'] ?? [],
            'default_plans' => $m['plans'] ?? [],
            'default_enabled' => self::enabledGlobally($m['id']),
        ], self::MODULES);
    }

    /**
     * Module nào phục vụ một URI (đã bỏ tiền tố 'api/').
     *
     * Khớp theo TIỀN TỐ DÀI NHẤT để endpoint cụ thể thắng module chung: `projects` (Bộ sưu tập) và
     * `projects/{project}/export` (Xuất gói cho xưởng) là hai quyền khác nhau, nên
     * `projects/12/export` phải rơi vào module xuất gói chứ không phải module bộ sưu tập.
     *
     * @return array<int, string> id các module có thể phục vụ URI này (rỗng = không module nào khai)
     */
    public static function modulesForUri(string $uri): array
    {
        $uri = trim($uri, '/');
        $best = -1;
        $out = [];

        foreach (self::MODULES as $m) {
            foreach (($m['endpoints'] ?? []) as $pattern) {
                $pattern = trim((string) $pattern, '/');
                if ($pattern === '' || ! self::uriMatches($pattern, $uri)) {
                    continue;
                }
                $len = strlen($pattern);
                if ($len > $best) {
                    $best = $len;
                    $out = [$m['id']];
                } elseif ($len === $best) {
                    $out[] = $m['id'];
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** URI có khớp pattern không? `{project}` khớp ĐÚNG một đoạn, pattern ngắn khớp cả cây con của nó. */
    protected static function uriMatches(string $pattern, string $uri): bool
    {
        if ($pattern === $uri) {
            return true;
        }

        // preg_quote() escape CẢ dấu mở và đóng của placeholder (`\{project\}`) nên phải thay cả cặp —
        // bản đầu chỉ thay dấu mở nên 'projects/{project}/export' không bao giờ khớp (bắt được bằng test).
        $quoted = preg_quote($pattern, '#');
        $regex = '#^'.preg_replace('/\\\{[A-Za-z_][A-Za-z0-9_]*\\\}/', '(?:[^/]+)', $quoted).'(?:/.*)?$#';

        return (bool) preg_match($regex, $uri);
    }

    /** Chuẩn hoá một mục khai báo (điền mặc định) để phần còn lại của hệ thống không phải kiểm null. */
    protected static function normalize(array $m): array
    {
        return array_merge([
            'group' => 'Khác',
            'kind' => self::KIND_FEATURE,
            'gui' => false,
            'icon' => 'square',
            'summary' => '',
            'endpoints' => [],
            'depends_on' => [],
            'plans' => [],
        ], $m);
    }
}
