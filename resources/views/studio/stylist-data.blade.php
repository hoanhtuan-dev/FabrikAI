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
    <div id="stylist-data-root" class="w-full"></div>
</body>
</html>
