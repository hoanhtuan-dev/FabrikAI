<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * XUẤT GÓI CHO XƯỞNG (Đợt 4 — 2026-09-19).
 *
 * Vì sao: chủ xưởng may không cần "một tấm ảnh đẹp" — họ cần một GÓI THÔNG TIN đủ để cắt may: ảnh tham
 * chiếu + phiếu kỹ thuật (chất liệu, màu, đường may) + bảng size, và một bản đọc được bằng máy để đưa
 * vào hệ thống của xưởng. Trước đây FabrikAI chỉ có ảnh nằm trong thư viện, muốn gửi xưởng phải tải
 * từng ảnh rồi tự soạn mô tả bằng tay.
 *
 * Nguyên tắc:
 *   · TRUNG THỰC: ảnh nào không tải được thì ghi rõ vào `anh/_KHONG_TAI_DUOC.txt` + `manifest.json`,
 *     KHÔNG im lặng bỏ qua (xưởng nhận thiếu ảnh mà không biết là tai họa).
 *   · Ảnh AI là ẢNH THAM CHIẾU — README nói rõ để xưởng không in/cắt thẳng từ ảnh AI.
 *   · Không phụ thuộc dịch vụ ngoài: mọi thứ trong 1 file ZIP, mở được bằng phần mềm nén bất kỳ.
 */
class ProjectExportService
{
    /** Trần số ảnh mỗi gói — bảo vệ thời gian phản hồi và dung lượng tải. */
    public const MAX_IMAGES = 60;

    /** Trần dung lượng mỗi ảnh đưa vào gói (50MB). */
    private const MAX_IMAGE_BYTES = 52428800;

    /**
     * Dựng file ZIP cho một bộ sưu tập.
     *
     * @param  array{sizes?:?string,note?:?string}  $options
     * @return array{path:string,name:string,manifest:array}
     */
    public function build(Project $project, array $options = []): array
    {
        // [P0.5 — 2026-09-20] Lấy ẢNH MỚI NHẤT trước (orderByDesc), không phải ảnh cũ nhất.
        // Bản trước dùng orderBy('id') tăng dần + limit 60 ⇒ bộ sưu tập 100 ảnh đóng gói 60 ảnh
        // CŨ NHẤT, tức là bỏ đúng những ảnh vừa duyệt xong và gửi cho xưởng bộ cũ. Tệ hơn: không có
        // chỗ nào nói ảnh đã bị cắt, nên xưởng nhận thiếu mà không ai biết.
        $baseQuery = $project->generations()->whereNotNull('media_url');
        $totalWithMedia = (clone $baseQuery)->count();

        $generations = $baseQuery
            ->orderByDesc('id')
            ->limit(self::MAX_IMAGES)
            ->get();
        $truncated = $totalWithMedia > $generations->count();

        $tmp = tempnam(sys_get_temp_dir(), 'fabrikai-export-');
        if ($tmp === false) {
            throw new \RuntimeException('Không tạo được file tạm để đóng gói.');
        }
        // ZipArchive cần đuôi .zip để mở đúng định dạng.
        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Không mở được file ZIP để ghi.');
        }

        $note = trim((string) ($options['note'] ?? ''));
        $images = [];
        $skipped = [];

        foreach ($generations as $i => $g) {
            $bytes = $this->imageBytes((string) $g->media_url);
            if ($bytes === null) {
                $skipped[] = ['generation_id' => $g->id, 'url' => (string) $g->media_url, 'reason' => 'Không tải/đọc được ảnh.'];
                continue;
            }

            $n = count($images) + 1;
            $ext = $this->extensionFor($bytes);
            $base = Str::slug(Str::limit((string) ($g->prompt ?: 'anh'), 40, '')) ?: 'anh';
            $file = 'anh/'.sprintf('%02d', $n).'-'.$base.'.'.$ext;

            $zip->addFromString($file, $bytes);
            $images[] = [
                'n' => $n,
                'file' => $file,
                'generation_id' => $g->id,
                'type' => (string) $g->type,
                'model' => (string) $g->model,
                'provider' => (string) $g->provider,
                'resolution' => (string) $g->resolution,
                'credits_cost' => (int) $g->credits_cost,
                'created_at' => $g->created_at?->format('d/m/Y H:i'),
                'prompt' => (string) $g->prompt,
            ];
        }

        $manifest = [
            'brand' => 'FabrikAI',
            'exported_at' => now()->toIso8601String(),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'brief' => $project->brief,
                'deadline' => $project->deadline?->toDateString(),
                'tags' => $this->tags($project),
            ],
            'note' => $note !== '' ? $note : null,
            // Số ảnh THẬT của bộ sưu tập so với số ảnh có trong gói — để hệ thống của xưởng (và
            // người đọc manifest) biết gói này có bị cắt hay không, thay vì đoán.
            'images_total_in_collection' => $totalWithMedia,
            'images_in_bundle' => count($images),
            'truncated' => $truncated,
            'truncated_note' => $truncated
                ? 'Bộ sưu tập có '.$totalWithMedia.' ảnh; gói chỉ chứa '.self::MAX_IMAGES.' ảnh MỚI NHẤT. Ảnh còn lại tải riêng trong Thư viện FabrikAI.'
                : null,
            'images' => $images,
            'skipped' => $skipped,
            'disclaimer' => 'Ảnh do AI tạo — dùng làm ảnh THAM CHIẾU/ý tưởng. Vui lòng đối chiếu mẫu thật trước khi sản xuất hàng loạt.',
        ];

        $zip->addFromString('README.txt', $this->readme($project, count($images), count($skipped), $truncated ? $totalWithMedia : null));
        $zip->addFromString('thong-tin-bo-suu-tap.txt', $this->projectInfo($project, $note));
        $zip->addFromString('bang-size.csv', $this->sizeSheet((string) ($options['sizes'] ?? '')));
        $zip->addFromString('phieu-ky-thuat.txt', $this->techSheet($project, $images, $note));
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($skipped) {
            $lines = ["CÁC ẢNH KHÔNG TẢI ĐƯỢC VÀO GÓI", str_repeat('=', 32), ''];
            foreach ($skipped as $s) {
                $lines[] = '- Ảnh #'.$s['generation_id'].': '.$s['reason'];
                $lines[] = '  nguồn: '.$s['url'];
            }
            $lines[] = '';
            $lines[] = 'Ảnh gốc vẫn còn trong thư viện FabrikAI — tải lại thủ công nếu cần.';
            $zip->addFromString('anh/_KHONG_TAI_DUOC.txt', implode("\n", $lines));
        }

        $zip->close();

        return [
            'path' => $zipPath,
            'name' => 'fabrikai-'.(Str::slug(Str::limit($project->name, 40, '')) ?: 'bo-suu-tap').'-'.now()->format('Ymd').'.zip',
            'manifest' => $manifest,
        ];
    }

    /** Đọc bytes của một media_url: data URI · URL http(s) · file trong storage/public. */
    public function imageBytes(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'data:')) {
            $comma = strpos($url, ',');
            if ($comma === false) {
                return null;
            }
            $meta = substr($url, 5, $comma - 5);
            $data = substr($url, $comma + 1);
            $raw = str_contains($meta, 'base64') ? base64_decode($data, true) : rawurldecode($data);

            return is_string($raw) && $raw !== '' ? $raw : null;
        }

        if (preg_match('#^https?://#i', $url)) {
            try {
                return studio_fetch_remote_bytes($url, self::MAX_IMAGE_BYTES);
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Đường dẫn nội bộ: /storage/studio/... (đĩa public) hoặc file trong public_html.
        $rel = (string) parse_url($url, PHP_URL_PATH);
        $publicRel = ltrim(str_replace('\\', '/', $rel), '/');

        $abs = app(StudioLibraryService::class)->urlToPath($url);
        if ($abs && is_file($abs)) {
            $bytes = @file_get_contents($abs);

            return $bytes === false ? null : $bytes;
        }

        $safe = studio_safe_public_file($publicRel);
        if ($safe && is_file($safe)) {
            $bytes = @file_get_contents($safe);

            return $bytes === false ? null : $bytes;
        }

        // Ảnh demo nằm trong storage/app/public nhưng không có tiền tố storage/.
        if (! str_starts_with($publicRel, 'storage/')) {
            $diskPath = Storage::disk('public')->path($publicRel);
            if (is_file($diskPath)) {
                $bytes = @file_get_contents($diskPath);

                return $bytes === false ? null : $bytes;
            }
        }

        return null;
    }

    /**
     * `projects.tags` được cast thành ArrayObject ⇒ KHÔNG dùng trực tiếp được với implode()/mảng.
     * Chuẩn hoá về array thuần ở MỘT chỗ để mọi phần của gói dùng cùng một dạng dữ liệu.
     *
     * @return array<int, string>
     */
    private function tags(Project $project): array
    {
        $tags = $project->tags;

        if ($tags instanceof \Illuminate\Database\Eloquent\Casts\ArrayObject) {
            $tags = $tags->toArray();
        }

        return is_array($tags) ? array_values(array_map('strval', $tags)) : [];
    }

    private function extensionFor(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';

        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    /**
     * @param  int|null  $collectionTotal  Tổng số ảnh THẬT của bộ sưu tập khi gói bị cắt (null = không cắt).
     */
    private function readme(Project $project, int $imageCount, int $skipped, ?int $collectionTotal = null): string
    {
        $lines = [
            'GÓI SẢN XUẤT — '.$project->name,
            str_repeat('=', 60),
            '',
            'Gói này gồm:',
            '  · anh/            '.$imageCount.' ảnh tham chiếu (đánh số theo thứ tự)',
            '  · bang-size.csv   bảng size — điền số đo thật của xưởng trước khi cắt',
            '  · phieu-ky-thuat.txt  phiếu kỹ thuật từng mẫu (chất liệu · màu · đường may · ghi chú)',
            '  · thong-tin-bo-suu-tap.txt  thông tin bộ sưu tập + yêu cầu của khách',
            '  · manifest.json   bản đọc được bằng máy (mã ảnh, model, câu lệnh đã dùng)',
            '',
            'LƯU Ý QUAN TRỌNG',
            '  · Ảnh trong gói do AI tạo, dùng làm ẢNH THAM CHIẾU/ý tưởng thiết kế.',
            '    KHÔNG in hoặc cắt thẳng theo ảnh AI — hãy đối chiếu mẫu thật (màu vải, độ rũ,',
            '    chi tiết đường may) trước khi sản xuất hàng loạt.',
            '  · Mọi ô còn trống trong phiếu kỹ thuật là phần xưởng cần xác nhận với khách.',
        ];
        if ($collectionTotal !== null) {
            $lines[] = '';
            $lines[] = 'LƯU Ý VỀ SỐ LƯỢNG: bộ sưu tập có '.$collectionTotal.' ảnh, gói này chứa '.$imageCount
                .' ảnh MỚI NHẤT (giới hạn '.self::MAX_IMAGES.' ảnh/gói). Nếu xưởng cần đủ bộ,';
            $lines[] = '  hãy yêu cầu designer xuất thêm một gói nữa hoặc tải ảnh còn lại trong Thư viện FabrikAI.';
        }
        if ($skipped > 0) {
            $lines[] = '';
            $lines[] = 'CẢNH BÁO: có '.$skipped.' ảnh KHÔNG tải được vào gói — xem anh/_KHONG_TAI_DUOC.txt.';
        }

        return implode("\n", $lines)."\n";
    }

    private function projectInfo(Project $project, string $note): string
    {
        $rows = [
            'Tên bộ sưu tập' => $project->name,
            'Trạng thái' => (string) $project->status,
            'Hạn chót' => $project->deadline?->format('d/m/Y') ?? '—',
            'Mùa / vụ (tags)' => implode(', ', $this->tags($project)) ?: '—',
            'Ngày xuất gói' => now()->format('d/m/Y H:i'),
        ];

        $out = ["THÔNG TIN BỘ SƯU TẬP", str_repeat('=', 40), ''];
        foreach ($rows as $k => $v) {
            $out[] = $k.': '.$v;
        }
        $out[] = '';
        $out[] = 'YÊU CẦU CỦA KHÁCH (brief)';
        $out[] = str_repeat('-', 40);
        $out[] = trim((string) $project->brief) !== '' ? (string) $project->brief : '(chưa ghi)';
        if ($note !== '') {
            $out[] = '';
            $out[] = 'GHI CHÚ KHI XUẤT GÓI';
            $out[] = str_repeat('-', 40);
            $out[] = $note;
        }
        if (trim((string) $project->base_concept) !== '') {
            $out[] = '';
            $out[] = 'Ý TƯỞNG GỐC (base concept)';
            $out[] = str_repeat('-', 40);
            $out[] = (string) $project->base_concept;
        }

        return implode("\n", $out)."\n";
    }

    /** Bảng size CSV: dùng phần người dùng nhập, nếu không có thì phát MẪU để xưởng điền. */
    private function sizeSheet(string $sizes): string
    {
        $header = 'size,nguc(cm),eo(cm),hong(cm),dai_ao(cm),dai_tay(cm),ghi_chu';
        $sizes = trim($sizes);

        if ($sizes === '') {
            return $header."\nS,,,,,,\nM,,,,,,\nL,,,,,,\nXL,,,,,,\n";
        }

        $lines = preg_split('/\r\n|\r|\n/', $sizes) ?: [];
        $out = [$header];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Chấp nhận cả dấu phẩy, chấm phẩy và tab làm dấu phân cách.
            $cells = preg_split('/\s*[;\t]\s*|\s*,\s*/', $line) ?: [];
            $out[] = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $cells));
        }

        return implode("\n", $out)."\n";
    }

    /**
     * Phiếu kỹ thuật cho XƯỞNG: mỗi mẫu một khối, các ô cần xác nhận để trống cho xưởng điền.
     *
     * @param  array<int, array<string, mixed>>  $images
     */
    private function techSheet(Project $project, array $images, string $note): string
    {
        $out = ['PHIẾU KỸ THUẬT — '.$project->name, str_repeat('=', 60), ''];
        if ($note !== '') {
            $out[] = 'Ghi chú chung: '.$note;
            $out[] = '';
        }

        foreach ($images as $img) {
            $out[] = 'MẪU '.sprintf('%02d', $img['n']).' — '.$img['file'];
            $out[] = str_repeat('-', 60);
            $out[] = 'Ảnh tham chiếu : '.$img['file'];
            $out[] = 'Mô tả (AI)     : '.Str::limit((string) $img['prompt'], 300);
            $out[] = 'Model đã dùng  : '.$img['provider'].' · '.$img['model'].' · '.$img['resolution'].' · '.$img['created_at'];
            $out[] = 'Chất liệu      : ......................................';
            $out[] = 'Màu / mã vải   : ......................................';
            $out[] = 'Đường may      : ......................................';
            $out[] = 'Chi tiết cần lưu ý: ...................................';
            $out[] = 'Số đo theo size: xem bang-size.csv';
            $out[] = '';
        }

        if (! $images) {
            $out[] = '(Bộ sưu tập chưa có ảnh nào — hãy tạo ảnh trong Studio rồi xuất lại gói.)';
            $out[] = '';
        }

        $out[] = 'XÁC NHẬN';
        $out[] = 'Người lập phiếu: ....................  Ngày: ....../....../......';
        $out[] = 'Xưởng xác nhận : ....................  Ngày: ....../....../......';

        return implode("\n", $out)."\n";
    }
}
