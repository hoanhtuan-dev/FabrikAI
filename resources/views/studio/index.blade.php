<!DOCTYPE html>
<html lang="vi" class="h-full" data-theme="{{ theme_resolved() }}" style="--font-scale: {{ font_scale_ratio() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ theme_color() }}">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    {{-- Font tự-host của build (Inter · Fraunces · Space Grotesk) — xem App\Support\BuildFonts. --}}
    @include('partials.fonts')
    {{-- T5: PWA đã GỠ HẲN (2026-09-17) — đã xoá `/sw.js` + `/manifest.json` + các icon chỉ phục vụ
         manifest. Trước đó service worker không bao giờ được đăng ký lại, nên PWA vốn là code chết.
         Việc gỡ SW cũ trong trình duyệt người dùng vẫn chạy ở `pageBoot.js` (cần thiết: xoá file trên
         máy chủ KHÔNG tự gỡ SW đã cài sẵn ở phía client). --}}
    <link rel="apple-touch-icon" href="/icons/studio-apple-touch-icon.png">
    <link rel="icon" type="image/png" href="/icons/studio-favicon-32.png">
    <title>FabrikAI — AI Fashion Design Studio</title>
    @vite(['resources/css/app.css', 'resources/js/studio/main.js'])
    @php
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
            'project_statuses' => app(\App\Services\ProjectWorkflowService::class)->states(),
        ];
    @endphp
    <script>window.__STUDIO_BOOT__ = @json($boot);</script>
</head>
<body class="h-screen overflow-hidden bg-ink-900 text-cream-100 antialiased">
    <div class="absolute inset-0 flex items-center justify-center text-sm text-cream-400" aria-hidden="true">Đang tải FabrikAI…</div>
    <div id="studio-root" class="absolute inset-0"></div>
</body>
</html>
