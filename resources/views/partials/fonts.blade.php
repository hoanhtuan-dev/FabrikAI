{{--
  FONT CỦA BUILD — nạp CSS @font-face do `laravel-vite-plugin/fonts` phát ra (đợt 62 · 2026-09-26).

  VÌ SAO CẦN MỘT PARTIAL RIÊNG: plugin tự-host font (tải woff2 về `public/build/assets/`) và phát ra
  `fonts-<hash>.css` + `fonts-manifest.json`, nhưng KHÔNG tự chèn <link> vào HTML — việc đó là của app.
  Trước đợt này app chưa làm, nên ba họ chữ (Inter · Fraunces · Space Grotesk) chỉ tồn tại trên giấy:
  mọi máy rơi về `system-ui` (đo trên Chrome: `document.fonts` rỗng, HTML không có <link> font nào).

  VÌ SAO PRELOAD: font là tài nguyên chặn hiển thị chữ — preload đúng MỘT weight mỗi họ (woff2) làm chữ
  hiện đúng ngay khung hình đầu; preload cả 4 weight là bắt máy tải thừa. Xem App\Support\BuildFonts.
--}}
@php($__fonts = \App\Support\BuildFonts::styleUrl())
@if($__fonts)
    @foreach(\App\Support\BuildFonts::preloads() as $__f)
        <link rel="preload" as="font" type="font/woff2" href="{{ $__f['url'] }}" crossorigin="anonymous">
    @endforeach
    <link rel="stylesheet" href="{{ $__fonts }}">
@endif
