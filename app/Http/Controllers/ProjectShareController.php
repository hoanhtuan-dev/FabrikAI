<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFeedback;
use App\Models\ProjectShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CHIA SẺ BỘ SƯU TẬP CHO KHÁCH DUYỆT (Đợt 4 — 2026-09-19).
 *
 * Hai phía trong một controller:
 *   · PHÍA CHỦ (auth + can-studio): tạo link công khai (có hạn) và THU HỒI link.
 *   · PHÍA KHÁCH (không cần đăng nhập): mở link xem ảnh + brief, gửi phản hồi Duyệt / Yêu cầu sửa.
 *
 * Nguyên tắc bảo mật:
 *   · Token 48 ký tự ngẫu nhiên, KHÔNG đoán được; link hết hạn hoặc bị thu hồi ⇒ 404 (không tiết lộ
 *     là "có tồn tại nhưng hết hạn" để tránh dò token).
 *   · Trang công khai `noindex`: link chia sẻ không được vào Google.
 *   · Gửi phản hồi có throttle theo IP (chống spam) và giới hạn độ dài.
 *   · KHÔNG tự đổi trạng thái bộ sưu tập khi khách bấm "Duyệt" — chuyển trạng thái là quyết định của
 *     chủ (có whitelist + phân quyền riêng ở ProjectWorkflowService); ở đây chỉ GHI LẠI phản hồi.
 */
class ProjectShareController extends Controller
{
    /** Số ngày hạn của link — chỉ nhận các mốc này để tránh giá trị lạ. */
    private const ALLOWED_DAYS = [7, 30, 90];

    /** Trần số ảnh hiển thị trên trang khách duyệt (khớp trần của gói xuất xưởng). */
    private const SHARE_IMAGE_LIMIT = 60;

    /** Tạo (hoặc dùng lại) link chia sẻ cho một bộ sưu tập. */
    public function create(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwner($request, $project);

        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
        ]);

        // Dùng lại link còn hiệu lực để không rải nhiều link cho cùng một bộ sưu tập.
        $share = $project->shares()->get()->first(fn (ProjectShare $s) => $s->isUsable());

        if (! $share) {
            $days = (int) ($data['days'] ?? 30);
            $share = $project->shares()->create([
                'created_by' => $request->user()->id,
                'token' => ProjectShare::newToken(),
                'expires_at' => now()->addDays($days),
            ]);
        }

        return response()->json([
            'ok' => true,
            'url' => $share->publicUrl(),
            'token' => $share->token,
            'expires_at' => $share->expires_at?->format('d/m/Y'),
            'views' => (int) $share->views,
        ]);
    }

    /** Thu hồi một link (khách mở lại sẽ thấy 404). */
    public function revoke(Request $request, Project $project, ProjectShare $share): JsonResponse
    {
        $this->authorizeOwner($request, $project);
        abort_unless($share->project_id === $project->id, 404);

        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Trạng thái chia sẻ của MỘT bộ sưu tập: link còn hiệu lực (nếu có) + phản hồi của khách.
     *
     * Tách riêng khỏi danh sách dự án để danh sách không phải kèm phản hồi của mọi bộ (N+1), và để
     * panel Bộ sưu tập chỉ gọi khi người dùng thực sự mở khối chia sẻ.
     */
    public function status(Request $request, Project $project): JsonResponse
    {
        // ĐỌC trạng thái chia sẻ: mọi người làm việc trên bộ sưu tập (chủ · thành viên nhóm · Super
        // Admin) đều xem được — thành viên cần biết khách đã phản hồi gì.
        abort_unless(team_can_view_project($request->user(), $project), 403);

        $share = $project->shares()->orderByDesc('id')->get()->first(fn (ProjectShare $s) => $s->isUsable());

        return response()->json([
            'share' => $share ? [
                'url' => $share->publicUrl(),
                'token' => $share->token,
                'expires_at' => $share->expires_at?->format('d/m/Y'),
                'views' => (int) $share->views,
                'last_viewed_at' => $share->last_viewed_at?->format('d/m/Y H:i'),
            ] : null,
            'feedback' => $project->feedback()->orderByDesc('id')->limit(20)->get()->map(fn (ProjectFeedback $f) => [
                'id' => $f->id,
                'author_name' => $f->author_name,
                'decision' => $f->decision,
                'decision_label' => $f->decisionLabel(),
                'message' => $f->message,
                'created_at' => $f->created_at?->format('d/m/Y H:i'),
            ])->values(),
        ]);
    }

    /** Trang công khai cho khách xem bộ sưu tập (không cần đăng nhập). */
    public function show(Request $request, string $token)
    {
        $share = ProjectShare::query()->where('token', $token)->with('project')->first();

        // Hết hạn / đã thu hồi / không tồn tại / bộ sưu tập đã xoá ⇒ 404 giống nhau (không dò được token).
        abort_unless($share && $share->isUsable() && $share->project && $share->project->deleted_at === null, 404);

        $project = $share->project;

        // Đếm lượt xem (không chặn render nếu ghi lỗi).
        try {
            $share->forceFill(['views' => (int) $share->views + 1, 'last_viewed_at' => now()])->save();
        } catch (\Throwable $e) {
            // bỏ qua: số lượt xem không quan trọng bằng việc khách xem được ảnh
        }

        // [P0.5] ẢNH MỚI NHẤT trước + nói THẬT tổng số. Bản trước lấy 60 ảnh CŨ NHẤT và view in
        // "{{ $images->count() }} ảnh" ⇒ bộ 100 ảnh hiện đúng 60 ảnh cũ kèm dòng "60 ảnh" — khách
        // duyệt nhìn thấy số sai trên chính trang dùng để chốt mẫu.
        $imagesTotal = $project->generations()->whereNotNull('media_url')->count();
        $images = $project->generations()
            ->whereNotNull('media_url')
            ->orderByDesc('id')
            ->limit(self::SHARE_IMAGE_LIMIT)
            ->get();

        $feedback = $project->feedback()->orderByDesc('id')->limit(20)->get();

        return view('share', [
            'token' => $token,
            'share' => $share,
            'project' => $project,
            // Nhãn/màu trạng thái tiếng Việt do MỘT nguồn duy nhất sinh ra (ProjectWorkflowService) —
            // view không được tự bịa và cũng không được đọc thuộc tính không tồn tại (xem share.blade.php).
            'statusLabel' => app(\App\Services\ProjectWorkflowService::class)->label((string) $project->status),
            'imagesTotal' => $imagesTotal,
            'imagesTruncated' => $imagesTotal > $images->count(),
            'referenceImages' => app(\App\Services\StudioLibraryService::class)->uploadsForProject((int) $project->id),
            'images' => $images,
            'feedback' => $feedback,
            'sent' => $request->query('sent') === '1',
            'error' => $request->query('error'),
        ]);
    }

    /** Nhận phản hồi của khách: Duyệt hoặc Yêu cầu sửa (+ ghi chú). */
    public function submitFeedback(Request $request, string $token)
    {
        $share = ProjectShare::query()->where('token', $token)->first();
        abort_unless($share && $share->isUsable() && $share->project_id, 404);

        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:120'],
            'decision' => ['required', 'string', 'in:approved,changes'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'author_name.required' => 'Vui lòng ghi tên người phản hồi.',
            'decision.required' => 'Chọn «Duyệt» hoặc «Yêu cầu sửa».',
            'message.max' => 'Ghi chú tối đa 1000 ký tự.',
        ]);

        ProjectFeedback::create([
            'project_id' => $share->project_id,
            'share_id' => $share->id,
            'author_name' => trim((string) $data['author_name']),
            'decision' => $data['decision'],
            'message' => isset($data['message']) ? trim((string) $data['message']) : null,
        ]);

        return redirect('/chia-se/'.$token.'?sent=1#phan-hoi');
    }

    /**
     * Chỉ chủ bộ sưu tập hoặc Super Admin được TẠO/THU HỒI link chia sẻ.
     *
     * [P1 — 2026-09-20] Trước đây hàm này gọi team_can_view_project(), tức là THÀNH VIÊN NHÓM cũng
     * tạo và thu hồi được link của bộ sưu tập chủ nhóm — trái với chính comment ngay trên nó và trái
     * với luật "thành viên không quản lý bộ sưu tập" (team_can_manage_project). Hệ quả thật: một
     * nhân viên có thể THU HỒI link mà khách đang mở để duyệt mẫu.
     */
    private function authorizeOwner(Request $request, Project $project): void
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);
    }
}
