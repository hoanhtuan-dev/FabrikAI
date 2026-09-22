<!DOCTYPE html>
<html lang="vi" class="h-full" data-theme="{{ theme_resolved() }}" style="--font-scale: {{ font_scale_ratio() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ theme_color() }}">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    <title>FabrikAI · {{ \App\Support\SettingsAreas::find($area)['label'] ?? 'Cài đặt' }}</title>
    @vite(['resources/css/app.css', 'resources/js/studio/hub.js'])
</head>
<body class="min-h-screen bg-ink-900 text-cream-100 antialiased">
    {{--
      MỘT TRANG cho CẢ BA KHU (Cài đặt của tôi · Cài đặt hệ thống · Quản trị).

      [Vấn đề gốc 2026-09-26] Trước đây là ba trang SPA riêng (/cai-dat, /settings, /admin) cộng thêm
      các trang rời (/presets, /stylist-data, /model-settings, /he-thong-thiet-ke, /bao-cao-nhom).
      Mỗi trang tự dựng thanh tiêu đề, sidebar và nút "về Studio" — muốn sang khu khác phải quay về
      Studio rồi mở lại menu. Người dùng không có một chỗ nào để "quản lý tài khoản và hệ thống".

      Nay: MỘT entry (hub.js -> SettingsHubApp.vue), MỘT thanh tiêu đề, MỘT bộ chuyển khu. Ba app cũ
      được nhúng vào (prop "embedded") nên chúng ẩn thanh tiêu đề riêng, chỉ còn nội dung + sidebar
      mục bên trong.

      Vì sao hub vẫn là ba app chứ không viết lại một app khổng lồ: mỗi khu có nghiệp vụ riêng
      (SettingsApp ~1.9k dòng, AdminApp ~2.1k dòng) và đã có test khoá hành vi. Nhúng lại giữ nguyên
      nghiệp vụ, chỉ hợp nhất phần khung nhìn.

      data-section   : khu "Cài đặt của tôi" đọc để mở đúng mục (MySettingsApp.onMounted).
      data-user-id   : useLocalCatalog + MySettingsApp đọc — tests/Feature/UserCatalogTest.php khoá
                       bất biến "mỗi trang phải nhúng data-user-id".
      data-user-admin: 1 khi là owner — hub dùng để ẩn/hiện hai khu chỉ owner mới vào được (máy chủ
                       VẪN là nơi chặn thật: middleware auth+admin trên /settings và /admin).
    --}}
    <div id="hub-root"
         class="w-full"
         data-area="{{ $area }}"
         {{-- Danh sách khu do MÁY CHỦ lọc theo quyền rồi truyền xuống: App\Support\SettingsAreas
              là nguồn duy nhất — thanh trong Vue và thanh ở partials/hub-bar.blade.php cùng đọc nó. --}}
         data-areas='@json(\App\Support\SettingsAreas::visibleFor(auth()->user()))'
         data-section="{{ $section ?? 'presets' }}"
         data-user-id="{{ auth()->id() }}"
         data-user-admin="{{ auth()->user()?->isAdmin() ? '1' : '0' }}"></div>
</body>
</html>
