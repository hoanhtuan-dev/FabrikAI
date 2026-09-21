<?php

namespace App\Ai;

use App\Services\AiModelGateway;
use App\Services\DesignAgentService;
use Laravel\Ai\Ai;

/**
 * CẦU NỐI MODEL REGISTRY → LARAVEL AI SDK (2026-09-22).
 *
 * Vì sao cần lớp này: Laravel AI SDK đọc nhà cung cấp từ config("ai.providers.*") — cấu hình TĨNH
 * trong tệp. Còn FabrikAI giữ nhà cung cấp + khoá + model trong DB và giải theo TỪNG NHÓM CÔNG VIỆC lúc
 * chạy (Model Registry · Nhóm công việc · luân chuyển nhiều khoá · thứ tự ưu tiên · Custom Providers).
 * Hai mô hình đó không khớp nhau, và nếu bắt chúng khớp bằng cách chuyển hết cấu hình vào tệp thì mất
 * toàn bộ phần quản trị đang chạy.
 *
 * Cách làm: ĐỌC đúng thứ mà AiModelGateway ĐÃ giải (một nguồn sự thật duy nhất — không đọc lại DB theo
 * cách riêng), rồi ĐĂNG KÝ nó vào config của SDK ngay trước khi gọi. Nhờ vậy Cài đặt → Nhóm công việc /
 * Model Registry / Custom Providers vẫn là nơi quyết định, còn SDK chỉ là ĐỘNG CƠ thi hành.
 *
 * MỘT ENTRY CHO MỖI (nhà cung cấp · khoá): SDK nhận một DANH SÁCH provider và tự thử lần lượt khi nhà
 * cung cấp lỗi. Muốn luân chuyển nhiều khoá của dự án thành failover của SDK thì mỗi khoá phải là một
 * entry riêng — gộp lại là mất tính năng.
 *
 * GIỚI HẠN ĐÃ ĐO — ĐỪNG HỨA QUÁ: SDK v0.11.2 KHÔNG hỗ trợ công cụ WebSearch cho driver
 * openai-compatible. Chỉ OpenAI · Anthropic · Gemini · xAI · OpenRouter · Azure mới có
 * (Contracts/Providers/SupportsWebSearch; OpenAiCompatibleProvider KHÔNG implements nó). Mà mọi nhà cung
 * cấp của dự án đều đi đường openai-compatible (DashScope/Qwen · ckey · PayGo). Nên TÌM KIẾM WEB vẫn
 * phải ở lại đường tự viết trong AiModelGateway — cầu nối này KHÔNG thay được nó.
 */
class RegistryProviders
{
    /**
     * CHUỖI DỰ PHÒNG CỦA TỪNG VAI — phải GIỐNG HỆT chuỗi mà DesignAgentService dùng khi chọn model.
     *
     * Vì sao phải chép đúng: nhóm vai (agent_reason · agent_search · agent_vision) BỎ TRỐNG là chuyện bình
     * thường — khi đó agent rơi về nhóm nền. Nếu cầu nối chỉ đọc đúng một nhóm thì nó trả về RỖNG trong
     * khi agent vẫn chạy được, và lệnh kiểm tra sẽ báo "chưa cấu hình" cho một hệ đang chạy tốt — đúng
     * kiểu nói sai mà cả dự án này đang cố tránh.
     *
     * @return list<string>
     */
    public function chainFor(string $role): array
    {
        return match ($role) {
            'search' => [DesignAgentService::SEARCH_GROUP, DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP],
            'reason' => [DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP],
            'vision' => [DesignAgentService::VISION_GROUP, 'vision'],
            default => [$role],
        };
    }

    /**
     * Giải một nhóm công việc thành danh sách provider của SDK, THEO ĐÚNG thứ tự ưu tiên của dự án.
     *
     * @param  list<string>  $fallbacks  nhóm dự phòng, theo đúng thứ tự agent sẽ thử (xem chainFor)
     * @return list<array{name:string, model:string, label:string}>
     */
    public function forGroup(string $group, array $fallbacks = []): array
    {
        $out = [];
        $seen = [];
        $seenModel = [];

        $gateway = app(AiModelGateway::class);

        foreach (array_values(array_unique(array_merge([$group], $fallbacks))) as $candidateGroup) {
            foreach ($gateway->candidates($candidateGroup) as $candidate) {
                // Khử trùng theo (nhà cung cấp · model) GIỮA CÁC NHÓM: cùng một model xuất hiện ở cả nhóm
                // vai lẫn nhóm nền là chuyện thường, và đăng ký hai lần thì SDK thử lại đúng một thứ hai lượt.
                $signature = $candidate['provider'].':'.$candidate['model'];
                if (isset($seenModel[$signature])) {
                    continue;
                }
                $seenModel[$signature] = true;
                foreach ((array) ($candidate['keys'] ?? []) as $index => $key) {
                    $key = (string) $key;
                    if ($key === '') {
                        continue;
                    }

                    // MỘT nguồn cho luật driver/địa chỉ (xem SdkProviderMap) — chép ra đây là để hai nơi
                    // lệch nhau, và lệch ở đây chỉ lộ ra lúc chạy thật.
                    $providerConfig = SdkProviderMap::configFor($candidate, $key);
                    if ($providerConfig === null) {
                        // Không có địa chỉ gọi được ⇒ ĐỪNG đăng ký một provider sẽ hỏng lúc chạy. Bỏ qua để
                        // danh sách còn lại làm việc; nơi gọi đọc forGroup() === [] mà nói thật với người dùng.
                        continue;
                    }

                    $name = SdkProviderMap::nameForKey($candidate, $key);
                    if (isset($seen[$name])) {
                        continue;
                    }
                    $seen[$name] = true;

                    config(['ai.providers.'.$name => $providerConfig]);

                    $out[] = [
                        'name' => $name,
                        'model' => (string) $candidate['model'],
                        'label' => $candidate['provider'].':'.$candidate['model'],
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Tham số failover cho SDK: MỘT BẢN ĐỒ provider => model, theo đúng thứ tự ưu tiên.
     *
     * [ĐỌC TỪ MÃ SDK, KHÔNG ĐOÁN] Promptable::prompt() khai ?string $model — model KHÔNG nhận mảng.
     * Chỉ tham số provider nhận mảng, và khi ấy nó là một BẢN ĐỒ tên-provider => model (xem
     * Provider::formatProviderAndModelList): khoá là số thì lấy model mặc định của provider, khoá là CHUỖI
     * thì dùng đúng model ghi ở giá trị. Truyền hai mảng song song là TypeError ngay — đã dính thật khi
     * chạy --live lần đầu.
     *
     * Bản đồ giữ ĐÚNG thứ tự chèn ⇒ SDK thử lần lượt theo thứ tự mà Cài đặt quyết định.
     *
     * @return array{providers:array<string,string>, labels:list<string>}
     */
    public function failoverArgs(string $group, array $fallbacks = []): array
    {
        $rows = $this->forGroup($group, $fallbacks);

        $map = [];
        $labels = [];
        foreach ($rows as $row) {
            $map[$row['name']] = $row['model'];
            $labels[] = $row['label'];
        }

        return ['providers' => $map, 'labels' => $labels];
    }

    /** Quên instance đã dựng của các provider vừa đăng ký (khoá/model có thể đã đổi giữa hai lần gọi). */
    public function forget(array $names): void
    {
        foreach ($names as $name) {
            try {
                Ai::forgetInstance($name);
            } catch (\Throwable) {
                // Manager chưa dựng instance nào thì không có gì để quên — không phải lỗi.
            }
        }
    }

}

