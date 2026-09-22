@extends('layouts.app')
@section('title', 'Đăng nhập')

{{-- [2026-09-26 · thiết kế lại] Trang đăng nhập nay là HAI CỘT trên máy tính và MỘT CỘT trên điện thoại:
     cột trái nói ứng dụng này là gì (người mới cần biết trước khi gõ mật khẩu), cột phải là biểu mẫu.
     Trước đây chỉ có một thẻ giữa màn hình — đúng nhưng không nói được gì, và trên điện thoại thẻ đó
     chiếm gần hết chiều cao mà vẫn trống.

     Nút dùng lớp THẬT của daisyUI (btn · btn-primary · btn-block) thay vì tự vẽ: nhờ vậy nút đăng nhập
     có đúng chiều cao, bóng, nhịp chuyển động và trạng thái disabled của bộ component đang dùng ở phần
     còn lại của giao diện. --}}
@section('content')
<div class="hero min-h-screen bg-ink-950 px-4 py-8 sm:py-12">
    <div class="hero-content w-full max-w-5xl flex-col gap-8 lg:flex-row lg:items-stretch lg:gap-12">

        {{-- CỘT TRÁI — giới thiệu. Ẩn phần mô tả dài trên điện thoại để biểu mẫu lên gần đỉnh màn hình. --}}
        <section class="hidden w-full max-w-md flex-col justify-center lg:flex" aria-label="Giới thiệu">
            <p class="kicker">FabrikAI</p>
            <h1 class="mt-3 font-display text-4xl font-semibold leading-tight text-cream-50">
                Studio thiết kế thời trang AI
            </h1>
            <p class="mt-4 text-body-lg leading-6 text-cream-300">
                Từ ý tưởng tới lô hàng: radar xu hướng, brief bộ sưu tập, kế hoạch sản xuất và giá vốn,
                ảnh mẫu để đăng bán — cùng một chỗ.
            </p>
            <ul class="mt-6 space-y-3 text-body text-cream-200">
                <li class="flex items-start gap-2.5">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-600/20 text-brand-200" aria-hidden="true">1</span>
                    <span><b class="text-cream-100">Khai DNA shop một lần</b> — agent viết brief đúng chất shop bạn, không phải brief chung chung.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-600/20 text-brand-200" aria-hidden="true">2</span>
                    <span><b class="text-cream-100">Số do máy tính</b> — định mức, giá vốn, bảng size và kế hoạch cắt; AI chỉ viết chữ.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-600/20 text-brand-200" aria-hidden="true">3</span>
                    <span><b class="text-cream-100">Đi hết một vòng</b> — phiếu kỹ thuật, mẫu vật lý, tiến độ sản xuất và nghiệm thu chất lượng.</span>
                </li>
            </ul>
        </section>

        {{-- CỘT PHẢI — biểu mẫu. --}}
        <section class="w-full max-w-md" aria-label="Đăng nhập">
            <div class="card p-5 sm:p-7">
                <div class="flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center rounded-2xl bg-brand-600 font-display text-lg font-semibold text-primary-content" aria-hidden="true">F</span>
                    <div>
                        <p class="font-display text-title font-semibold text-cream-50">Đăng nhập</p>
                        <p class="text-body text-cream-400">Studio thiết kế thời trang AI</p>
                    </div>
                </div>

                @if($errors->any())
                    {{-- alert của daisyUI: có icon, viền và màu theo vai "error" của theme. --}}
                    <div role="alert" class="alert alert-error mt-5 text-body">
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
                    @csrf
                    <input type="hidden" name="redirect" value="{{ request('redirect', '/') }}">

                    <div>
                        <label class="label" for="login-email">Email</label>
                        <input id="login-email" type="email" name="email" value="{{ old('email') }}" class="input"
                               inputmode="email" autocomplete="email" required autofocus>
                    </div>

                    <div>
                        <label class="label" for="login-password">Mật khẩu</label>
                        <input id="login-password" type="password" name="password" class="input" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Đăng nhập</button>
                </form>

                <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-700 pt-4 text-body text-cream-400">
                    <span>Chưa có tài khoản? <a href="{{ route('register') }}" class="link py-2 text-brand-300">Đăng ký</a></span>
                    <span>Xem trước? <a href="{{ route('pricing.page') }}" class="link py-2 text-brand-300">Bảng giá</a></span>
                </div>
            </div>

            <p class="mt-4 px-1 text-tiny leading-5 text-cream-400 lg:hidden">
                Studio thiết kế thời trang AI: radar xu hướng · brief bộ sưu tập · kế hoạch sản xuất &amp; giá vốn · ảnh mẫu để đăng bán.
            </p>
        </section>
    </div>
</div>
@endsection
