<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

/**
 * StudioLibraryService — quản lý Thư viện ảnh/video đã tạo trong Studio.
 *
 * Cung cấp: danh sách có lọc + phân trang, quét ảnh rác / ảnh cũ / file mồ côi,
 * xóa hàng loạt và dọn file vật lý (không chỉ xóa bản ghi DB).
 */
class StudioLibraryService
{
    /**
     * Thư mục chứa output được tạo ra nằm TRỰC TIẾP trong studio/ (không phải subdir).
     * Các subdir (faces, poses, assets, khuon-mat, dang-nguoi-mau) là tài nguyên quản lý riêng.
     */
    private const OUTPUT_DIRS = ['studio', 'studio/ref'];

    /**
     * Số phút tối thiểu một file phải "đứng im" trước khi được coi là mồ côi —
     * tránh xóa nhầm file đang được job render ghi/ghi xong nhưng DB chưa cập nhật.
     */
    private const ORPHAN_GRACE_MINUTES = 10;

    /**
     * Lọc + phân trang danh sách generation của người dùng, kèm thống kê đếm nhanh.
     */
    public function list(User $user, array $filters = []): array
    {
        $query = $user->generations()->with('project');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['project_id'])) {
            // 'none' = chỉ ảnh CHƯA gắn dự án; số id = lọc theo dự án đó.
            if ((string) $filters['project_id'] === 'none') {
                $query->whereNull('project_id');
            } else {
                $query->where('project_id', (int) $filters['project_id']);
            }
        }
        if (! empty($filters['q'])) {
            // M16: escape ký tự đại diện của LIKE — trước đây '%'/'_' trong ô tìm kiếm được hiểu
            // là wildcard (gõ '%' khớp mọi bản ghi; gõ '_' khớp mọi ký tự).
            // MySQL (production) dùng '\' làm escape mặc định cho LIKE nên cách này đúng ở đó;
            // SQLite (test/local) không có escape mặc định — chỉ khác biệt khi gõ đúng '%'/'_'.
            $q = addcslashes(trim((string) $filters['q']), '%_\\');
            $query->where(function ($sub) use ($q) {
                $sub->where('prompt', 'like', '%'.$q.'%')
                    ->orWhere('model', 'like', '%'.$q.'%')
                    ->orWhere('provider', 'like', '%'.$q.'%');
            });
        }

        $sort = (string) ($filters['sort'] ?? 'newest');
        switch ($sort) {
            case 'oldest':
                $query->oldest();
                break;
            case 'name_asc':
                $query->orderBy('prompt', 'asc')->orderBy('created_at', 'desc');
                break;
            case 'name_desc':
                $query->orderBy('prompt', 'desc')->orderBy('created_at', 'desc');
                break;
            case 'cost_desc':
                $query->orderBy('credits_cost', 'desc')->orderBy('created_at', 'desc');
                break;
            case 'cost_asc':
                $query->orderBy('credits_cost', 'asc')->orderBy('created_at', 'desc');
                break;
            default:
                $query->latest();
        }

        $perPage = max(12, min(100, (int) ($filters['per_page'] ?? 48)));
        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(fn (Generation $g) => $this->serialize($g))->values();

        return [
            'items' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'has_more' => $paginator->hasMorePages(),
            'stats' => $this->counts($user, (int) ($filters['old_days'] ?? 30)),
        ];
    }

    /**
     * Đếm nhanh theo trạng thái + ảnh rác / ảnh cũ / file mồ côi (chỉ đếm, không quét byte).
     */
    public function counts(User $user, int $oldDays = 30): array
    {
        $base = $user->generations();
        $counts = [
            'total' => (clone $base)->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'processing' => (clone $base)->where('status', 'processing')->count(),
        ];
        $counts['junk_count'] = $counts['failed'] + $counts['cancelled'];
        $counts['project_linked_count'] = (clone $base)->whereNotNull('project_id')->count();
        $counts['old_count'] = $user->generations()
            ->where('status', 'completed')
            ->whereNotNull('media_url')
            ->where('created_at', '<', now()->subDays($oldDays))
            ->count();
        $counts['orphan_count'] = count($this->scanOrphanFiles());

        return $counts;
    }

    /**
     * Quét chi tiết (có byte + danh sách id/file) cho 3 nhóm: ảnh rác, ảnh cũ, file mồ côi.
     */
    public function scan(User $user, int $oldDays = 30): array
    {
        return [
            'junk' => $this->junkCategory($user),
            'old' => $this->oldCategory($user, $oldDays),
            'orphans' => $this->orphanCategory(),
        ];
    }

    /**
     * Ảnh rác = generation thất bại / đã hủy (không tạo ra output dùng được).
     */
    private function junkCategory(User $user): array
    {
        $rows = $user->generations()
            ->whereIn('status', ['failed', 'cancelled'])
            ->get();

        return [
            'ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'count' => $rows->count(),
            'bytes' => $this->sumMediaBytes($rows),
        ];
    }

    /**
     * Ảnh cũ = generation hoàn tất có output và đã quá `$oldDays` ngày.
     */
    private function oldCategory(User $user, int $oldDays): array
    {
        $rows = $user->generations()
            ->where('status', 'completed')
            ->whereNotNull('media_url')
            ->where('created_at', '<', now()->subDays($oldDays))
            ->get();

        return [
            'ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'count' => $rows->count(),
            'bytes' => $this->sumMediaBytes($rows),
        ];
    }

    /**
     * File mồ côi = file trên đĩa (output studio/ và studio/ref/) không được bất kỳ
     * generation / asset / preset nào tham chiếu nữa.
     */
    private function orphanCategory(): array
    {
        $files = $this->scanOrphanFiles();

        return [
            'files' => $files,
            'count' => count($files),
            'bytes' => array_sum(array_column($files, 'size')),
        ];
    }

    /**
     * Xóa hàng loạt generation + file media vật lý. Trả về số đã xóa + byte giải phóng.
     */
    public function bulkDelete(User $user, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return ['deleted' => 0, 'freed_bytes' => 0];
        }

        $rows = $user->generations()->whereIn('id', $ids)->get();
        $freed = $this->sumMediaBytes($rows);

        foreach ($rows as $gen) {
            $this->deleteGenerationFiles($gen);
            $gen->delete();
        }

        return ['deleted' => $rows->count(), 'freed_bytes' => $freed];
    }

    /**
     * Dọn dẹp theo phạm vi: 'orphans' (file mồ côi), 'junk' (ảnh rác), 'old' (ảnh cũ).
     */
    public function cleanup(User $user, string $scope, int $oldDays = 30): array
    {
        return match ($scope) {
            'orphans' => $this->cleanupOrphans(),
            'junk' => $this->bulkDelete($user, $this->junkCategory($user)['ids']),
            'old' => $this->bulkDelete($user, $this->oldCategory($user, $oldDays)['ids']),
            default => ['deleted' => 0, 'freed_bytes' => 0],
        };
    }

    /**
     * Danh sách file ĐÃ TẢI LÊN (ảnh nguồn studio/ref + tài nguyên tự thêm studio/assets),
     * kèm trạng thái "đang dùng" và thống kê file mồ côi (không dùng).
     */
    public function uploadedFiles(?\App\Models\User $user = null): array
    {
        $referenced = $this->referencedPaths();

        // [Đợt 0.1b] `studio/ref` nay tách theo user: người dùng chỉ thấy thư mục của MÌNH
        // (+ kho phẳng cũ để không mất ảnh cũ); owner (hoặc $user = null) thấy TẤT CẢ.
        $isAdmin = $user === null || (bool) $user->isAdmin();
        $refBase = Storage::disk('public')->path('studio/ref');
        $refDirs = $isAdmin
            ? array_merge(glob($refBase.'/u*', GLOB_ONLYDIR) ?: [], [$refBase])
            : [$refBase.'/u'.$user->id, $refBase];

        $files = [];
        foreach ($refDirs as $dir) {
            if (is_dir($dir)) {
                $files = array_merge($files, glob($dir.'/*.{png,jpg,jpeg,webp,gif}', GLOB_BRACE) ?: []);
            }
        }

        // `studio/assets` là tài nguyên DÙNG CHUNG (không có user_id) nên ai cũng thấy.
        $assetBase = Storage::disk('public')->path('studio/assets');
        if (is_dir($assetBase)) {
            $files = array_merge($files, glob($assetBase.'/*.{png,jpg,jpeg,webp,gif}', GLOB_BRACE) ?: []);
        }

        // Bộ sưu tập mà từng ảnh đang thuộc về (nếu có). Ảnh tải lên không có dòng trong CSDL nên
        // quan hệ này nằm ở bảng riêng upload_project_links — xem migration 2026_09_20_000002.
        $links = \App\Models\UploadProjectLink::mapForUser($user?->id);

        $items = [];

        {
            foreach ($files as $file) {
                if (! is_file($file)) {
                    continue;
                }
                $rel = $this->pathToRelative($file);
                if ($rel === '') {
                    continue;
                }
                $dims = @getimagesize($file);
                $items[] = [
                    'rel' => $rel,
                    'name' => basename($file),
                    'url' => '/storage/'.$rel,
                    'kind' => str_contains($rel, 'studio/assets/') ? 'asset' : 'ref',
                    'size' => (int) filesize($file),
                    'mtime' => (int) filemtime($file),
                    // [P0.1] Ảnh thuộc bộ sưu tập cũng là "đang dùng" — nhãn này điều khiển cả ô
                    // "Dọn file mồ côi" lẫn nút xoá, nên nếu nói sai thì người dùng xoá mất ảnh gốc.
                    'used' => isset($referenced[$rel]) || isset($links[$rel]),
                    'project_id' => $links[$rel] ?? null,
                    'width' => $dims[0] ?? 0,
                    'height' => $dims[1] ?? 0,
                ];
            }
        }

        usort($items, fn ($a, $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));

        $unused = array_values(array_filter($items, fn ($i) => ! $i['used']));

        return [
            'items' => array_values($items),
            'stats' => [
                'total' => count($items),
                'total_bytes' => array_sum(array_column($items, 'size')),
                'unused_count' => count($unused),
                'unused_bytes' => array_sum(array_column($unused, 'size')),
            ],
        ];
    }

    /**
     * Xóa hàng loạt file đã tải lên (chỉ cho phép xóa file KHÔNG còn được dùng).
     */
    /**
     * Đường dẫn ảnh tải lên HỢP LỆ và THUỘC VỀ người dùng này — dùng cho thao tác GHI.
     *
     * Trả '' nếu không hợp lệ hoặc không có quyền; nơi gọi tự quyết định cách báo lỗi. Dùng lại
     * đúng hai lớp đã có của đường XOÁ (normalizeUploadRel chặn leo thư mục,
     * studio_upload_visible_to chặn thao tác lên ảnh của người khác) để hai đường không lệch nhau.
     */
    public function ownedUploadRel(\App\Models\User $user, string $rel): string
    {
        $rel = $this->normalizeUploadRel($rel);
        if ($rel === '') {
            return '';
        }

        return studio_upload_visible_to($rel, $user->id, (bool) $user->isAdmin()) ? $rel : '';
    }

    public function deleteUploadedFiles(array $rels, ?\App\Models\User $user = null): array
    {
        $referenced = $this->referencedPaths();
        $deleted = 0;
        $freed = 0;
        $isAdmin = $user === null || (bool) $user->isAdmin();

        foreach ($rels as $rel) {
            $rel = $this->normalizeUploadRel((string) $rel);
            if ($rel === '' || isset($referenced[$rel])) {
                continue; // bỏ qua file đang được dùng / đường dẫn không hợp lệ
            }
            // [Đợt 0.1b] Không được xoá ảnh của user khác; owner thì được.
            if (! studio_upload_visible_to($rel, $user?->id, $isAdmin)) {
                continue;
            }
            $abs = Storage::disk('public')->path($rel);
            if (! is_file($abs)) {
                continue;
            }
            $size = (int) filesize($abs);
            if ($this->safeUnlink($abs)) {
                // File không còn ⇒ liên kết bộ sưu tập cũng hết nghĩa; giữ lại chỉ tạo dòng mồ côi
                // trỏ tới đường dẫn không tồn tại.
                \App\Models\UploadProjectLink::where('rel', $rel)->delete();
                $deleted++;
                $freed += $size;
            }
        }

        return ['deleted' => $deleted, 'freed_bytes' => $freed];
    }

    /**
     * Dọn toàn bộ file đã tải lên không còn được dùng (file mồ côi).
     */
    public function cleanupUploadedOrphans(): array
    {
        $data = $this->uploadedFiles(); // dọn mồ côi là việc TOÀN CỤC (route ADMIN) ⇒ quét tất cả
        $unused = array_values(array_filter($data['items'], fn ($i) => ! $i['used']));

        return $this->deleteUploadedFiles(array_column($unused, 'rel'));
    }

    /**
     * [P1.3 — 2026-09-20] ẢNH TẢI LÊN đang thuộc MỘT bộ sưu tập — đường ĐỌC còn thiếu.
     *
     * Vì sao cần: `POST /api/projects/{id}/uploads` đã ghi liên kết từ lâu, nhưng không có đường
     * đọc nào phía bộ sưu tập (serialize/export/trang chia sẻ đều chỉ lấy generations) ⇒ người dùng
     * gắn ảnh gốc của cả bộ vào bộ sưu tập rồi... không thấy nó ở đâu nữa. Đây là dead-end đúng
     * nghĩa: thao tác thành công nhưng không có cách nào kiểm chứng ngoài việc quay lại Thư viện.
     *
     * KHÔNG quét đĩa (khác uploadedFiles()): chỉ đọc bảng liên kết nên rẻ, gọi được trong serialize().
     *
     * @return array<int, array{rel:string,name:string,url:string,kind:string}>
     */
    public function uploadsForProject(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }

        return \App\Models\UploadProjectLink::query()
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->get()
            ->map(function (\App\Models\UploadProjectLink $link): array {
                $rel = (string) $link->rel;

                return [
                    'rel' => $rel,
                    'name' => basename($rel),
                    'url' => '/storage/'.$rel,
                    'kind' => str_contains($rel, 'studio/assets/') ? 'asset' : 'ref',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Chuẩn hoá + giới hạn đường dẫn tải lên về studio/ref hoặc studio/assets (chống traversal).
     */
    private function normalizeUploadRel(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
        $rel = preg_replace('#^(storage/)+#', '', $rel) ?? $rel;

        // [BẢO MẬT] Chặn leo thư mục NGAY TẠI ĐÂY: bất kỳ segment '..' nào cũng bị từ chối.
        // Trước đây hàm chỉ kiểm TIỀN TỐ ('studio/ref/' | 'studio/assets/') nên
        // 'studio/ref/../../../<file>' vẫn lọt qua toàn bộ các lớp còn lại và bị unlink.
        if (in_array('..', explode('/', $rel), true)) {
            return '';
        }

        if (str_starts_with($rel, 'studio/ref/') || str_starts_with($rel, 'studio/assets/')) {
            return $rel;
        }

        return '';
    }

    private function cleanupOrphans(): array
    {
        $files = $this->scanOrphanFiles();
        $freed = 0;
        foreach ($files as $file) {
            if ($this->safeUnlink($file['path'])) {
                $freed += (int) ($file['size'] ?? 0);
            }
        }

        return ['deleted' => count($files), 'freed_bytes' => $freed];
    }

    /**
     * Xóa các file media của MỘT generation: chỉ output riêng (media_url).
     * base_image / mask_image có thể là nguồn dùng chung (ref/ảnh khác) → để lại,
     * chúng sẽ được dọn bởi luồng "file mồ côi" khi không còn ai tham chiếu.
     */
    public function deleteGenerationFiles(Generation $gen): void
    {
        if ($gen->media_url) {
            $this->deleteUrl($gen->media_url);
        }
    }

    /**
     * Tổng kích thước media của một tập generation (chỉ media_url).
     */
    private function sumMediaBytes($rows): int
    {
        $total = 0;
        foreach ($rows as $gen) {
            $path = $this->urlToPath((string) ($gen->media_url ?? ''));
            if ($path && is_file($path)) {
                $total += (int) filesize($path);
            }
        }

        return $total;
    }

    /**
     * Quét file mồ côi trong các thư mục output. Được gọi ở chế độ admin — tham chiếu
     * được gom từ TẤT CẢ người dùng để không xóa nhầm file của người khác.
     */
    // [R5 — 2026-09-20] B16: quét toàn bộ thư mục output là đắt (glob + filemtime trên hàng nghìn file).
    // Lưu kết quả 5 phút trong cache — đủ cho stats sidebar, người dùng vẫn thấy "Dọn file mồ côi"
    // hoạt động; chỉ là con số được làm mới 5 phút/lần.
    private function scanOrphanFiles(): array
    {
        $key = 'studio:orphan_scan:all:v1';

        return cache()->remember($key, 300, function () {
            $referenced = $this->referencedPaths();
            $files = [];
            $grace = now()->subMinutes(self::ORPHAN_GRACE_MINUTES)->getTimestamp();

            foreach (self::OUTPUT_DIRS as $dir) {
                $abs = Storage::disk('public')->path($dir);
                if (! is_dir($abs)) {
                    continue;
                }
                $found = glob($abs.'/*.{png,jpg,jpeg,webp,gif,mp4,webm,mov}', GLOB_BRACE) ?: [];
                foreach ($found as $file) {
                    if (! is_file($file)) {
                        continue;
                    }
                    $mtime = (int) filemtime($file);
                    if ($mtime >= $grace) {
                        continue; // file mới — có thể đang được render
                    }
                    $rel = $this->pathToRelative($file);
                    if ($rel === '' || isset($referenced[$rel])) {
                        continue;
                    }
                    $files[] = [
                        'path' => $file,
                        'rel' => $rel,
                        'name' => basename($file),
                        'size' => (int) filesize($file),
                        'mtime' => $mtime,
                    ];
                }
            }

            // Sắp xếp theo thời gian cũ → mới để dễ nhận diện.
            usort($files, fn ($a, $b) => ($a['mtime'] ?? 0) <=> ($b['mtime'] ?? 0));

            return $files;
        });
    }

    /**
     * Tập hợp các đường dẫn tương đối (dạng "studio/x.jpg") đang được tham chiếu.
     */
    private function referencedPaths(): array
    {
        $set = [];

        $collect = function ($url) use (&$set) {
            $rel = $this->urlToRelative((string) $url);
            if ($rel !== '') {
                $set[$rel] = true;
            }
        };

        foreach (Generation::query()->cursor() as $g) {
            $collect($g->media_url);
            $collect($g->base_image);
            $collect($g->mask_image);
            $meta = is_array($g->meta) ? $g->meta : [];
            foreach ((array) ($meta['ref_images'] ?? []) as $ref) {
                $collect($ref);
            }
            $collect($meta['face_ref'] ?? null);
        }

        // [P0.1 — 2026-09-20] ẢNH TẢI LÊN ĐANG THUỘC MỘT BỘ SƯU TẬP LÀ ẢNH ĐANG ĐƯỢC DÙNG.
        // Trước khi thêm dòng này, tập tham chiếu chỉ gom từ bảng generations/studio_assets/
        // face_presets/pose_presets — bảng upload_project_links (liên kết file ↔ bộ sưu tập) bị bỏ
        // sót. Hệ quả ĐO ĐƯỢC: ảnh vừa gắn vào bộ sưu tập vẫn bị liệt kê là "chưa dùng", hiện trong
        // ô "Dọn file mồ côi", và nút dọn XOÁ THẬT file trên đĩa + xoá luôn liên kết bộ sưu tập
        // (bộ sưu tập mất ảnh tham chiếu mà không có cách nào lấy lại).
        foreach (\App\Models\UploadProjectLink::query()->cursor() as $link) {
            $collect($link->rel);
        }

        foreach (\App\Models\StudioAsset::query()->cursor() as $a) {
            $collect($a->path);
        }
        foreach (\App\Models\FacePreset::query()->cursor() as $f) {
            $collect($f->image);
        }
        foreach (\App\Models\PosePreset::query()->cursor() as $p) {
            $collect($p->image);
        }

        return $set;
    }

    /**
     * Chuyển URL kiểu "/storage/studio/x.jpg" thành đường dẫn tuyệt đối trên đĩa.
     */
    public function urlToPath(string $url): ?string
    {
        $rel = $this->urlToRelative($url);
        if ($rel === '') {
            return null;
        }
        $path = Storage::disk('public')->path($rel);

        return $path;
    }

    /**
     * Chuẩn hoá URL/đường dẫn thành đường dẫn tương đối "studio/x.jpg" (không có tiền tố).
     */
    private function urlToRelative(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return '';
        }
        // Bỏ query string nếu có.
        $url = (string) parse_url($url, PHP_URL_PATH);
        $rel = ltrim(str_replace('\\', '/', $url), '/');
        $rel = preg_replace('#^(storage/)+#', '', $rel) ?? $rel;
        // Chỉ chấp nhận đường dẫn nằm trong studio/.
        if (! str_starts_with($rel, 'studio/')) {
            return '';
        }

        return $rel;
    }

    /**
     * Chuyển đường dẫn tuyệt đối trên đĩa về đường dẫn tương đối (chuẩn hoá dấu phân cách).
     */
    private function pathToRelative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', Storage::disk('public')->path('')), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : '';
    }

    /**
     * Xóa file vật lý theo URL, có chặn path traversal (chỉ cho phép nằm trong storage/app/public).
     */
    private function deleteUrl(string $url): void
    {
        $path = $this->urlToPath($url);
        if ($path) {
            $this->safeUnlink($path);
        }
    }

    /**
     * Xóa file với kiểm tra an toàn: chỉ xóa file nằm trong storage/app/public.
     */
    private function safeUnlink(string $path): bool
    {
        // [BẢO MẬT] So trên ĐƯỜNG DẪN ĐÃ RESOLVE (realpath gỡ hết '..' và symlink) thay vì so CHUỖI.
        // Bản cũ so chuỗi thô nên '<root>/studio/ref/../../../x' vẫn "bắt đầu bằng root" và lọt,
        // trong khi is_file()/unlink() lại resolve '..' ra file NGOÀI root -> xoá file tuỳ ý.
        // Đây là lớp phòng thủ thứ 2: kể cả caller tương lai truyền đường dẫn traversal thì unlink
        // vẫn không thể ra ngoài root.
        // [BẢO MẬT] So trên ĐƯỜNG DẪN ĐÃ RESOLVE (realpath gỡ hết '..' và symlink) thay vì so CHUỖI.
        // Bản cũ so chuỗi thô nên '<root>/studio/ref/../../../x' vẫn "bắt đầu bằng root" và lọt,
        // trong khi is_file()/unlink() lại resolve '..' ra file NGOÀI root -> xoá file tuỳ ý.
        // Đây là lớp phòng thủ thứ 2: kể cả caller tương lai truyền đường dẫn traversal thì unlink
        // vẫn không thể ra ngoài root.
        $root = realpath(Storage::disk('public')->path(''));
        $real = realpath($path);

        if ($root === false || $real === false || ! is_file($real)) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        if (! str_starts_with(str_replace('\\', '/', $real), $root)) {
            return false;
        }

        return @unlink($real);
    }

    /**
     * Serialize một generation về shape frontend quen thuộc (khớp với /studio/latest).
     */
    private function serialize(Generation $g): array
    {
        return [
            'id' => $g->id,
            'type' => $g->type,
            'status' => $g->status,
            'model' => $g->model,
            'provider' => $g->provider,
            'media_url' => $g->media_url,
            'error' => $g->error,
            'credits_cost' => $g->credits_cost,
            'project_id' => $g->project_id,
            'project' => $g->project?->name,
            'prompt' => $g->prompt,
            'created_at' => $g->created_at?->format('d/m/Y H:i'),
            'created_at_iso' => $g->created_at?->toIso8601String(),
            'created_ts' => $g->created_at?->getTimestamp(),
            'resolution' => $g->resolution,
            'ratio' => $g->ratio,
            'duration' => $g->duration,
            'elapsed_ms' => $g->elapsed_ms,
            'seed' => is_array($g->meta) ? ($g->meta['seed'] ?? null) : null,
            'meta' => $g->meta,
        ];
    }
}
