<!DOCTYPE html>
<html lang="vi" class="h-full" data-theme="{{ theme_resolved() }}" style="--font-scale: {{ font_scale_ratio() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ theme_color() }}">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    <title>FabrikAI · Cài đặt</title>
    @vite(['resources/css/app.css', 'resources/js/studio/settings.js'])
</head>
<body class="min-h-screen bg-ink-900 text-cream-100 antialiased">
    <div id="settings-root" class="w-full"></div>
</body>
</html>
