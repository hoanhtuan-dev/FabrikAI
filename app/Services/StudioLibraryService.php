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
            $q = trim((string) $filters['q']);
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
    public function uploadedFiles(): array
    {
        $referenced = $this->referencedPaths();
        $dirs = ['studio/ref', 'studio/assets'];
        $items = [];

        foreach ($dirs as $dir) {
            $abs = Storage::disk('public')->path($dir);
            if (! is_dir($abs)) {
                continue;
            }
            $files = glob($abs.'/*.{png,jpg,jpeg,webp,gif}', GLOB_BRACE) ?: [];
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
                    'used' => isset($referenced[$rel]),
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
    public function deleteUploadedFiles(array $rels): array
    {
        $referenced = $this->referencedPaths();
        $deleted = 0;
        $freed = 0;

        foreach ($rels as $rel) {
            $rel = $this->normalizeUploadRel((string) $rel);
            if ($rel === '' || isset($referenced[$rel])) {
                continue; // bỏ qua file đang được dùng / đường dẫn không hợp lệ
            }
            $abs = Storage::disk('public')->path($rel);
            if (! is_file($abs)) {
                continue;
            }
            $size = (int) filesize($abs);
            if ($this->safeUnlink($abs)) {
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
        $data = $this->uploadedFiles();
        $unused = array_values(array_filter($data['items'], fn ($i) => ! $i['used']));

        return $this->deleteUploadedFiles(array_column($unused, 'rel'));
    }

    /**
     * Chuẩn hoá + giới hạn đường dẫn tải lên về studio/ref hoặc studio/assets (chống traversal).
     */
    private function normalizeUploadRel(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
        $rel = preg_replace('#^(storage/)+#', '', $rel) ?? $rel;
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
    private function scanOrphanFiles(): array
    {
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
        $root = rtrim(str_replace('\\', '/', Storage::disk('public')->path('')), '/').'/';
        $normalized = str_replace('\\', '/', $path);
        if (! str_starts_with($normalized, $root) || ! is_file($path)) {
            return false;
        }

        return @unlink($path);
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
