<?php

namespace App\Services;

use App\Models\User;

/**
 * MỘT CỔNG CHO MỌI CÔNG CỤ CỦA AGENT — nơi vòng lặp hàm gặp VÒNG KHÉP KÍN (2026-09-26).
 *
 * Trước lớp này, mỗi lượt chạy tự dựng công cụ của mình: \`DesignAgentService\` có \`makeSearchTool()\` và gắn
 * thẳng \`tool_handler\` vào MỘT công cụ duy nhất (radar dòng 2192, brief dòng 2621). Hệ quả:
 *   · thêm công cụ thứ hai là sửa hai chỗ và dễ lệch nhau;
 *   · kết quả tìm được KHÔNG đi đâu cả — tra xong là quên;
 *   · không có SỔ TRÍCH DẪN chung, nên không công cụ nào tham chiếu được kết quả của công cụ khác.
 *
 * Lớp này gom cả ba việc vào một chỗ:
 *   1. ĐIỀU PHỐI — \`definitions()\` cho gateway khai công cụ, \`handle()\` định tuyến theo tên.
 *   2. SỔ TRÍCH DẪN — mỗi kết quả tìm được cấp một mã ổn định (\`src_1\`, \`src_2\`…) trong lượt chạy; công
 *      cụ đọc trang CHỈ được đọc những địa chỉ có trong sổ này.
 *   3. GHI SỔ NGUỒN — kết quả tìm được ghi vào WebFindingService, và khi mạng KHÔNG có gì mới thì lấy lại
 *      nguồn đã tra trước đó. Đây là mắt xích biến "tra xong rồi quên" thành vòng khép kín: công cụ tìm
 *      kiếm nuôi sổ, sổ nuôi lại Agent Studio ở lượt sau, người dùng lưu nguồn thì nguồn đó được ưu tiên.
 *
 * KHÔNG BAO GIỜ NÉM: mọi thất bại của công cụ trở thành kết quả đọc được gửi lại cho model (ném lỗi ở đây
 * là giết cả lượt chạy chỉ vì một công cụ phụ).
 */
class AgentToolbox
{
    /** @var array<string, object> tên công cụ => đối tượng công cụ */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> mã trích dẫn (src_1…) => item */
    private array $ledger = [];

    /** @var array<string, string> URL đã chuẩn hoá => mã trích dẫn (để công cụ đọc trang tra ngược) */
    private array $urlIndex = [];

    /** @var list<string> */
    private array $queries = [];

    /** @var list<string> NHÃN nguồn (tên site) đã trả về trong lượt — KHÁC \`$sources\` là trình kết nối được tiêm vào. */
    private array $sourceLabels = [];

    private int $calls = 0;

    private int $results = 0;

    private int $reused = 0;

    private int $stored = 0;

    private int $updated = 0;

    private bool $truncated = false;

    private ?string $error = null;

    private ?string $findingsError = null;

    public function __construct(
        private readonly ?WebSourceService $sources,
        private readonly ?WebFindingService $findings,
        private readonly ?User $user,
        private readonly string $region = 'all',
    ) {}

    /**
     * Bật CÔNG CỤ TÌM KIẾM — cổng vào của cả vòng khép kín.
     *
     * Vì sao công cụ tìm kiếm luôn đi kèm công cụ đọc trang: đo trên production, khi chỉ có tiêu đề thì
     * model gọi 2-5 lượt tìm cho một chủ đề; có thêm đường đọc nội dung thì nó tra ĐÚNG trang cần đọc và
     * trả lời được câu hỏi cụ thể. Hai công cụ bật/tắt cùng nhau để không bao giờ có "đọc trang" mà không
     * có gì để đọc.
     */
    public function withSearch(): static
    {
        $search = new WebSearchTool($this->sources);
        $search->enable(true);
        $this->tools[WebSearchTool::NAME] = $search;

        $page = new ReadPageTool($this->sources);
        $page->enable(true);
        $this->tools[ReadPageTool::NAME] = $page;

        return $this;
    }

    /**
     * Khai báo công cụ cho gateway (chuẩn function-calling).
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(
            fn (object $tool) => (array) $tool->definition(),
            array_values($this->tools),
        ));
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * ĐỊNH TUYẾN một lời gọi công cụ của model.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(string $name, array $arguments): array
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            // Model bịa tên hàm: trả lỗi ĐỌC ĐƯỢC để nó tự sửa (gateway cũng đã lọc, đây là chốt thứ hai).
            return ['error' => 'không có công cụ tên "'.$name.'"'];
        }

        if ($name === ReadPageTool::NAME) {
            // Nạp lại sổ trích dẫn NGAY TRƯỚC khi đọc: trang vừa tìm được ở lượt trước phải đọc được ở lượt này.
            $tool->allow($this->allowedUrls());

            return $tool->handle($arguments);
        }

        $result = $tool->handle($arguments, $this->region);

        $this->calls++;
        $query = trim((string) ($result['query'] ?? ''));
        if ($query !== '') {
            $this->queries[] = $query;
        }

        // GHI SỔ + CẤP MÃ TRÍCH DẪN cho từng kết quả — làm ở ĐÂY (không phải trong WebSearchTool) để công
        // cụ tìm kiếm vẫn là một lớp thuần: tìm và trả kết quả, không biết gì về sổ của ai.
        $items = [];
        foreach ((array) ($result['results'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = $this->register($item);
        }
        $result['results'] = $items;
        $result['found'] = count($items);
        $this->results += count($items);

        foreach ((array) ($result['sources'] ?? []) as $label) {
            $label = (string) $label;
            if ($label !== '' && ! in_array($label, $this->sourceLabels, true)) {
                $this->sourceLabels[] = $label;
            }
        }

        // GHI SỔ NGUỒN: nguồn vừa tìm được thuộc về tài khoản này, để lượt sau dùng lại được.
        if ($items !== [] && $this->findings !== null) {
            $written = $this->findings->remember($this->user, $query, $this->region, $items);
            $this->stored += (int) ($written['stored'] ?? 0);
            $this->updated += (int) ($written['updated'] ?? 0);
            if (($written['error'] ?? null) !== null) {
                $this->findingsError = (string) $written['error'];
            }
        }

        // KHÔNG CÓ KẾT QUẢ MỚI ⇒ DÙNG LẠI NGUỒN ĐÃ TRA (mắt xích khép vòng).
        //
        // Vì sao không trả về "0 kết quả" luôn: mạng hỏng, nguồn tạm chết, hay câu hỏi đã tra hôm qua —
        // trong cả ba trường hợp, nguồn ĐÃ ĐỌC ĐƯỢC trước đó vẫn là dữ liệu thật và tốt hơn hẳn việc để
        // model trả lời bằng trí nhớ. Nhưng phải NÓI RÕ là dùng lại, kèm từ khoá gốc và thời điểm.
        if ($items === [] && $this->findings !== null && $query !== '') {
            $recalled = $this->findings->recall($this->user, $query, $this->region);
            if ($recalled !== []) {
                $items = array_map(fn (array $item) => $this->register($item, true), $recalled);
                $result['results'] = $items;
                $result['found'] = count($items);
                $result['reused'] = count($items);
                $this->reused += count($items);
                // ĐẾM cả nguồn dùng lại: với người dùng, model vẫn có 1 nguồn để đọc — bỏ khỏi số đếm là
                // báo cáo thấp hơn thực tế và giao diện sẽ nói "0 kết quả" trong khi prompt có nguồn.
                $this->results += count($items);
                $result['note'] = 'Lần tra mới KHÔNG có kết quả; đây là '.count($items).' nguồn ĐÃ TRA TRƯỚC ĐÓ cho cùng từ khoá (đánh dấu reused=true). '
                    .'Vẫn là dữ liệu thật, nhưng hãy nói rõ là nguồn cũ nếu có thể đã lỗi thời. TUYỆT ĐỐI không được bịa thêm.';
            }
        }

        // "Hết trần" KHÔNG đoán từ câu chữ: WebSearchTool::report() đã có cờ \`truncated\` thật (báo cáo gộp ở dưới).
        // Lý do không có kết quả thì lấy từ chính kết quả công cụ — nhưng CHỈ khi lượt này chưa có nguồn nào.
        if ($items === [] && ($result['note'] ?? '') !== '') {
            $this->error = (string) $result['note'];
        }
        if ($this->results > 0 && $this->error !== null && str_contains($this->error, 'Không có tin nào')) {
            // Cùng luật với WebSearchTool::report(): một truy vấn không ra kết quả KHÔNG phải lỗi của cả lượt.
            $this->error = null;
        }

        return $result;
    }

    /**
     * Mở LẦN THỬ mới: MỌI công cụ tự cấp lại trần lời gọi (lần thử lại bắt đầu hội thoại mới nên kết quả
     * tìm cũ không còn trong prompt — xem WebSearchTool::beginAttempt).
     */
    public function beginAttempt(): void
    {
        foreach ($this->tools as $tool) {
            if (is_callable([$tool, 'beginAttempt'])) {
                $tool->beginAttempt();
            }
        }
    }

    /**
     * SỔ TRÍCH DẪN của lượt chạy — để prompt nói cho model biết nó được dẫn nguồn nào và đọc được trang nào.
     *
     * @return list<array<string, mixed>>
     */
    public function citations(): array
    {
        return array_values($this->ledger);
    }

    /**
     * SỐ ĐO của cả lượt: giữ NGUYÊN các khoá mà giao diện đang đọc (calls · queries · results · sources ·
     * truncated · error) rồi THÊM phần của vòng khép kín (stored · reused · pages · citations).
     *
     * Giữ nguyên khoá cũ là ràng buộc cứng: \`useAgentStudio.js\` (dòng 741-814) đọc đúng những khoá này để
     * dựng câu "AI đã tự tra …" — đổi hình dạng là màn hình nói sai về một lượt chạy thật.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $search = $this->tools[WebSearchTool::NAME] ?? null;
        $page = $this->tools[ReadPageTool::NAME] ?? null;
        $searchReport = $search !== null ? (array) $search->report() : [];
        $pageReport = $page !== null ? (array) $page->report() : [];

        return [
            'enabled' => ($searchReport['enabled'] ?? false) === true,
            'calls' => $this->calls,
            'queries' => $this->queries,
            'results' => $this->results,
            'sources' => $this->sourceLabels,
            'truncated' => $this->truncated || (bool) ($searchReport['truncated'] ?? false) || (bool) ($pageReport['truncated'] ?? false),
            'error' => $this->error ?? ($searchReport['error'] ?? null),
            // ── VÒNG KHÉP KÍN: số đo của việc GHI SỔ và DÙNG LẠI ──────────────────────────────
            'stored' => $this->stored,
            'updated' => $this->updated,
            'reused' => $this->reused,
            'findings_error' => $this->findingsError,
            'pages' => [
                'calls' => (int) ($pageReport['calls'] ?? 0),
                'urls' => array_values((array) ($pageReport['urls'] ?? [])),
                'chars' => (int) ($pageReport['chars'] ?? 0),
                'truncated' => (bool) ($pageReport['truncated'] ?? false),
                'error' => $pageReport['error'] ?? null,
            ],
            'citations' => $this->citations(),
        ];
    }

    /**
     * SỔ TRÍCH DẪN dạng tra ngược: URL => TIÊU ĐỀ. Công cụ đọc trang nhận ĐÚNG danh sách này.
     *
     * Trả về tiêu đề (không phải mã trích dẫn) vì \`ReadPageTool::allow()\` khai như vậy: đọc đúng một
     * danh sách URL kèm nhãn người đọc được, để sau này muốn hiện "đang đọc: <tiêu đề>" thì có sẵn dữ liệu.
     *
     * @return array<string, string>
     */
    public function allowedUrls(): array
    {
        $allowed = [];
        foreach ($this->urlIndex as $url => $ref) {
            $allowed[$url] = (string) ($this->ledger[$ref]['title'] ?? '');
        }

        return $allowed;
    }

    /** Đăng ký một nguồn vào sổ trích dẫn và trả về item đã gắn mã (\`src_N\`). */
    private function register(array $item, bool $reused = false): array
    {
        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return $item;
        }

        $ref = $this->urlIndex[$url] ?? null;
        if ($ref === null) {
            $ref = 'src_'.(count($this->ledger) + 1);
            $this->urlIndex[$url] = $ref;
            $this->ledger[$ref] = [
                'ref' => $ref,
                'title' => (string) ($item['title'] ?? ''),
                'url' => $url,
                'source_name' => (string) ($item['source_name'] ?? $item['source'] ?? ''),
                'published_at' => $item['published_at'] ?? null,
                'snippet' => (string) ($item['summary'] ?? $item['snippet'] ?? ''),
                'found_query' => (string) ($item['found_query'] ?? ''),
                'reused' => $reused || (bool) ($item['reused'] ?? false),
            ];
        }

        return $item + ['ref' => $ref];
    }
}
