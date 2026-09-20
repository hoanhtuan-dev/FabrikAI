<!DOCTYPE html>
<html lang="vi" data-theme="{{ theme_resolved() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#193d2b">
    <meta name="color-scheme" content="dark light">
    @include('partials.theme')
    <title>@yield('title', 'FabrikAI') — FabrikAI</title>
    @yield('meta')
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-ink-950 text-cream-100 antialiased">
    @yield('content')
</body>
</html>
