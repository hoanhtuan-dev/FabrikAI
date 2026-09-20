@extends('layouts.app')

@section('title', 'Hệ thống thiết kế — thư viện theme & token')
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
            <h1 class="mt-1 font-display text-2xl font-semibold text-cream-50">Thư viện theme &amp; bảng token</h1>
            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-cream-200">
                Mọi con số dưới đây được TÍNH từ <b class="text-cream-100">resources/css/app.css</b> bằng công thức
                tương phản WCAG 2.1 (độ chói tương đối), không phải ước lượng bằng mắt. Ngưỡng AA cho chữ
                thường là <b class="text-cream-100">{{ $aa }}:1</b>. Bậc nào không đạt sẽ hiện ĐỎ — và bộ test
                <b class="text-cream-100">ThemeSystemTest</b> cũng đỏ, nên không thể deploy một bảng lệch chuẩn.
            </p>
        </header>

        {{-- ══════════════════════════════════════════════════════════════════════════════════
             THƯ VIỆN THEME (2026-09-25) — import liên kết daisyUI Theme Generator.

             Vì sao nằm cùng trang với bảng token: chọn bảng màu và ĐO bảng màu đó là hai nửa của
             một việc. Trang này là chỗ duy nhất vừa đổi được theme vừa nhìn thấy ngay tỉ lệ tương
             phản của theme đang chạy — không có hai trang để lệch số.

             Vì sao form POST thường (không Vue/fetch): thao tác này đổi màu cho TOÀN BỘ sản phẩm và
             chỉ làm vài lần một năm. Một form thật chạy được kể cả khi bundle JS hỏng.
        --}}
        <section class="mb-10" id="thu-vien-theme">
            <h2 class="font-display text-lg font-semibold text-cream-50">Thư viện theme</h2>
            <p class="mt-1 max-w-3xl text-body leading-relaxed text-cream-300">
                Dán liên kết từ <span class="font-mono text-cream-200">daisyui.com/theme-generator</span> rồi bấm
                <b class="text-cream-100">Import</b>. Mỗi liên kết được lưu thành <b class="text-cream-100">nhiều theme</b>:
                bản gốc trong liên kết, và bản đối ứng Sáng/Tối tự sinh (giữ nguyên hue, hạ sắc độ màu trạng thái
                cho đạt WCAG AA) — nhờ vậy đổi một chế độ là chế độ còn lại cũng đổi theo, không nói hai thứ tiếng khác nhau.
                Dán nhiều dòng thì import nhiều liên kết một lượt (tối đa {{ \App\Support\DaisyThemeLink::MAX_THEMES_PER_IMPORT }} theme mỗi lượt).
            </p>

            @if (session('theme_status'))
                @php($status = session('theme_status'))
                <div class="mt-3 rounded-lg border p-3 {{ $status['kind'] === 'ok' ? 'border-ok/40 bg-ok/10' : 'border-warn/40 bg-warn/10' }}">
                    <p class="text-sm font-semibold {{ $status['kind'] === 'ok' ? 'text-ok' : 'text-warn' }}">{{ $status['title'] }}</p>
                    @if (! empty($status['lines']))
                        <ul class="mt-1.5 space-y-0.5 text-body text-cream-200">
                            @foreach ($status['lines'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @error('link')
                <div class="mt-3 rounded-lg border border-danger/40 bg-danger/10 p-3">
                    <p class="text-sm font-semibold text-danger">Không import được liên kết này</p>
                    <p class="mt-1 text-body leading-relaxed text-cream-200">{{ $message }}</p>
                </div>
            @enderror

            <form method="POST" action="{{ route('theme.import') }}" class="mt-3 rounded-xl border border-ink-700 bg-ink-900 p-4">
                @csrf
                <label for="theme-link" class="text-xs font-semibold text-cream-100">Liên kết theme (mỗi dòng một liên kết)</label>
                <textarea id="theme-link" name="link" rows="4" required
                          placeholder="https://daisyui.com/theme-generator/#theme=eJx…"
                          class="mt-1.5 w-full rounded-lg border border-ink-600 bg-ink-950 p-2.5 font-mono text-body text-cream-100 placeholder:text-cream-400 focus:border-brand-500 focus:outline-none">{{ old('link') }}</textarea>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <button type="submit" class="motion-ui rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-500">
                        Import theme
                    </button>
                    <span class="text-label text-cream-400">
                        Định dạng đọc được: liên kết đầy đủ · phần <span class="font-mono">#theme=…</span> · hoặc chính chuỗi payload.
                    </span>
                </div>
            </form>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[60rem] border-collapse text-left text-xs">
                    <thead>
                        <tr class="border-b border-ink-700 text-label uppercase tracking-wide text-cream-300">
                            <th class="py-2 pr-3">Theme</th>
                            <th class="py-2 pr-3">Chế độ</th>
                            <th class="py-2 pr-3">Nguồn</th>
                            <th class="py-2 pr-3">Bảng màu</th>
                            <th class="py-2 pr-3">Trạng thái</th>
                            <th class="py-2">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php($seenBatches = [])
                        @foreach ($library['rows'] as $row)
                            <tr class="border-b border-ink-700/60 align-top">
                                <td class="py-2.5 pr-3">
                                    <p class="font-semibold text-cream-100">{{ $row['name'] }}</p>
                                    @if ($row['created_at'])
                                        <p class="mt-0.5 text-label text-cream-400">
                                            {{ $row['created_at']->format('d/m/Y H:i') }}@if ($row['author']) · {{ $row['author'] }}@endif
                                        </p>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3 text-cream-200">{{ $row['scheme'] === 'light' ? 'Sáng' : 'Tối' }}</td>
                                <td class="py-2.5 pr-3">
                                    <span class="rounded-full px-2 py-0.5 text-label font-semibold
                                        {{ $row['source'] === 'builtin' ? 'bg-brand-600/20 text-brand-100' : ($row['source'] === 'derived' ? 'bg-ink-700 text-cream-200' : 'bg-ok/15 text-ok') }}">
                                        {{ $row['source'] === 'builtin' ? 'Gốc của FabrikAI' : ($row['source'] === 'derived' ? 'Tự sinh' : 'Từ liên kết') }}
                                    </span>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <span class="flex flex-wrap gap-1">
                                        @foreach (['--color-base-300', '--color-base-100', '--color-base-content', '--color-primary', '--color-secondary', '--color-accent', '--color-error', '--color-success'] as $key)
                                            @isset($row['tokens'][$key])
                                                <span class="h-4 w-4 rounded border border-ink-600" style="background: {{ $row['tokens'][$key] }}" title="{{ str_replace('--color-', '', $key) }} {{ $row['tokens'][$key] }}"></span>
                                            @endisset
                                        @endforeach
                                    </span>
                                </td>
                                <td class="py-2.5 pr-3">
                                    @if ($row['active'])
                                        <span class="rounded-full bg-ok/15 px-2 py-0.5 text-label font-semibold text-ok">ĐANG DÙNG</span>
                                    @else
                                        <span class="text-label text-cream-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if (! $row['active'])
                                            @if ($row['theme'])
                                                <form method="POST" action="{{ route('theme.activate', $row['theme']) }}">
                                                    @csrf
                                                    <button type="submit" class="rounded-md border border-brand-500 px-2.5 py-1 text-label font-semibold text-brand-100 hover:bg-brand-600/20">
                                                        Bật cho chế độ {{ $row['scheme'] === 'light' ? 'Sáng' : 'Tối' }}
                                                    </button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('theme.reset', $row['scheme']) }}">
                                                    @csrf
                                                    <button type="submit" class="rounded-md border border-ink-600 px-2.5 py-1 text-label font-semibold text-cream-200 hover:border-brand-400">
                                                        Dùng bảng màu gốc
                                                    </button>
                                                </form>
                                            @endif
                                        @endif

                                        @if ($row['theme'])
                                            <form method="POST" action="{{ route('theme.destroy', $row['theme']) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded-md border border-danger/40 px-2.5 py-1 text-label font-semibold text-danger hover:bg-danger/10">
                                                    Xoá
                                                </button>
                                            </form>

                                            @if ($row['batch'] && ! in_array($row['batch'], $seenBatches, true))
                                                @php($seenBatches[] = $row['batch'])
                                                <form method="POST" action="{{ route('theme.batch.destroy', $row['batch']) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-md border border-ink-600 px-2.5 py-1 text-label font-semibold text-cream-300 hover:border-danger/40 hover:text-danger">
                                                        Hoàn tác cả lô import
                                                    </button>
                                                </form>
                                            @endif

                                            <details class="w-full">
                                                <summary class="cursor-pointer text-label text-cream-400 hover:text-cream-200">Liên kết của theme này</summary>
                                                <textarea readonly rows="3" class="mt-1 w-full rounded border border-ink-600 bg-ink-950 p-2 font-mono text-label text-cream-200">{{ $row['theme']->link() }}</textarea>
                                                <p class="mt-1 text-label text-cream-400">
                                                    Dán lại liên kết này vào trang Theme Generator để chỉnh tiếp — kể cả với bản đối ứng tự sinh.
                                                </p>
                                            </details>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-2 text-body leading-relaxed text-cream-400">
                Chế độ <b class="text-cream-200">Tối</b> đang dùng:
                <b class="text-cream-100">{{ $library['active']['dark']?->name ?? \App\Support\ThemeLibrary::BUILTIN_NAME['dark'].' (gốc)' }}</b>
                · chế độ <b class="text-cream-200">Sáng</b> đang dùng:
                <b class="text-cream-100">{{ $library['active']['light']?->name ?? \App\Support\ThemeLibrary::BUILTIN_NAME['light'].' (gốc)' }}</b>.
            </p>
        </section>

        <h2 class="mb-4 font-display text-lg font-semibold text-cream-50">Bảng token hai theme</h2>

        @foreach ($themes as $name => $matrix)
            <section class="mb-8">
                <h3 class="font-display text-lg font-semibold text-cream-50">Theme {{ $name }}</h3>
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

                <h4 class="mt-5 text-sm font-semibold text-cream-100">Màu nhấn &amp; màu trạng thái (cũng là CHỮ ⇒ cũng phải đạt AA)</h4>
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
            Bảng token bên dưới là phần ĐỌC (token là việc của mã nguồn). Phần GHI duy nhất nằm ở mục
            Thư viện theme phía trên: dán liên kết daisyUI Theme Generator vào đó để đổi bảng màu.
        </footer>
    </div>
</div>
@endsection
