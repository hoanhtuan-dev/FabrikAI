@extends('layouts.app')
@section('title', 'Đăng ký')

{{-- [2026-09-26 · thiết kế lại] Cùng khuôn với trang đăng nhập: hai cột trên máy tính (giới thiệu + biểu mẫu),
     một cột trên điện thoại. Biểu mẫu đăng ký có BỐN ô nên trên điện thoại bàn phím sẽ che phần dưới —
     vì vậy nút nằm ngay sau ô cuối, không có khối mô tả nào chen giữa. --}}
@section('content')
<div class="hero min-h-screen bg-ink-950 px-4 py-8 sm:py-12">
    <div class="hero-content w-full max-w-5xl flex-col gap-8 lg:flex-row lg:items-stretch lg:gap-12">

        <section class="hidden w-full max-w-md flex-col justify-center lg:flex" aria-label="Giới thiệu">
            <p class="kicker">FabrikAI</p>
            <h1 class="mt-3 font-display text-4xl font-semibold leading-tight text-cream-50">
                Bắt đầu trong 2 phút
            </h1>
            <p class="mt-4 text-body-lg leading-6 text-cream-300">
                Tạo tài khoản để khai DNA shop, dựng brief bộ sưu tập đầu tiên và tính kế hoạch sản xuất
                cùng giá vốn — tất cả trong một buổi làm việc.
            </p>
            <ul class="mt-6 space-y-3 text-body text-cream-200">
                <li class="flex items-start gap-2.5">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ok/15 text-ok" aria-hidden="true">✓</span>
                    <span>Brief, mood board, cơ cấu SKU và bảng size cho cả bộ sưu tập.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ok/15 text-ok" aria-hidden="true">✓</span>
                    <span>Kế hoạch cắt, định mức vải và ba mức giá bán — số do máy tính, không do AI đoán.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ok/15 text-ok" aria-hidden="true">✓</span>
                    <span>Phiếu kỹ thuật, mẫu vật lý, tiến độ sản xuất và nghiệm thu chất lượng.</span>
                </li>
            </ul>
        </section>

        <section class="w-full max-w-md" aria-label="Đăng ký">
            <div class="card p-5 sm:p-7">
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-2xl bg-brand-600 font-display text-lg font-semibold text-primary-content" aria-hidden="true">F</span>
                    <div>
                        <p class="font-display text-title font-semibold text-cream-50">Tạo tài khoản</p>
                        <p class="text-body text-cream-400">Miễn phí bắt đầu, không cần thẻ</p>
                    </div>
                </div>

                @if($errors->any())
                    <div role="alert" class="alert alert-error mt-5 text-body">
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label class="label" for="reg-name">Tên</label>
                        <input id="reg-name" type="text" name="name" value="{{ old('name') }}" class="input" autocomplete="name" required autofocus>
                    </div>
                    <div>
                        <label class="label" for="reg-email">Email</label>
                        <input id="reg-email" type="email" name="email" value="{{ old('email') }}" class="input" inputmode="email" autocomplete="email" required>
                    </div>
                    <div>
                        <label class="label" for="reg-password">Mật khẩu</label>
                        <input id="reg-password" type="password" name="password" class="input" autocomplete="new-password" required>
                    </div>
                    <div>
                        <label class="label" for="reg-password2">Nhập lại mật khẩu</label>
                        <input id="reg-password2" type="password" name="password_confirmation" class="input" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Đăng ký</button>
                </form>

                <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-700 pt-4 text-body text-cream-400">
                    <span>Đã có tài khoản? <a href="{{ route('login') }}" class="link py-2 text-brand-300">Đăng nhập</a></span>
                    <span>Xem trước <a href="{{ route('pricing.page') }}" class="link py-2 text-brand-300">các gói và giá</a></span>
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
