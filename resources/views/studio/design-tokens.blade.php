@extends('layouts.app')

@section('title', 'Hệ thống thiết kế — token hai theme')
@section('meta')
    <meta name="robots" content="noindex, nofollow">
@endsection

@section('content')
{{--
    TRANG XEM TOKEN (2026-09-23) — mở nợ đã ghi trong DEPLOY_LOG: "số liệu tương phản chỉ nằm trong
    test, người thiết kế không có chỗ nào xem".

    Vì sao server-render, không phải app JS: đây là trang ĐỌC số liệu của chính mã nguồn. Nó dùng
    App\Support\ThemePalette — CÙNG lớp mà tests/Feature/ThemeSystemTest.php dùng — nên nếu ai sửa
    token trong resources/css/app.css thì trang này và test luôn nói cùng một con số (không có nguồn
    thứ hai để lệch).
--}}
<div class="studio-shell min-h-screen">
    <div class="container-x py-8">
        <header class="mb-6">
            <p class="kicker">FabrikAI · dành cho người thiết kế</p>
            <h1 class="mt-1 font-display text-2xl font-semibold text-cream-50">Bảng token hai theme</h1>
            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-cream-200">
                Mọi con số dưới đây được TÍNH từ <b class="text-cream-100">resources/css/app.css</b> bằng công thức
                tương phản WCAG 2.1 (độ chói tương đối), không phải ước lượng bằng mắt. Ngưỡng AA cho chữ
                thường là <b class="text-cream-100">{{ $aa }}:1</b>. Bậc nào không đạt sẽ hiện ĐỎ — và bộ test
                <b class="text-cream-100">ThemeSystemTest</b> cũng đỏ, nên không thể deploy một bảng lệch chuẩn.
            </p>
        </header>

        @foreach ($themes as $name => $matrix)
            <section class="mb-8">
                <h2 class="font-display text-lg font-semibold text-cream-50">Theme {{ $name }}</h2>
                <p class="mt-1 text-body text-cream-300">
                    Nền trang <span class="font-semibold text-cream-100">{{ $matrix['tokens']['ink-950'] }}</span> ·
                    nền card <span class="font-semibold text-cream-100">{{ $matrix['tokens']['ink-800'] }}</span> ·
                    chữ chính <span class="font-semibold text-cream-100">{{ $matrix['tokens']['cream-100'] }}</span>
                </p>

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[46rem] border-collapse text-left text-xs">
                        <thead>
                            <tr class="border-b border-ink-700 text-label uppercase tracking-wide text-cream-300">
                                <th class="py-2 pr-3">Bậc nội dung</th>
                                <th class="py-2 pr-3">Màu</th>
                                <th class="py-2 pr-3">Chữ</th>
                                @foreach ($surfaces as $surface)
                                    <th class="py-2 pr-3">{{ $surface }}</th>
                                @endforeach
                                <th class="py-2">Đạt AA?</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($matrix['rows'] as $row)
                                <tr class="border-b border-ink-700/60">
                                    <td class="py-2 pr-3 font-semibold text-cream-100">{{ $row['token'] }}</td>
                                    <td class="py-2 pr-3 font-mono text-body text-cream-300">{{ $row['hex'] }}</td>
                                    <td class="py-2 pr-3">
                                        <span class="rounded px-2 py-1 text-xs" style="background: {{ $matrix['tokens']['ink-800'] }}; color: {{ $row['hex'] }}">Aa</span>
                                    </td>
                                    @foreach ($row['ratios'] as $ratio)
                                        <td class="py-2 pr-3 tabular-nums {{ $ratio >= $aa ? 'text-cream-200' : 'text-danger' }}">{{ number_format($ratio, 2, ',', '.') }}</td>
                                    @endforeach
                                    <td class="py-2">
                                        @if ($row['pass'])
                                            <span class="rounded-full bg-ok/15 px-2 py-0.5 text-label font-semibold text-ok">ĐẠT</span>
                                        @else
                                            <span class="rounded-full bg-danger/15 px-2 py-0.5 text-label font-semibold text-danger">KHÔNG ĐẠT</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <h3 class="mt-5 text-sm font-semibold text-cream-100">Màu nhấn &amp; màu trạng thái (cũng là CHỮ ⇒ cũng phải đạt AA)</h3>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($accents[$name] as $accent)
                        <span class="flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 px-3 py-2 text-body">
                            <span class="h-3 w-3 rounded-full" style="background: {{ $accent['hex'] }}"></span>
                            <span class="font-semibold text-cream-100">{{ $accent['token'] }}</span>
                            <span class="font-mono text-cream-300">{{ $accent['hex'] }}</span>
                            <span class="tabular-nums {{ $accent['pass'] ? 'text-ok' : 'text-danger' }}">{{ number_format($accent['min'], 2, ',', '.') }}:1</span>
                        </span>
                    @endforeach
                </div>
            </section>
        @endforeach

        <section class="mb-8">
            <h2 class="font-display text-lg font-semibold text-cream-50">Token CỐ ĐỊNH (không đổi theo theme)</h2>
            <p class="mt-1 max-w-3xl text-body leading-relaxed text-cream-300">
                Đây là môi trường ẢNH và các khối đảo màu: nền canvas người dùng tự chọn, lớp phủ đặt trên ảnh,
                nút đảo màu. Nếu chúng theo theme thì ở theme Sáng, "nền Kem" sẽ thành nền ĐEN và chữ trên lớp phủ
                ảnh sẽ thành chữ đen trên nền tối (đo được 2,9:1 trước khi tách token).
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($fixed as $token)
                    @isset($fixedValues[$token])
                        <span class="flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 px-3 py-2 text-body">
                            <span class="h-3 w-3 rounded-full border border-ink-600" style="background: {{ $fixedValues[$token] }}"></span>
                            <span class="font-semibold text-cream-100">--color-{{ $token }}</span>
                            <span class="font-mono text-cream-300">{{ $fixedValues[$token] }}</span>
                        </span>
                    @endisset
                @endforeach
            </div>
        </section>

        <footer class="rounded-lg border border-ink-700 bg-ink-900 p-4 text-body leading-relaxed text-cream-300">
            Luật đầy đủ nằm ở <b class="text-cream-100">docs/DESIGN_SYSTEM.md §1.1</b> (màu) và <b class="text-cream-100">§1.4</b> (cách theme hoạt động).
            Đổi giao diện: <b class="text-cream-100">Cài đặt của tôi → Giao diện</b> hoặc nút ở thanh trạng thái Studio.
            Bảng này chỉ ĐỌC — không có ô nhập nào ở đây, vì token là việc của mã nguồn, không phải của người dùng cuối.
        </footer>
    </div>
</div>
@endsection
