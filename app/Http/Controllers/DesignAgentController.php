<?php

namespace App\Http\Controllers;

use App\Services\CollectionPlanService;
use App\Services\DesignAgentService;
use App\Services\MarketSignalService;
use App\Services\WebAccessService;
use App\Services\WebSourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DesignAgentController extends Controller
{
    public function __construct(private readonly DesignAgentService $agents) {}

    public function radar(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'region' => ['nullable', 'string', 'in:all,hcm,hanoi,danang'],
            // Người dùng được QUYỀN tắt suy luận AI (mặc định BẬT): tắt ⇒ engine tất định,
            // nhanh và không tốn lượt gọi model.
            'ai' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->agents->radar(
            $request->user(), (string) ($data['region'] ?? 'all'), (bool) ($data['ai'] ?? true)
        ));
    }

    /**
     * KHẢ NĂNG TRUY CẬP INTERNET của agent — ĐO THẬT (Đợt 22 — 2026-09-23).
     *
     * Giao diện Agent Studio trước đây ghi "nguồn ngoài đang ở chế độ demo" bằng VĂN BẢN TĨNH: câu đó
     * không biết máy chủ có ra được internet hay không, cũng không biết model đang cấu hình có tìm kiếm
     * tích hợp hay không. Endpoint này trả kết quả ĐO (cache 10 phút) để giao diện nói đúng sự thật.
     *
     * `?force=1` = đo lại ngay (nút "Kiểm tra lại"), có throttle riêng vì mỗi lần đo là request ra ngoài.
     */
    public function webAccess(Request $request, WebAccessService $web): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['force' => ['nullable', 'boolean']]);

        return response()->json($web->probe((bool) ($data['force'] ?? false)));
    }

    /**
     * NGUỒN DỮ LIỆU NGOÀI đang được đưa vào prompt (Đợt 27 — 2026-09-23).
     *
     * Máy chủ tự đi lấy tin (RSS/JSON) rồi nhét vào prompt kèm URL + thời điểm; endpoint này để giao diện
     * hiển thị ĐÚNG thứ đang dùng (nguồn nào chết, nguồn nào bị lọc hết tin) — không phải câu văn tĩnh.
     * `?force=1` = lấy lại ngay (nút "Làm mới nguồn").
     */
    public function sources(Request $request, WebSourceService $sources, MarketSignalService $market): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'force' => ['nullable', 'boolean'],
            'region' => ['nullable', 'string', 'in:all,hcm,hanoi,danang'],
        ]);
        $region = (string) ($data['region'] ?? 'all');
        $force = (bool) ($data['force'] ?? false);

        $evidence = $sources->evidence($region, WebSourceService::EVIDENCE_LIMIT, $force);

        // TÍN HIỆU THỊ TRƯỜNG đi kèm chính lời gọi này: người dùng bấm "Cập nhật tin" thì cả phần ĐO từ tin
        // cũng phải mới, nếu không màn hình hiện tin mới mà số liệu vẫn của lần đo cũ.
        $evidence['market'] = $market->capture($region, $force);

        return response()->json($evidence);
    }

    public function collection(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $this->validatedCollectionInput($request);

        // `force=1` = bỏ qua BỘ ĐỆM và gọi model lại (nút "Tạo lại" trên giao diện). Mặc định dùng bộ đệm:
        // cùng đầu vào + cùng DNA + cùng model ⇒ kết quả y hệt, không có lý do trả thêm ~28 giây và token.
        $brief = $this->agents->collectionBrief(
            $data, $request->user(), (bool) ($data['ai'] ?? true), (bool) ($data['force'] ?? false)
        );

        // Hướng đã chọn mà KHÔNG còn trong danh mục ⇒ brief vẫn được tạo, nhưng phải NÓI RA đã bỏ cái nào.
        // Im lặng bỏ là người dùng tưởng brief bám đủ 3 hướng trong khi thực tế chỉ còn 2.
        $dropped = array_values((array) ($data['dropped_trend_ids'] ?? []));
        if ($dropped !== []) {
            $brief['dropped_trend_ids'] = $dropped;
            $brief['dropped_note'] = 'Đã bỏ '.count($dropped).' hướng không còn trong danh sách hiện tại (danh mục hướng thay đổi theo tin mới). Mở lại bước Tín hiệu rồi chọn lại nếu bạn muốn bám đúng các hướng đó.';
        }

        return response()->json($brief);
    }

    /**
     * KẾ HOẠCH SẢN XUẤT & LỢI NHUẬN (giá thành · lệnh cắt · đợt sản xuất · bảng size).
     *
     * Cố ý KHÔNG gọi model AI: đây là tầng TIỀN, mọi con số phải tái lập được và phải do chủ xưởng
     * kiểm soát qua đơn giá họ nhập. Cùng đầu vào với brief nên cấu trúc danh mục / bảng size / dải
     * giá luôn khớp đúng cái đang hiển thị ở bước Định hướng.
     */
    public function plan(Request $request, CollectionPlanService $planner): \Illuminate\Http\JsonResponse
    {
        $data = $this->validatedCollectionInput($request);
        $assumptions = Validator::make($request->all(), [
            'assumptions' => ['nullable', 'array'],
            'assumptions.*' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ])->validate()['assumptions'] ?? [];

        // brief ở chế độ TẤT ĐỊNH (không AI) — cấu trúc/size/giá là dữ liệu vào của kế hoạch,
        // không cần tới model nên phản hồi tức thì.
        $brief = $this->agents->collectionBrief($data, $request->user(), false);

        return response()->json([
            'engine' => 'plan-v1',
            'model' => [
                'group' => null,
                'mode' => 'rule',
                'provider' => null,
                'model' => null,
                'candidates' => 0,
                'latency_ms' => 0,
                'cached' => false,
                'reason' => 'plan_is_deterministic',
                'note' => 'Giá thành, lệnh cắt và lợi nhuận do hệ thống TÍNH từ đơn giá bạn nhập — AI không tham gia vào con số.',
            ],
            'plan' => $planner->plan($brief, is_array($assumptions) ? $assumptions : []),
            'brief_basis' => [
                'structure' => $brief['structure'],
                'size_distribution' => $brief['size_distribution'],
                'price_bands' => $brief['price_bands'],
                'brand_narrative' => $brief['brand_narrative'],
                'project_payload' => $brief['project_payload'],
                'engine' => $brief['engine'],
            ],
        ]);
    }

    /**
     * DỮ LIỆU BÁN HÀNG THẬT của shop (nhập tay hoặc dán từ Excel/POS).
     *
     * Đây là phần khiến Agent Studio gắn bó lâu dài: càng nhập nhiều kỳ, cơ cấu SKU, dải giá và lời
     * khuyên càng sát cái shop THẬT SỰ bán được. Chỉ ghi vào dữ liệu của CHÍNH người dùng.
     */
    public function shopSignals(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'rows' => ['present', 'array', 'max:200'],
            'rows.*.name' => ['nullable', 'string', 'max:160'],
            'rows.*.category' => ['nullable', 'string', 'max:80'],
            'rows.*.units_sold' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'rows.*.stock_on_hand' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'rows.*.returns' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'rows.*.price_vnd' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'rows.*.period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'source' => ['nullable', 'string', 'in:manual,paste'],
        ]);

        $result = $this->agents->saveShopSignals(
            $request->user(), $data['rows'], (string) ($data['source'] ?? 'manual')
        );

        return response()->json([
            'saved' => count($result['rows']),
            'rows' => $result['rows'],
            'shop' => $result['shop'],
        ]);
    }

    /**
     * Validate dùng CHUNG cho brief và kế hoạch sản xuất — hai đường luôn nhận đúng một dạng đầu vào
     * nên không thể lệch nhau về cấu trúc danh mục, bảng size hay dải giá.
     */
    private function validatedCollectionInput(Request $request): array
    {
        $payload = $request->only([
            'prompt', 'region', 'trend_ids', 'brief', 'size_distribution', 'ai', 'force', 'reference_images',
        ]);
        $payload['prompt'] = trim((string) ($payload['prompt'] ?? ''));
        $payload['brief'] = trim((string) ($payload['brief'] ?? ''));
        $payload['trend_ids'] = array_values(array_filter(
            array_map('trim', (array) ($payload['trend_ids'] ?? [])),
            'strlen',
        ));
        $payload['size_distribution'] = is_array($payload['size_distribution'] ?? null)
            ? $payload['size_distribution'] : [];
        // Ảnh mẫu cho VAI ĐỌC ẢNH: tối đa 3, mỗi cái là đường dẫn trong site ('/…') hoặc URL http(s).
        // Không nhận giá trị khác ⇒ không có đường lách để máy chủ đi lấy tài nguyên nội bộ.
        $payload['reference_images'] = array_values(array_filter(
            array_map('trim', (array) ($payload['reference_images'] ?? [])),
            'strlen',
        ));

        $validator = Validator::make($payload, [
            'prompt' => ['required', 'string', 'max:2000'],
            'region' => ['nullable', 'string', 'in:all,hcm,hanoi,danang'],
            'trend_ids' => ['nullable', 'array', 'max:10', 'distinct'],
            'trend_ids.*' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,79}$/'],
            'brief' => ['nullable', 'string', 'max:4000'],
            'size_distribution' => ['nullable', 'array', 'max:20'],
            'reference_images' => ['nullable', 'array', 'max:3'],
            'reference_images.*' => ['nullable', 'string', 'max:400', 'regex:#^(/|https?://)#'],
            'size_distribution.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'ai' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($payload) {
            if (mb_strlen($payload['prompt'] ?? '') < 3) {
                $validator->errors()->add('prompt', 'Mô tả bộ sưu tập phải có ít nhất 3 ký tự.');
            }

            $ids = $payload['trend_ids'] ?? [];
            if (count(array_unique($ids)) !== count($ids)) {
                $validator->errors()->add('trend_ids', 'Mỗi xu hướng chỉ được chọn một lần.');
            }
            // KHÔNG chặn cả request vì một id đã biến mất — xem chú thích ở bước lọc bên dưới.

            foreach (array_keys($payload['size_distribution'] ?? []) as $size) {
                if (! preg_match('/^[A-Za-z0-9]{1,8}$/', (string) $size)) {
                    $validator->errors()->add('size_distribution', 'Mã size chỉ được chứa chữ và số (tối đa 8 ký tự).');
                    break;
                }
            }
        });

        $data = $validator->validate();

        // XU HƯỚNG ĐÃ CHỌN MÀ KHÔNG CÒN TRONG DANH SÁCH ⇒ BỎ QUA, KHÔNG CHẶN.
        //
        // [LỖI THẬT — production 2026-09-21, mã tra cứu L-WJH6] Người dùng chọn một hướng đang hiện trên
        // màn hình rồi bấm tạo bộ sưu tập và nhận **HTTP 422 "Có xu hướng không tồn tại trong TrendRadar."**
        // Nguyên nhân: danh mục hướng KHÔNG còn cố định — hướng sinh từ tin thật (id `live-…`) phụ thuộc
        // tin lấy được, và từ 2026-09-21 còn phụ thuộc cả câu hỏi model tự tra trong lượt đó. Giữa lúc mở
        // radar và lúc bấm tạo brief, danh mục có thể đã đổi (đệm hết hạn, tin mới, model hỏi khác).
        //
        // Chặn cả request vì một id cũ là đánh đổi sai: người dùng mất toàn bộ công nhập liệu, và không có
        // cách nào để họ tự sửa. Nay id lạ bị BỎ RA và ĐẾM LẠI — phản hồi nói rõ đã bỏ cái nào.
        $selected = array_values(array_unique(array_map('strval', (array) ($data['trend_ids'] ?? []))));
        $known = $this->agents->trendIds();
        $kept = array_values(array_intersect($selected, $known));
        $data['trend_ids'] = $kept;
        $data['dropped_trend_ids'] = array_values(array_diff($selected, $kept));

        $data['size_distribution'] = collect($data['size_distribution'] ?? [])
            ->mapWithKeys(fn ($count, $size) => [strtoupper(substr((string) $size, 0, 8)) => (int) $count])
            ->all();

        return $data;
    }
}
