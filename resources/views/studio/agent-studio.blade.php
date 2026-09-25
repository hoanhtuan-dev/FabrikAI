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
    <title>FabrikAI — Agent Studio</title>
    {{--
      TRANG RIÊNG của Agent Studio (2026-09-25).

      Vì sao tách khỏi /studio: đây là luồng 4 bước cần CHIỀU CAO, mà trong /studio nó phải sống
      trong một modal che mất phần còn lại của màn hình. Xem AgentStudioApp.vue để biết chi tiết.

      Vì sao KHÔNG gộp vào /bo-suu-tap hay /cai-dat: hai trang đó là "xem & quản lý dữ liệu đã có",
      còn đây là "làm ra dữ liệu mới" — khác việc, khác nhịp, khác cả vòng đời (xem §11–§12).
    --}}
    @vite(['resources/css/app.css', 'resources/js/studio/agent-studio.js'])
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
<body class="h-screen overflow-hidden bg-ink-950 text-cream-100 antialiased">
    <div class="absolute inset-0 flex items-center justify-center text-sm text-cream-400" aria-hidden="true">Đang tải Agent Studio…</div>
    <div id="agent-studio-root" class="absolute inset-0"></div>
</body>
</html>
