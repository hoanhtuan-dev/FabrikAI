<!DOCTYPE html>
<html lang="vi" class="h-full" data-theme="{{ theme_resolved() }}" style="--font-scale: {{ font_scale_ratio() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ theme_color() }}">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    <title>FabrikAI · {{ $sectionTitle ?? "Cài đặt của tôi" }}</title>
    @vite(['resources/css/app.css', 'resources/js/studio/my-settings.js'])
</head>
<body class="min-h-screen bg-ink-900 text-cream-100 antialiased">
    {{--
      MỘT blade cho CẢ BỐN lối vào (/cai-dat, /presets, /stylist-data, /model-settings).
      Server truyền $section để app mở đúng mục ngay từ đầu, không phải đoán từ URL.

      data-user-id / data-user-admin: giữ nguyên như 3 blade cũ — useLocalCatalog đọc hai thuộc tính này,
      và tests/Feature/UserCatalogTest.php khoá bất biến "mỗi trang phải nhúng data-user-id".
    --}}
    <div id="my-settings-root"
         class="w-full"
         data-section="{{ $section ?? 'presets' }}"
         data-user-id="{{ auth()->id() }}"
         data-user-admin="{{ auth()->user()?->isAdmin() ? '1' : '0' }}"></div>
</body>
</html>
