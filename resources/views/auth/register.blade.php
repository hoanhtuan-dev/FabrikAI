@extends('layouts.app')
@section('title', 'Đăng ký')
@section('content')
<div class="container-x flex min-h-[85vh] items-center justify-center py-16">
    <div class="w-full max-w-md rounded-2xl border border-ink-700 bg-ink-900 p-8 shadow-2xl">
        <h1 class="font-display text-2xl font-semibold text-cream-50">Đăng ký FabrikAI</h1>
        <p class="mt-1 text-sm text-cream-400">Tạo tài khoản để dùng studio.</p>

        @if($errors->any())
            <div class="mt-4 rounded-xl bg-red-500/10 p-3 text-sm text-danger">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="label">Tên</label>
                <input type="text" name="name" value="{{ old('name') }}" class="input" required autofocus>
            </div>
            <div>
                <label class="label">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="input" required>
            </div>
            <div>
                <label class="label">Mật khẩu</label>
                <input type="password" name="password" class="input" required>
            </div>
            <div>
                <label class="label">Nhập lại mật khẩu</label>
                <input type="password" name="password_confirmation" class="input" required>
            </div>
            <button type="submit" class="btn-brand w-full">Đăng ký</button>
        </form>

        <p class="mt-4 text-sm text-cream-400">Đã có tài khoản? <a href="{{ route('login') }}" class="link">Đăng nhập</a></p>
        <p class="mt-2 text-xs text-cream-400">Xem trước <a href="{{ route('pricing.page') }}" class="link">các gói và giá</a> — không cần đăng ký.</p>
    </div>
</div>
@endsection
