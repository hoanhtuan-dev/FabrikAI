@extends('layouts.app')
@section('title', 'Bảng giá')
@section('meta')
    <meta name="description" content="Bảng giá FabrikAI — studio thiết kế thời trang AI cho nhà thiết kế, chủ thương hiệu và chủ xưởng may. Ảnh 2K, credit rõ ràng, dùng thử miễn phí.">
    <link rel="canonical" href="{{ url('/bang-gia') }}">
@endsection
@section('content')
{{--
    Vì sao SVG ở đây là markup TĨNH (không render từ icons.json qua PHP):
    bất biến bảo mật của repo cấm MỌI cú pháp in thô (raw echo) trong resources/views
    (tests/Feature/StudioXssSinksTest::test_blades_contain_no_raw_output_sink), mà muốn phát SVG từ
    registry thì buộc phải in thô. Ở đây chỉ dùng 4 icon TRANG TRÍ, copy nguyên path từ
    resources/js/studio/icons.json (nguồn icon của app vẫn là file đó — trang này không tự vẽ icon mới).
--}}
@php
    $loggedIn = auth()->check();
    $ctaHref = $loggedIn ? url('/') : route('register');
    $ctaLabel = $loggedIn ? 'Vào Studio' : 'Dùng thử miễn phí';
    $vn = fn (int $n) => number_format($n, 0, ',', '.');
    $personaIcons = [
        'pencil' => '<path d="M21.17 6.81a1 1 0 0 0-3.99-3.99L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.36a.5.5 0 0 0 .62.62l4.36-1.32a2 2 0 0 0 .83-.5z"/><path d="m15 5 4 4"/>',
        'briefcase' => '<rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'scissors' => '<circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/>',
    ];
    $checkPath = '<path d="m20 6-9 11-5-5"/>';
@endphp
<div class="studio-dark min-h-screen">
    {{-- ── Thanh điều hướng ── --}}
    <header class="sticky top-0 z-40 border-b border-ink-700 bg-ink-900/95 backdrop-blur">
        <div class="container-x flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
            <a href="{{ url('/') }}" class="flex items-center gap-2">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">F</span>
                <span class="font-display text-base font-semibold text-cream-50">FabrikAI</span>
            </a>
            <nav class="ml-auto flex flex-wrap items-center gap-1.5 text-xs">
                <a href="#goi" class="rounded-lg px-3 py-2 font-semibold text-cream-200 hover:bg-ink-800">Các gói</a>
                <a href="#so-sanh" class="rounded-lg px-3 py-2 font-semibold text-cream-200 hover:bg-ink-800">So sánh</a>
                <a href="#faq" class="rounded-lg px-3 py-2 font-semibold text-cream-200 hover:bg-ink-800">Hỏi đáp</a>
                @if($loggedIn)
                    <a href="{{ url('/') }}" class="btn-brand btn-sm">Vào Studio</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 font-semibold text-cream-200 hover:bg-ink-800">Đăng nhập</a>
                    <a href="{{ route('register') }}" class="btn-brand btn-sm">Dùng thử miễn phí</a>
                @endif
            </nav>
        </div>
    </header>

    {{-- ── Mở đầu ── --}}
    <section class="container-x py-12 sm:py-16">
        {{-- KHÔNG dùng class .kicker ở đây: nó là màu cho theme SÁNG (brand-600 trên nền kem) —
             trên nền tối chỉ đạt tương phản 3.04, dưới ngưỡng WCAG AA. --}}
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-300">Bảng giá · dành cho người làm nghề</p>
        <h1 class="mt-2 max-w-3xl font-display text-3xl font-semibold leading-tight text-cream-50 sm:text-4xl">
            Ảnh thời trang chuyên nghiệp trong vài phút — không cần studio, không cần nhiếp ảnh gia
        </h1>
        <p class="mt-4 max-w-2xl text-sm leading-relaxed text-cream-300">
            FabrikAI là studio thiết kế bằng AI cho người Việt: tạo ảnh sản phẩm, biến thể phom dáng,
            thay người mẫu, ghép trang phục và dựng lookbook — tất cả trong một luồng làm việc, tính bằng credit.
            Giá dưới đây là giá thật đang chạy trên hệ thống, không phải bảng giá mẫu.
        </p>
        <div class="mt-6 flex flex-wrap items-center gap-3">
            <a href="{{ $ctaHref }}" class="btn-brand">{{ $ctaLabel }}</a>
            @if($freePlan)
                {{-- Cố ý KHÔNG ghi con số cụ thể: số credit dùng thử do cột `users.credits_balance`
                     (default trong migration) quyết định, ghi cứng ở đây sẽ lệch khi đổi default. --}}
                <span class="text-xs text-cream-300">Tài khoản mới được tặng credit dùng thử — không cần thẻ thanh toán.</span>
            @endif
        </div>
        <div class="mt-6 flex flex-wrap gap-2">
            @foreach(['Ảnh tới 2K', 'Tiếng Việt', 'Giá VNĐ', 'Không cần biết về AI'] as $chip)
                <span class="rounded-full border border-ink-700 bg-ink-900 px-3 py-1 text-[11px] font-semibold text-cream-200">{{ $chip }}</span>
            @endforeach
        </div>
    </section>

    {{-- ── Ba nhóm khách hàng ── --}}
    <section class="border-y border-ink-800 bg-ink-900/40 py-12">
        <div class="container-x">
            <h2 class="font-display text-2xl font-semibold text-cream-50">Bạn làm nghề gì — và cần gì mỗi ngày?</h2>
            <p class="mt-2 max-w-2xl text-sm text-cream-300">Cùng một hệ thống, nhưng cách dùng khác nhau. Chọn đúng cách bắt đầu sẽ tiết kiệm thời gian nhất.</p>
            <div class="mt-6 grid gap-4 md:grid-cols-3">
                @foreach($personas as $persona)
                    @php
                        $startPlan = $plans->firstWhere('slug', $persona['start']);
                        $growPlan = $plans->firstWhere('slug', $persona['grow']);
                    @endphp
                    <article class="card flex flex-col p-5">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-600/20 text-brand-200">
                            @if($persona['icon'] === 'pencil')
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.17 6.81a1 1 0 0 0-3.99-3.99L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.36a.5.5 0 0 0 .62.62l4.36-1.32a2 2 0 0 0 .83-.5z"/><path d="m15 5 4 4"/></svg>
                            @elseif($persona['icon'] === 'briefcase')
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            @else
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/></svg>
                            @endif
                        </span>
                        <h3 class="mt-3 font-display text-lg font-semibold text-cream-50">{{ $persona['title'] }}</h3>
                        <p class="mt-2 text-xs leading-relaxed text-cream-300">{{ $persona['pain'] }}</p>
                        <ul class="mt-3 flex-1 space-y-1.5">
                            @foreach($persona['want'] as $want)
                                <li class="flex items-start gap-2 text-xs text-cream-200">
                                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20 6-9 11-5-5"/></svg>
                                    {{ $want }}
                                </li>
                            @endforeach
                        </ul>
                        @if($startPlan)
                            <p class="mt-4 rounded-lg border border-ink-700 bg-ink-900/60 p-2.5 text-[11px] text-cream-300">
                                Bắt đầu ở <a href="#goi" class="font-semibold text-brand-200 hover:underline">{{ $startPlan->name }}</a>
                                @if($growPlan && $growPlan->id !== $startPlan->id)
                                    · lớn lên ở <span class="font-semibold text-cream-100">{{ $growPlan->name }}</span>
                                @endif
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Các gói (dữ liệu thật từ DB) ── --}}
    <section id="goi" class="container-x py-12 sm:py-16">
        <h2 class="font-display text-2xl font-semibold text-cream-50">Các gói đang mở bán</h2>
        <p class="mt-2 max-w-2xl text-sm text-cream-300">
            Credit là đơn vị công việc: mỗi ảnh hoặc video tốn một số credit tuỳ gói. Credit không dùng hết sẽ còn lại trong tài khoản.
        </p>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($plans as $plan)
                @php $badges = $recommended[$plan->slug] ?? []; @endphp
                <article class="card flex flex-col p-5 {{ $plan->isFree() ? '' : 'ring-1 ring-inset ring-brand-500/20' }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-display text-lg font-semibold text-cream-50">{{ $plan->name }}</h3>
                        @if($plan->isFree())
                            <span class="rounded-full bg-sky-500/15 px-2 py-0.5 text-[10px] font-semibold text-sky-300">Bắt đầu</span>
                        @elseif($plan->is_default)
                            <span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-300">Mặc định</span>
                        @endif
                    </div>
                    @if($plan->tagline)
                        <p class="mt-1 min-h-[2.25rem] text-xs text-cream-300">{{ $plan->tagline }}</p>
                    @endif
                    <p class="mt-3 font-display text-3xl font-semibold text-cream-50">{{ $plan->priceLabel() }}</p>
                    {{-- [Q3] Gói xưởng bán theo VỤ (3 tháng), không phải theo tháng: nhãn phải nói đúng. --}}
                    <p class="text-[11px] text-cream-300">
                        {{ $plan->isFree() ? 'không giới hạn thời gian' : ('mỗi '.$plan->unitLabel().($plan->isSeasonal() ? ' ('.$plan->unitMonths().' tháng)' : '')) }}
                    </p>

                    <dl class="mt-4 space-y-1.5 text-xs">
                        <div class="flex items-baseline justify-between gap-2">
                            <dt class="text-cream-300">Credit mỗi {{ $plan->unitLabel() }}</dt>
                            <dd class="font-semibold text-cream-50">{{ $vn((int) $plan->credits_per_month) }}</dd>
                        </div>
                        @if((int) $plan->bonus_credits > 0)
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="text-cream-300">Tặng lần đầu</dt>
                                <dd class="font-semibold text-emerald-300">+{{ $vn((int) $plan->bonus_credits) }}</dd>
                            </div>
                        @endif
                        <div class="flex items-baseline justify-between gap-2">
                            <dt class="text-cream-300">Ảnh tối đa</dt>
                            <dd class="font-semibold text-cream-50">{{ $plan->resolution_cap }}</dd>
                        </div>
                        {{-- [Q4] Số ghế: chủ doanh nghiệp cần biết gói cho bao nhiêu NGƯỜI dùng chung. --}}
                        <div class="flex items-baseline justify-between gap-2">
                            <dt class="text-cream-300">Số ghế</dt>
                            <dd class="font-semibold {{ $plan->hasTeamSeats() ? 'text-emerald-300' : 'text-cream-50' }}">{{ $plan->seatsLabel() }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-2">
                            <dt class="text-cream-300">Mỗi ảnh</dt>
                            <dd class="font-semibold text-cream-50">{{ $vn((int) $plan->image_credit_cost) }} credit</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-2">
                            <dt class="text-cream-300">Mỗi video</dt>
                            <dd class="font-semibold text-cream-50">{{ $vn((int) $plan->video_credit_cost) }} credit</dd>
                        </div>
                    </dl>

                    @if(! empty($plan->features))
                        <ul class="mt-4 flex-1 space-y-1.5 border-t border-ink-700 pt-3">
                            @foreach($plan->features as $feature)
                                <li class="flex items-start gap-2 text-[11px] text-cream-200">
                                    <svg class="mt-0.5 h-3 w-3 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20 6-9 11-5-5"/></svg>
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="flex-1"></div>
                    @endif

                    @if(! empty($badges))
                        <p class="mt-3 rounded-lg border border-brand-500/25 bg-brand-600/10 p-2 text-[10px] text-brand-100">
                            Hợp với: {{ implode(' · ', $badges) }}
                        </p>
                    @endif

                    <a href="{{ $ctaHref }}" class="btn-brand btn-sm mt-3 w-full justify-center">
                        {{ $plan->isFree() ? 'Bắt đầu miễn phí' : ($loggedIn ? 'Gửi yêu cầu nâng cấp' : 'Đăng ký rồi gửi yêu cầu') }}
                    </a>
                </article>
            @endforeach
        </div>

        <div class="mt-4 rounded-lg border border-ink-700 bg-ink-900/60 p-3 text-[11px] leading-relaxed text-cream-300">
            <p>
                <b class="text-cream-100">Thanh toán &amp; kích hoạt gói trả phí — 3 bước, không cần thẻ:</b>
            </p>
            <ol class="mt-2 list-decimal space-y-1 pl-5">
                <li>Trong Studio, mở <b class="text-cream-100">«Gói &amp; credit» → Yêu cầu nâng cấp</b>: chọn gói, số tháng (1 · 3 · 6 · 12) và cách thanh toán.</li>
                <li>Bạn nhận ngay một <b class="text-cream-100">MÃ YÊU CẦU</b> (dạng <span class="font-mono">UP-2609-0001</span>) — dùng mã này làm nội dung chuyển khoản.</li>
                <li>FabrikAI xác nhận tiền rồi <b class="text-cream-100">kích hoạt gói</b> cho tài khoản của bạn (trong giờ làm việc{{ $payment['support']['hours'] ? ' · '.$payment['support']['hours'] : '' }}).</li>
            </ol>
            @if (! empty($payment['bank']['account']))
                <p class="mt-2 rounded bg-ink-800 px-2 py-1.5">
                    <b class="text-cream-100">Chuyển khoản:</b> {{ $payment['bank']['name'] }} · STK <b>{{ $payment['bank']['account'] }}</b>
                    @if (! empty($payment['bank']['holder'])) — {{ $payment['bank']['holder'] }} @endif
                    @if (! empty($payment['bank']['branch'])) ({{ $payment['bank']['branch'] }}) @endif
                </p>
            @endif
            @if (! empty($payment['support']['phone']) || ! empty($payment['support']['email']))
                <p class="mt-2">
                    <b class="text-cream-100">Cần hỗ trợ:</b>
                    @if (! empty($payment['support']['phone'])) {{ $payment['support']['phone'] }} @endif
                    @if (! empty($payment['support']['email'])) · {{ $payment['support']['email'] }} @endif
                    @if (! empty($payment['support']['zalo'])) · Zalo {{ $payment['support']['zalo'] }} @endif
                </p>
            @endif
            <p class="mt-2 text-cream-300/85">
                Cổng thanh toán trực tuyến (VNPay) {{ $payment['vnpay']['available'] ? 'đã hoạt động.' : 'chưa mở — bạn vẫn dùng phần miễn phí ngay hôm nay.' }}
            </p>
        </div>
    </section>

    {{-- ── So sánh ── --}}
    <section id="so-sanh" class="border-y border-ink-800 bg-ink-900/40 py-12">
        <div class="container-x">
            <h2 class="font-display text-2xl font-semibold text-cream-50">So sánh nhanh</h2>
            <div class="mt-5 overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-xs">
                    <thead>
                        <tr class="border-b border-ink-700 text-cream-300">
                            <th class="py-3 pr-4 font-semibold">Hạng mục</th>
                            @foreach($plans as $plan)
                                <th class="py-3 pr-4 font-semibold">{{ $plan->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="text-cream-200">
                        <tr class="border-b border-ink-800">
                            <td class="py-2.5 pr-4 text-cream-300">Giá mỗi kỳ thanh toán</td>
                            @foreach($plans as $plan)
                                <td class="py-2.5 pr-4 font-semibold text-cream-50">{{ $plan->priceLabel() }}</td>
                            @endforeach
                        </tr>
                        <tr class="border-b border-ink-800">
                            <td class="py-2.5 pr-4 text-cream-300">Credit mỗi kỳ <span class="text-cream-300/70">(tháng · vụ)</span></td>
                            @foreach($plans as $plan)
                                <td class="py-2.5 pr-4">{{ $vn((int) $plan->credits_per_month) }}<span class="text-cream-300/70">/{{ $plan->unitLabel() }}</span></td>
                            @endforeach
                        </tr>
                        <tr class="border-b border-ink-800">
                            <td class="py-2.5 pr-4 text-cream-300">Credit tặng lần đầu</td>
                            @foreach($plans as $plan)
                                <td class="py-2.5 pr-4">{{ (int) $plan->bonus_credits > 0 ? '+'.$vn((int) $plan->bonus_credits) : '—' }}</td>
                            @endforeach
                        </tr>
                        <tr class="border-b border-ink-800">
                            <td class="py-2.5 pr-4 text-cream-300">Số ghế (người dùng chung một gói)</td>
                            @foreach($plans as $plan)
                                <td class="py-2.5 pr-4">{{ $plan->seatsLabel() }}</td>
                            @endforeach
                        </tr>
                        <tr class="border-b border-ink-800">
                            <td class="py-2.5 pr-4 text-cream-300">Độ phân giải ảnh tối đa</td>
                            @foreach($plans as $plan)
                                <td class="py-2.5 pr-4">{{ $plan->resolution_cap }}</td>
                            @endforeach
                        </tr>
                        <tr class="border-b border-ink-800">
                            <td class="py-2.5 pr-4 text-cream-300">Credit mỗi ảnh / mỗi video</td>
                            @foreach($plans as $plan)
                                <td class="py-2.5 pr-4">{{ $vn((int) $plan->image_credit_cost) }} / {{ $vn((int) $plan->video_credit_cost) }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <td class="py-2.5 pr-4 text-cream-300">Đặc quyền kèm theo</td>
                            @foreach($plans as $plan)
                                <td class="py-2.5 pr-4">{{ count($plan->features ?? []) }} mục</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-[11px] text-cream-300">
                Ví dụ dễ hình dung: gói {{ $cheapestPaid ? $cheapestPaid->name : 'trả phí' }} với
                {{ $cheapestPaid ? $vn((int) $cheapestPaid->credits_per_month + (int) $cheapestPaid->bonus_credits) : '' }} credit
                ≈ {{ $cheapestPaid && (int) $cheapestPaid->image_credit_cost > 0 ? $vn((int) floor(((int) $cheapestPaid->credits_per_month + (int) $cheapestPaid->bonus_credits) / (int) $cheapestPaid->image_credit_cost)) : '' }} ảnh
                trong tháng đầu.
            </p>
        </div>
    </section>

    {{-- ── Cách hoạt động ── --}}
    <section class="container-x py-12">
        <h2 class="font-display text-2xl font-semibold text-cream-50">Từ ý tưởng tới ảnh bán được: 4 bước</h2>
        <ol class="mt-6 grid gap-4 md:grid-cols-4">
            @foreach([
                ['Tạo tài khoản', 'Khoảng 30 giây. Nhận credit dùng thử, không cần thẻ.'],
                ['Đưa nguyên liệu', 'Dán ý tưởng bằng tiếng Việt, hoặc tải ảnh sản phẩm / ảnh mẫu của bạn.'],
                ['Chọn bố cục', 'Tỉ lệ khung, độ phân giải, dáng người mẫu, bối cảnh — chọn sẵn, không phải viết prompt phức tạp.'],
                ['Nhận ảnh & dùng ngay', 'Ảnh về trong thư viện riêng: tải về, tạo biến thể, gửi khách duyệt.'],
            ] as $i => $step)
                <li class="card p-5">
                    <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-600/20 text-xs font-bold text-brand-200">{{ $i + 1 }}</span>
                    <h3 class="mt-3 text-sm font-semibold text-cream-50">{{ $step[0] }}</h3>
                    <p class="mt-1 text-xs leading-relaxed text-cream-300">{{ $step[1] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- ── Hỏi đáp ── --}}
    <section id="faq" class="border-t border-ink-800 bg-ink-900/40 py-12">
        <div class="container-x">
            <h2 class="font-display text-2xl font-semibold text-cream-50">Câu hỏi thường gặp</h2>
            <div class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach([
                    ['Credit là gì?', 'Credit là đơn vị tính công việc. Mỗi ảnh tốn số credit ghi trên gói của bạn (thường 1 credit), mỗi video tốn nhiều hơn. Số credit còn lại luôn hiện ở góc phải Studio.'],
                    ['Tôi không biết gì về AI, có dùng được không?', 'Được. Bạn mô tả bằng tiếng Việt như đang trao đổi với thợ may hoặc nhiếp ảnh gia; hệ thống tự dịch thành câu lệnh cho model và gợi ý sẵn bố cục, dáng, bối cảnh.'],
                    ['Nhiều người trong công ty dùng chung một gói được không?', 'Được — gói có SỐ GHẾ: Chuyên nghiệp 3 người, Studio 10 người, Xưởng theo vụ 5 người. Chủ gói mời nhân viên bằng email trong Studio (mục «Gói & credit» → Nhóm làm việc); cả nhóm dùng chung credit và chung bộ sưu tập, ảnh vẫn ghi rõ ai tạo.'],
        ['Thanh toán thế nào?', 'Chưa có cổng thanh toán trực tuyến: bạn gửi «Yêu cầu nâng cấp» trong Studio, nhận mã yêu cầu, chuyển khoản theo mã đó rồi FabrikAI kích hoạt gói. VNPay sẽ mở sau.'],
                    ['Hết credit giữa việc thì sao?', 'Hệ thống cảnh báo sớm khi credit sắp hết và cho bạn nạp/nâng gói ngay trong Studio. Bạn cũng xem được toàn bộ lịch sử trừ credit trong mục Sổ credit.'],
                    ['Ảnh dùng cho sàn thương mại điện tử và in ấn được không?', 'Được. Gói từ mức trả phí cho ảnh tới 2K; bạn chọn tỉ lệ khung (1:1, 4:5, 3:4, 16:9…) phù hợp từng kênh bán.'],
                    ['Dữ liệu và ảnh của tôi có riêng tư không?', 'Mỗi tài khoản có thư viện, dự án, khuôn mặt và dáng người mẫu riêng; người khác không thấy. Khoá API của hệ thống được mã hoá và không bao giờ hiển thị lại.'],
                ] as $faq)
                    <details class="card p-4">
                        <summary class="cursor-pointer text-sm font-semibold text-cream-100">{{ $faq[0] }}</summary>
                        <p class="mt-2 text-xs leading-relaxed text-cream-300">{{ $faq[1] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Kêu gọi cuối ── --}}
    <section class="container-x py-14">
        <div class="card flex flex-wrap items-center justify-between gap-4 p-6">
            <div class="min-w-0">
                <h2 class="font-display text-xl font-semibold text-cream-50">Bắt đầu bằng phần miễn phí, nâng gói khi công việc nhiều lên</h2>
                <p class="mt-1 text-xs text-cream-300">Không cần thẻ thanh toán. Không cần cài đặt gì. Ảnh đầu tiên có thể có trong vài phút.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $ctaHref }}" class="btn-brand">{{ $ctaLabel }}</a>
                @if(! $loggedIn)
                    <a href="{{ route('login') }}" class="btn-outline">Đã có tài khoản</a>
                @endif
            </div>
        </div>
    </section>

    <footer class="border-t border-ink-800 py-8">
        <div class="container-x flex flex-wrap items-center gap-3 text-[11px] text-cream-300">
            <span>© {{ date('Y') }} FabrikAI — studio thiết kế thời trang AI.</span>
            <a href="{{ url('/') }}" class="ml-auto hover:text-cream-100">Studio</a>
            <a href="{{ route('login') }}" class="hover:text-cream-100">Đăng nhập</a>
            <a href="{{ route('register') }}" class="hover:text-cream-100">Đăng ký</a>
        </div>
    </footer>
</div>
@endsection
