<!DOCTYPE html>
<html lang="vi" data-theme="{{ theme_resolved() }}" style="--font-scale: {{ font_scale_ratio() }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover: bắt buộc để thanh dưới (dock) và vùng chạm chạm tới được sát mép trên iPhone
         có tai thỏ — thiếu nó thì mọi thứ bị đẩy vào trong và chừa một dải trắng ở đáy màn hình. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ theme_color() }}">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    <title>@yield('title', 'FabrikAI') — FabrikAI</title>
    @yield('meta')
    @vite(['resources/css/app.css'])
</head>
{{-- [2026-09-26 · thiết kế lại] Nền trang dùng token bề mặt CHÌM NHẤT (base-300 = ink-950) và chừa
     vùng an toàn ở đáy cho thanh điều hướng dạng dock trên điện thoại. --}}
<body class="min-h-screen bg-ink-950 text-cream-100 antialiased">
    @yield('content')
</body>
</html>
