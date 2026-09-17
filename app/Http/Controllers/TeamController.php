<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NHÓM LÀM VIỆC THEO SỐ GHẾ CỦA GÓI (Q4 — 2026-09-19).
 *
 * Chủ doanh nghiệp/studio cần nhiều người cùng dùng MỘT gói: chung credit, chung bộ sưu tập. Ba
 * endpoint ở đây là toàn bộ bề mặt quản lý ghế:
 *   · GET    /api/team                — tình trạng ghế + danh sách thành viên
 *   · POST   /api/team/members        — mời một thành viên (trả MẬT KHẨU TẠM đúng một lần)
 *   · DELETE /api/team/members/{user} — bỏ một thành viên khỏi nhóm (giải phóng ghế)
 *
 * Quyền: chủ nhóm (tài khoản không thuộc nhóm nào) hoặc Super Admin. Thành viên KHÔNG quản lý ghế —
 * các em không mời thêm người bằng credit của chủ nhóm.
 */
class TeamController extends Controller
{
    public function __construct(protected TeamService $team) {}

    /** GET /api/team — ghế + thành viên của nhóm mà người dùng đang thuộc. */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        // Thành viên xem được nhóm của mình (biết mình đang ở nhóm nào) nhưng không có quyền mời/xoá.
        $owner = $this->team->ownerOf($actor);

        return response()->json([
            'seats' => studio_team_seats($actor),
            'is_owner' => ! $actor->isTeamMember(),
            'owner' => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
            'members' => array_map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'is_owner' => (int) $u->id === (int) $owner->id,
                'is_active' => (bool) $u->is_active,
                'joined_at' => $u->created_at?->format('d/m/Y'),
            ], $this->team->members($owner)),
        ]);
    }

    /** POST /api/team/members — mời thành viên mới vào nhóm. */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if ($actor->isTeamMember() && ! $actor->isSuperAdmin()) {
            return response()->json([
                'message' => 'Bạn là thành viên trong nhóm — chỉ chủ nhóm mới thêm được người.',
                'code' => 'not_team_owner',
            ], 403);
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            [$member, $temp] = $this->team->invite($actor, $data['email'], $data['name'] ?? null, $data['phone'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invite_failed'], 422);
        }

        return response()->json([
            'ok' => true,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'is_owner' => false,
                'is_active' => true,
                'joined_at' => $member->created_at?->format('d/m/Y'),
            ],
            // Mật khẩu tạm chỉ trả về MỘT LẦN ngay sau khi mời (không lưu dạng đọc được ở đâu khác).
            'temp_password' => $temp,
            'seats' => studio_team_seats($actor),
            'message' => 'Đã thêm '.$member->name.' vào nhóm. Gửi email + mật khẩu tạm bên dưới cho họ đăng nhập.',
        ], 201);
    }

    /** DELETE /api/team/members/{user} — bỏ thành viên khỏi nhóm (giải phóng ghế). */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        if ($actor->isTeamMember() && ! $actor->isSuperAdmin()) {
            return response()->json([
                'message' => 'Bạn là thành viên trong nhóm — chỉ chủ nhóm mới bỏ được ghế.',
                'code' => 'not_team_owner',
            ], 403);
        }

        if ((int) $user->id === (int) $actor->id) {
            return response()->json(['message' => 'Bạn là chủ nhóm — không thể tự bỏ ghế của mình.', 'code' => 'cannot_remove_self'], 422);
        }

        // [Q4] Không thuộc nhóm của mình ⇒ 403 (lỗi phân quyền), không phải 404: câu trả lời phải nói
        // rõ "không có quyền", tránh việc chủ nhóm khác tưởng nhầm là ghế đã bị xoá.
        if (! $this->team->remove($actor, $user)) {
            return response()->json(['message' => 'Tài khoản này không thuộc nhóm của bạn.', 'code' => 'not_in_team'], 403);
        }

        return response()->json([
            'ok' => true,
            'seats' => studio_team_seats($actor),
            'message' => 'Đã bỏ '.$user->name.' khỏi nhóm. Tài khoản và ảnh đã tạo vẫn được giữ nguyên.',
        ]);
    }
}
