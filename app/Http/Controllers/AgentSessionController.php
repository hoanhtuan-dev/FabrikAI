<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PHIÊN LÀM VIỆC DAI DẲNG của Agent Studio (2026-09-25).
 *
 * VÌ SAO CÓ FILE NÀY: từ 2026-09-25 luồng Agent Studio dài ra (Định hướng có 7 bước con, Thực thi
 * duyệt TỪNG mẫu prompt). Người dùng làm dở rồi đóng máy, tối mở lại trên điện thoại — trước đây mất
 * sạch, chỉ còn bản nháp localStorage của ĐÚNG một trình duyệt. "Dai dẳng" phải nghĩa là: mở máy khác,
 * trình duyệt khác, vẫn thấy đúng chỗ đang làm.
 *
 * LƯU Ở ĐÂU — dùng luôn bảng projects: MỘT BẢN NHÁP = MỘT PHIÊN.
 *   · Không thêm bảng mới ⇒ không phải migrate production, và không có mô hình dữ liệu thứ hai để lệch.
 *   · Phiên thừa hưởng sẵn mọi thứ của bộ sưu tập: owner-scoped, xoá mềm, trạng thái, hiện ở
 *     /bo-suu-tap, xuất gói cho xưởng.
 *   · Nội dung phiên nằm ở settings.agent_session (JSON) — cùng chỗ với dữ liệu brief mà
 *     "Tạo bộ sưu tập từ brief" đã ghi.
 *
 * QUYỀN: mọi thao tác đều lọc theo user_id của người đang đăng nhập. Không có đường nào để một tài
 * khoản đọc/ghi phiên của tài khoản khác, kể cả khi họ đoán được id.
 */
class AgentSessionController extends Controller
{
    /** Phiên ĐANG MỞ của người dùng (bản nháp Agent Studio mới nhất, chưa chốt). */
    public function show(Request $request): JsonResponse
    {
        $project = $this->openSession($request->user());

        return response()->json([
            'project' => $project ? $this->shell($project) : null,
            'session' => $project ? (array) ($project->settings['agent_session'] ?? []) : null,
        ]);
    }

    /**
     * GHI phiên. Trình duyệt gọi theo nhịp gộp (debounce) nên đây là đường NÓNG — giữ nó rẻ:
     * một UPDATE, không gọi model, không dựng lại brief.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $user = $request->user();
        $project = $this->projectForWrite($user, isset($data['project_id']) ? (int) $data['project_id'] : null);

        $settings = $this->settingsOf($project);
        $session = (array) $data['session'];
        $session['version'] = (int) ($session['version'] ?? self::SESSION_VERSION);
        // Mốc thời gian do MÁY CHỦ đặt: đồng hồ máy khách lệch thì "cập nhật lúc" hiện sai, và khi hai
        // thiết bị cùng ghi thì mốc của máy khách không nói được cái nào mới hơn.
        $session['updated_at'] = now()->toISOString();
        unset($session['status']);   // trạng thái phiên chỉ đổi qua close()/reopen() — không nhận từ client

        $settings['agent_studio'] = true;
        $settings['agent_session'] = $session;
        $project->settings = $settings;

        $name = trim((string) ($session['name'] ?? ''));
        if ($name !== '') {
            $project->name = mb_substr($name, 0, 255);
        }

        // status KHÔNG fillable (mọi chuyển trạng thái phải qua ProjectWorkflowService) và bản nháp mới
        // tạo đã là 'draft' sẵn — đường này cố ý không đụng tới nó.
        $project->save();

        return response()->json([
            'project' => $this->shell($project->fresh()),
            'saved_at' => $session['updated_at'],
            'samples_done' => $this->countDone($project),
        ]);
    }

    /**
     * CHỐT PHIÊN — người dùng đã làm xong (hoặc muốn gác lại) thì đóng phiên.
     *
     * Cố ý KHÔNG đụng tới projects.archived/status: chuyển sang archived nằm trong REVIEWER_GATES
     * (cần quyền người duyệt), nên khách thường bấm "lưu trữ" sẽ nhận 403 — đúng loại lỗi im lặng khó
     * hiểu. Bộ sưu tập vẫn nằm nguyên trong /bo-suu-tap; đổi trạng thái là việc của người dùng ở đó.
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
        ]);

        $project = $request->user()->projects()->whereNull('deleted_at')->whereKey((int) $data['project_id'])->first();
        abort_if($project === null, 404, 'Không tìm thấy phiên làm việc.');

        $settings = $this->settingsOf($project);
        $session = (array) ($settings['agent_session'] ?? []);
        $session['status'] = 'closed';
        $session['closed_at'] = now()->toISOString();
        $settings['agent_session'] = $session;
        $project->settings = $settings;
        $project->save();

        return response()->json([
            'project' => $this->shell($project->fresh()),
            'closed_at' => $session['closed_at'],
        ]);
    }

    /**
     * MỞ LẠI một phiên đã chốt (người dùng đổi ý). Không có đường này thì "chốt" là ngõ cụt: phiên cũ
     * vẫn nằm đó nhưng không cách nào quay lại làm tiếp.
     */
    public function reopen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
        ]);

        $project = $request->user()->projects()->whereNull('deleted_at')->whereKey((int) $data['project_id'])->first();
        abort_if($project === null, 404, 'Không tìm thấy phiên làm việc.');

        $settings = $this->settingsOf($project);
        $session = (array) ($settings['agent_session'] ?? []);
        unset($session['status'], $session['closed_at']);
        $session['updated_at'] = now()->toISOString();
        $settings['agent_session'] = $session;
        $project->settings = $settings;
        $project->save();

        return response()->json(['project' => $this->shell($project->fresh()), 'session' => $session]);
    }

    public const SESSION_VERSION = 1;

    /** Luật của payload phiên — trần số lượng đặt theo thứ người dùng thật sự làm được trong một phiên. */
    private function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'min:1'],
            'session' => ['required', 'array'],
            'session.version' => ['nullable', 'integer', 'min:1', 'max:99'],
            'session.step' => ['nullable', 'string', 'max:20'],
            'session.sub' => ['nullable', 'string', 'max:40'],
            'session.name' => ['nullable', 'string', 'max:255'],
            'session.prompt' => ['nullable', 'string', 'max:2000'],
            'session.brief' => ['nullable', 'string', 'max:4000'],
            'session.region' => ['nullable', 'string', 'in:all,hcm,hanoi,danang'],
            'session.trend_ids' => ['nullable', 'array', 'max:10'],
            'session.trend_ids.*' => ['nullable', 'string', 'max:80'],
            'session.sku_total' => ['nullable', 'integer', 'min:1', 'max:400'],
            'session.size_distribution' => ['nullable', 'array', 'max:20'],
            'session.size_distribution.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'session.palette' => ['nullable', 'array', 'max:12'],
            'session.moodboard' => ['nullable', 'array', 'max:40'],
            'session.plan_assumptions' => ['nullable', 'array'],
            'session.samples' => ['nullable', 'array', 'max:400'],
            'session.samples.*.id' => ['nullable', 'string', 'max:60'],
            'session.samples.*.name' => ['nullable', 'string', 'max:120'],
            'session.samples.*.category' => ['nullable', 'string', 'max:80'],
            'session.samples.*.size' => ['nullable', 'string', 'max:8'],
            'session.samples.*.status' => ['nullable', 'string', 'in:todo,generating,done,skipped'],
            'session.samples.*.prompt_vi' => ['nullable', 'string', 'max:1600'],
            'session.samples.*.prompt_en' => ['nullable', 'string', 'max:1600'],
            'session.samples.*.negative_prompt' => ['nullable', 'string', 'max:600'],
            'session.samples.*.note' => ['nullable', 'string', 'max:400'],
            'session.samples.*.context' => ['nullable', 'string', 'max:400'],
            'session.brief_snapshot' => ['nullable', 'array'],
        ];
    }

    /**
     * Phiên ĐANG MỞ: bản nháp Agent Studio mới nhất chưa chốt.
     *
     * Lọc bằng PHP (không bằng JSON SQL) vì repo chạy cả MySQL (production) lẫn SQLite (test) — một
     * truy vấn settings->agent_studio chỉ đúng trên một trong hai. Một người dùng có vài bản nháp thì
     * quét 20 bản gần nhất là quá đủ và luôn đúng.
     */
    private function openSession(User $user): ?Project
    {
        return $user->projects()
            ->whereNull('deleted_at')
            ->where('archived', false)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->first(function (Project $p): bool {
                $settings = $this->settingsOf($p);

                // PHẢI là phiên Agent Studio. Thiếu điều kiện này thì MỌI bản nháp của người dùng đều
                // bị coi là "phiên đang mở" — mở Agent Studio lên là thấy dự án cũ nào đó, và lần lưu
                // đầu tiên ghi đè lên nó. Đây là lỗi bắt được bằng test (tài khoản admin có sẵn dự án).
                return ($settings['agent_studio'] ?? false) === true
                    && ($settings['agent_session']['status'] ?? '') !== 'closed';
            });
    }

    /**
     * Đọc cột settings an toàn với MỌI kiểu cast.
     *
     * `settings` được cast sang ArrayObject, mà `(array) $arrayObject` cho ra tuỳ phiên bản PHP — đọc
     * thẳng từ JSON gốc là cách duy nhất chắc chắn đúng, và cũng là thứ tự nhiên của một cột JSON.
     */
    private function settingsOf(Project $project): array
    {
        $raw = $project->getRawOriginal('settings');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }
        $value = $project->settings;
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \ArrayObject) {
            return $value->getArrayCopy();
        }

        return [];
    }

    /** Phiên để GHI: có project_id thì dùng (kiểm quyền sở hữu), không thì lấy phiên đang mở hoặc tạo mới. */
    private function projectForWrite(User $user, ?int $id): Project
    {
        if ($id) {
            $project = $user->projects()->whereNull('deleted_at')->whereKey($id)->first();
            abort_if($project === null, 404, 'Không tìm thấy phiên làm việc.');
            return $project;
        }

        return $this->openSession($user) ?? $user->projects()->create([
            'name' => 'Bộ sưu tập đang dựng',
            'settings' => ['agent_studio' => true],
        ]);
    }

    /** Vỏ của phiên trả về giao diện — đủ để hiện "đang làm bộ nào, đã xong mấy mẫu". */
    private function shell(Project $project): array
    {
        $settings = $this->settingsOf($project);
        $samples = (array) ($settings['agent_session']['samples'] ?? []);

        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'updated_at' => $project->updated_at?->toISOString(),
            'closed' => ($settings['agent_session']['status'] ?? '') === 'closed',
            'samples_done' => collect($samples)->where('status', 'done')->count(),
            'samples_total' => count($samples),
        ];
    }

    private function countDone(Project $project): int
    {
        return collect((array) ($this->settingsOf($project)['agent_session']['samples'] ?? []))
            ->where('status', 'done')->count();
    }
}
