<!DOCTYPE html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#193d2b">
    <meta name="color-scheme" content="dark light">
    <title>FabrikAI · Quản lý data Trợ lý thiết kế</title>
    @vite(['resources/css/app.css', 'resources/js/studio/stylist-data.js'])
</head>
<body class="min-h-screen bg-ink-900 text-cream-100 antialiased">
    {{-- data-user-id: khoá localStorage cho bản tùy chỉnh RIÊNG của từng user (xem useLocalCatalog.js). --}}
    <div id="stylist-data-root" class="w-full" data-user-id="{{ auth()->id() }}" data-user-admin="{{ auth()->user()?->isAdmin() ? '1' : '0' }}"></div>
</body>
</html>
