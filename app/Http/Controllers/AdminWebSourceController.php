<?php

namespace App\Http\Controllers;

use App\Models\WebSource;
use App\Services\WebSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CÀI ĐẶT TRÌNH KẾT NỐI NGUỒN NGOÀI (Đợt 27 — 2026-09-23) — chỉ quản trị viên.
 *
 * Vì sao là API riêng chứ không phải file .env: yêu cầu thật là "CÀI ĐẶT ĐƯỢC trình kết nối: danh sách
 * nguồn (RSS/JSON/API)". Khai trong bảng + sửa trên giao diện nghĩa là thêm nguồn mới không phải sửa mã
 * và không phải chờ deploy.
 */
class AdminWebSourceController extends Controller
{
    /** GET /api/admin/web-sources — danh sách nguồn + tình trạng lấy tin gần nhất. */
    public function index(WebSourceService $service): JsonResponse
    {
        $sources = WebSource::query()->orderBy('priority')->orderBy('id')->get();
        $evidence = $service->evidence('all');
        $statuses = collect($evidence['sources'])->keyBy('slug');

        return response()->json([
            'sources' => $sources->map(fn (WebSource $s) => $this->map($s) + [
                // Trạng thái lấy tin ĐI KÈM mỗi nguồn: người cấu hình cần biết nguồn vừa thêm có chạy không.
                'status' => $statuses->get($s->slug),
            ])->values(),
            'kinds' => WebSource::KINDS,
            'evidence' => ['mode' => $evidence['mode'], 'limit' => WebSourceService::EVIDENCE_LIMIT],
        ]);
    }

    /** POST /api/admin/web-sources — thêm một nguồn. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        if (WebSource::query()->where('slug', $data['slug'])->exists()) {
            return response()->json(['message' => 'Slug đã tồn tại.'], 422);
        }

        $source = WebSource::create($this->fillable($data));

        return response()->json(['ok' => true, 'source' => $this->map($source)], 201);
    }

    /** PUT /api/admin/web-sources/{source} — sửa (slug giữ nguyên: nó là khoá tham chiếu trong log). */
    public function update(Request $request, WebSource $source): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $source->update($this->fillable($data));

        return response()->json(['ok' => true, 'source' => $this->map($source->fresh())]);
    }

    /** DELETE /api/admin/web-sources/{source} */
    public function destroy(WebSource $source): JsonResponse
    {
        $source->delete();

        return response()->json(['ok' => true]);
    }
    /**
     * POST /api/admin/web-sources/{source}/test — LẤY THỬ NGAY và trả về vài tin đầu.
     *
     * Có mặt vì cấu hình nguồn mà không thử được là cấu hình mù: người khai cần thấy ngay URL đúng chưa,
     * ánh xạ JSON có khớp không, bộ lọc từ khoá có ăn hết tin không.
     */
    public function test(WebSource $source, WebSourceService $service): JsonResponse
    {
        $fetched = $service->fetch($source, true);

        return response()->json([
            'source' => $this->map($source),
            'ok' => $fetched['ok'],
            'http' => $fetched['http'],
            'ms' => $fetched['ms'],
            'error' => $fetched['error'],
            'count' => count($fetched['items']),
            'items' => array_slice($fetched['items'], 0, 5),
        ]);
    }

    /** POST /api/admin/web-sources/seed — tạo các nguồn mặc định còn thiếu (không ghi đè nguồn đã có). */
    public function seed(WebSourceService $service): JsonResponse
    {
        return response()->json(['ok' => true, 'created' => $service->seedDefaults()]);
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', 'in:'.implode(',', WebSource::KINDS)],
            'enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'keywords' => ['nullable', 'string', 'max:300'],
            'region' => ['nullable', 'string', 'in:hcm,hanoi,danang'],
            'max_items' => ['nullable', 'integer', 'min:1', 'max:50'],
            'items_path' => ['nullable', 'string', 'max:120'],
            'title_field' => ['nullable', 'string', 'max:60'],
            'link_field' => ['nullable', 'string', 'max:60'],
            'date_field' => ['nullable', 'string', 'max:60'],
            'summary_field' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
        // Chỉ http/https: nguồn dữ liệu KHÔNG được là đường đọc file nội bộ (chống SSRF).
        $rules['url'] = ['required', 'string', 'max:500', 'url', 'starts_with:http://,https://'];
        $rules['slug'] = $creating
            ? ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/']
            : ['nullable', 'string', 'max:60'];

        return $rules;
    }

    /** @param array<string, mixed> $data */
    private function fillable(array $data): array
    {
        $out = [
            'name' => trim((string) $data['name']),
            'url' => trim((string) $data['url']),
            'kind' => (string) $data['kind'],
            'enabled' => (bool) ($data['enabled'] ?? true),
            'priority' => (int) ($data['priority'] ?? 5),
            'keywords' => ($data['keywords'] ?? null) ?: null,
            'region' => ($data['region'] ?? null) ?: null,
            'max_items' => (int) ($data['max_items'] ?? 8),
            'items_path' => ($data['items_path'] ?? null) ?: null,
            'title_field' => ($data['title_field'] ?? null) ?: null,
            'link_field' => ($data['link_field'] ?? null) ?: null,
            'date_field' => ($data['date_field'] ?? null) ?: null,
            'summary_field' => ($data['summary_field'] ?? null) ?: null,
            'note' => ($data['note'] ?? null) ?: null,
        ];
        if (isset($data['slug']) && $data['slug']) {
            $out['slug'] = (string) $data['slug'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function map(WebSource $source): array
    {
        return [
            'id' => $source->id,
            'slug' => $source->slug,
            'name' => $source->name,
            'url' => $source->url,
            'kind' => $source->kind,
            'enabled' => (bool) $source->enabled,
            'priority' => (int) $source->priority,
            'keywords' => (string) $source->keywords,
            'region' => $source->region,
            'max_items' => (int) $source->max_items,
            'items_path' => $source->items_path,
            'title_field' => $source->title_field,
            'link_field' => $source->link_field,
            'date_field' => $source->date_field,
            'summary_field' => $source->summary_field,
            'note' => $source->note,
        ];
    }
}
