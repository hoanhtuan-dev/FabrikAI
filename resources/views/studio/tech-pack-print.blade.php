<!DOCTYPE html>
<html lang="vi">
{{--
    BẢN IN PHIẾU KỸ THUẬT (A4) — Việc #3, 2026-09-26.

    VÌ SAO KHÔNG @extends('layouts.app'): đây là trang IN. Vỏ ứng dụng (thanh điều hướng, rail, nút) sẽ
    in ra giấy cùng phiếu và ăn mất chỗ của bảng thông số. Trang này tự đủ: một tệp HTML, CSS nội tuyến,
    KHÔNG @vite — nhờ vậy bản in giống nhau trên mọi máy và không phụ thuộc việc build asset.

    VÌ SAO BẢNG THÔNG SỐ IN BẰNG <pre>: nội dung bảng do CHÍNH TechPackService::measurementTable() dựng
    sẵn (đã canh cột) — cùng nguồn với tệp gửi xưởng trong gói ZIP. Dựng lại bảng bằng HTML ở đây là tạo
    bản sao thứ hai, và hai bản sao sẽ lệch nhau ngay lần sửa đầu (bài học của cả repo này).

    ĐƯỜNG TẠO PDF: nút "In / Lưu thành PDF" gọi window.print(). Không thêm thư viện PDF vì máy chủ dùng
    chung chặn proc_open (xem DEPLOY_LOG) — xem thêm chú thích ở TechPackController::print.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Phiếu kỹ thuật — {{ $project->name }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; background: #f4f4f5; color: #18181b;
               font: 13px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .sheet { max-width: 210mm; margin: 0 auto; background: #fff; padding: 18mm 16mm; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        h1 { font-size: 19px; margin: 0 0 2px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; margin: 20px 0 6px;
             border-bottom: 1px solid #d4d4d8; padding-bottom: 4px; }
        .sub { color: #52525b; font-size: 12px; }
        .meta { display: flex; flex-wrap: wrap; gap: 4px 22px; margin-top: 8px; font-size: 12px; color: #3f3f46; }
        .meta b { color: #18181b; }
        table.fields { width: 100%; border-collapse: collapse; }
        table.fields th { width: 34%; text-align: left; vertical-align: top; font-weight: 600;
                          padding: 5px 8px 5px 0; color: #3f3f46; border-bottom: 1px solid #f1f1f3; }
        table.fields td { padding: 5px 0; border-bottom: 1px solid #f1f1f3; white-space: pre-wrap; }
        table.fields td.empty { color: #a1a1aa; letter-spacing: .1em; }
        pre { font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
              background: #fafafa; border: 1px solid #e4e4e7; border-radius: 4px; padding: 10px; overflow-x: auto; }
        .warn { border: 1px solid #f59e0b; background: #fffbeb; border-radius: 4px; padding: 8px 10px; margin-top: 10px; font-size: 12px; }
        .imgs { margin: 0; padding-left: 18px; font-size: 12px; color: #3f3f46; }
        .sign { display: flex; justify-content: space-between; gap: 24px; margin-top: 34px; font-size: 12px; }
        .sign div { flex: 1; border-top: 1px solid #a1a1aa; padding-top: 5px; color: #52525b; }
        .screen-only { max-width: 210mm; margin: 0 auto 14px; display: flex; gap: 10px; align-items: center; }
        .btn { border: 0; border-radius: 6px; padding: 9px 16px; font-size: 13px; font-weight: 600;
               background: #18181b; color: #fff; cursor: pointer; }
        .btn.ghost { background: #fff; color: #18181b; border: 1px solid #d4d4d8; text-decoration: none; }
        @media print {
            body { background: #fff; padding: 0; font-size: 12px; }
            .sheet { max-width: none; margin: 0; padding: 0; box-shadow: none; }
            .screen-only { display: none; }
            pre { background: #fff; }
            h2 { margin-top: 14px; }
        }
    </style>
</head>
<body>
    <div class="screen-only">
        <button type="button" class="btn" onclick="window.print()">In / Lưu thành PDF</button>
        <a class="btn ghost" href="/">Về Studio</a>
        <span class="sub">Trong hộp thoại in, chọn đích là “Lưu thành PDF”.</span>
    </div>

    <div class="sheet">
        <h1>PHIẾU KỸ THUẬT</h1>
        <p class="sub">{{ $project->name }} · FabrikAI</p>

        <div class="meta">
            <span>Mã hàng: <b>{{ $pack['fields']['style_no'] !== '' ? $pack['fields']['style_no'] : '—' }}</b></span>
            <span>Nhóm hàng: <b>{{ $pack['fields']['category'] !== '' ? $pack['fields']['category'] : '—' }}</b></span>
            <span>In lúc: <b>{{ $generatedAt->format('d/m/Y H:i') }}</b></span>
            @if ($updatedAt)
                <span>Cập nhật phiếu: <b>{{ \Illuminate\Support\Carbon::parse($updatedAt)->format('d/m/Y H:i') }}</b></span>
            @endif
        </div>

        @unless ($completeness['ready'])
            <div class="warn">
                <b>Phiếu còn thiếu:</b> {{ implode(' · ', $completeness['missing']) }}.
                Xưởng vui lòng xác nhận lại các mục này trước khi cắt.
            </div>
        @endunless

        <h2>Thông số</h2>
        <table class="fields">
            <tbody>
                @foreach ($shape['fields'] as $field)
                    <tr>
                        <th>{{ $field['label'] }}</th>
                        @php $value = $pack['fields'][$field['key']] ?? ''; @endphp
                        @if ($value !== '')
                            <td>{{ $value }}</td>
                        @else
                            <td class="empty">………………………………</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Bảng thông số (cm)</h2>
        <pre>{{ implode("\n", $table) }}</pre>

        <h2>Ảnh tham chiếu</h2>
        @if (count($images) === 0)
            <p class="sub">Bộ sưu tập chưa có ảnh nào. Ảnh do AI tạo là ảnh THAM CHIẾU — đối chiếu mẫu thật
                trước khi sản xuất hàng loạt.</p>
        @else
            <p class="sub">Ảnh gốc nằm trong gói ZIP xuất cho xưởng. Danh sách dưới đây để đối chiếu nhanh
                (ảnh do AI tạo — dùng làm tham chiếu, không in/cắt thẳng từ ảnh).</p>
            <ol class="imgs">
                @foreach (array_slice($images, 0, 24) as $img)
                    <li>#{{ $img['id'] }} — {{ \Illuminate\Support\Str::limit($img['prompt'], 110) }}</li>
                @endforeach
            </ol>
            @if (count($images) > 24)
                <p class="sub">… và {{ count($images) - 24 }} ảnh nữa trong gói ZIP.</p>
            @endif
        @endif

        <div class="sign">
            <div>Người lập phiếu — Ngày ....../....../......</div>
            <div>Xưởng xác nhận — Ngày ....../....../......</div>
        </div>
    </div>
</body>
</html>
