<!DOCTYPE html>
<html lang="vi" class="h-full" data-theme="{{ theme_resolved() }}" style="--font-scale: {{ font_scale_ratio() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ theme_color() }}">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    <link rel="apple-touch-icon" href="/icons/studio-apple-touch-icon.png">
    <link rel="icon" type="image/png" href="/icons/studio-favicon-32.png">
    <title>FabrikAI — Hôm nay bạn muốn tạo gì?</title>
    @vite(['resources/css/app.css', 'resources/js/studio/home.js'])
    @php
        // Giữ đúng cấu trúc boot của studio/index.blade.php — StudioXssSinksTest canh việc
        // escape @json của payload này; HomeApp chỉ đọc "user".
        $u = auth()->user();
        $boot = [
            'user' => $u ? [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_label' => $u->roleLabel(),
                'avatar' => $u->avatar,
                'credits_balance' => $u->credits_balance,
                'is_admin' => $u->isAdmin(),
                'is_super_admin' => $u->isSuperAdmin(),
            ] : null,
        ];
    @endphp
    <script>window.__STUDIO_BOOT__ = @json($boot);</script>
</head>
<body class="min-h-dvh bg-ink-900 text-cream-100 antialiased">
    <div id="home-root" class="min-h-dvh"></div>
</body>
</html>
