@extends('layouts.app')
@section('title', 'Đăng nhập')
@section('content')
<div class="container-x flex min-h-[85vh] items-center justify-center py-16">
    <div class="w-full max-w-md rounded-2xl border border-ink-700 bg-ink-900 p-8 shadow-2xl">
        <h1 class="font-display text-2xl font-semibold text-cream-50">Đăng nhập FabrikAI</h1>
        <p class="mt-1 text-sm text-cream-400">Studio thiết kế thời trang AI.</p>

        @if($errors->any())
            <div class="mt-4 rounded-xl bg-danger/10 p-3 text-sm text-danger">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="redirect" value="{{ request('redirect', '/') }}">
            <div>
                <label class="label">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="input" required autofocus autocomplete="email">
            </div>
            <div>
                <label class="label">Mật khẩu</label>
                <input type="password" name="password" class="input" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-brand w-full">Đăng nhập</button>
        </form>

        <p class="mt-4 text-sm text-cream-400">Chưa có tài khoản? <a href="{{ route('register') }}" class="link">Đăng ký</a></p>
        <p class="mt-2 text-xs text-cream-400">Muốn xem gói và giá trước? <a href="{{ route('pricing.page') }}" class="link">Bảng giá</a></p>
    </div>
</div>
@endsection
