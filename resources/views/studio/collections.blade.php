<!DOCTYPE html>
<html lang="vi" class="h-full" data-theme="{{ theme_resolved() }}" style="--font-scale: {{ font_scale_ratio() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#193d2b">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    <title>FabrikAI — Bộ sưu tập</title>
    @vite(['resources/css/app.css', 'resources/js/studio/collections.js'])
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
            'project_statuses' => \App\Services\ProjectWorkflowService::STATES,
        ];
    @endphp
    <script>window.__STUDIO_BOOT__ = @json($boot);</script>
</head>
<body class="h-screen overflow-hidden bg-ink-950 text-cream-100 antialiased">
    <div id="collections-root" class="h-full w-full"></div>
</body>
</html>
