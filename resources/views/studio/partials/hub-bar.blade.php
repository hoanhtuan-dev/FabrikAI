{{--
  THANH CHUNG CỦA TRANG HỢP NHẤT "Cài đặt & Quản trị" — bản MÁY CHỦ RENDER.

  Dùng cho hai khu KHÔNG phải SPA: /he-thong-thiet-ke (Hệ thống thiết kế) và /bao-cao-nhom (Chi phí
  theo nhóm). Lý do hai trang đó vẫn render ở máy chủ (đã ghi trong chính chúng): trang xem token đọc
  App\Support\ThemePalette — CÙNG lớp mà ThemeSystemTest dùng, và form import theme là form POST thật
  nên vẫn chạy được kể cả khi bundle JS hỏng. Vì vậy thanh của chúng cũng phải là HTML thật, không
  phụ thuộc JS.

  Danh sách khu KHÔNG khai lại ở đây: đọc từ App\Support\SettingsAreas (một nguồn chân lý) — cùng
  danh sách mà SettingsHubApp.vue nhận qua data-areas. Biểu tượng cũng vậy: IconRegistry đọc
  resources/js/studio/icons.json, đúng file mà <StudioIcon> dùng, nên hai thanh không thể lệch hình.

  Truyền vào: $area = id khu đang mở (xem App\Support\SettingsAreas::ids()).
--}}
@php
    $hubAreas = \App\Support\SettingsAreas::visibleFor(auth()->user());
    $hubCurrent = \App\Support\SettingsAreas::normalize($area ?? 'mine');
    $hubActive = \App\Support\SettingsAreas::find($hubCurrent) ?? $hubAreas[0];
    $hubIcon = fn (string $name, string $size): string => '<svg class="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.\App\Support\IconRegistry::svg($name).'</svg>';
@endphp
<header data-area="{{ $hubCurrent }}" class="sticky top-0 z-40 border-b border-ink-700 bg-ink-900/95 backdrop-blur">
    <div class="mx-auto flex w-full max-w-[1400px] flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-5 lg:px-6">
        <a href="/" class="tool-btn shrink-0" title="Về xưởng thiết kế">
            {!! $hubIcon('arrowLeft', 'h-3.5 w-3.5') !!}
            <span class="hidden sm:inline">Studio</span>
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="flex items-center gap-2 font-display text-lg font-semibold text-cream-50">
                <span class="text-brand-300">{!! $hubIcon($hubActive['icon'], 'h-4 w-4') !!}</span>
                {{ $hubActive['label'] }}
            </h1>
            <p class="mt-0.5 hidden truncate text-body text-cream-300 sm:block">{{ $hubActive['desc'] }}</p>
        </div>

        @if (count($hubAreas) > 1)
            {{-- Bộ chuyển khu (màn hình rộng) --}}
            <nav class="hidden items-center gap-1 rounded-xl border border-ink-700 bg-ink-800/70 p-1 lg:flex" aria-label="Khu vực cài đặt">
                @foreach ($hubAreas as $a)
                    <a href="{{ $a['href'] }}" @if ($a['id'] === $hubCurrent) aria-current="page" @endif
                       class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors {{ $a['id'] === $hubCurrent ? 'bg-brand-600 text-primary-content shadow-sm' : 'text-cream-300 hover:bg-ink-700 hover:text-cream-100' }}">
                        {!! $hubIcon($a['icon'], 'h-3.5 w-3.5') !!} {{ $a['label'] }}
                    </a>
                @endforeach
            </nav>

            {{-- Bộ chuyển khu (màn hình hẹp): hàng cuộn ngang, mỗi mục cao >= 40px --}}
            <div class="-mx-1 flex w-full items-center gap-1 overflow-x-auto pb-0.5 lg:hidden" role="tablist" aria-label="Khu vực cài đặt">
                @foreach ($hubAreas as $a)
                    <a href="{{ $a['href'] }}" role="tab" aria-selected="{{ $a['id'] === $hubCurrent ? 'true' : 'false' }}"
                       class="flex min-h-10 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold {{ $a['id'] === $hubCurrent ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-300' }}">
                        {!! $hubIcon($a['icon'], 'h-3.5 w-3.5') !!} {{ $a['short'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</header>
