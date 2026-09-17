@extends('layouts.app')
@section('title', 'Duyệt bộ sưu tập · '.$project->name)
@section('meta')
    {{-- Link chia sẻ KHÔNG được vào Google: người nhận là khách/nhân viên duyệt nội bộ. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Xem và duyệt bộ sưu tập {{ $project->name }} — FabrikAI.">
@endsection
@section('content')
<div class="studio-dark min-h-screen">
    <header class="border-b border-ink-700 bg-ink-900/95">
        <div class="container-x flex flex-wrap items-center gap-3 py-3">
            <span class="flex items-center gap-2">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">F</span>
                <span class="font-display text-base font-semibold text-cream-50">FabrikAI</span>
            </span>
            <span class="rounded-full border border-ink-700 bg-ink-800 px-3 py-1 text-[11px] font-semibold text-cream-200">Trang duyệt bộ sưu tập</span>
            @if($share->expires_at)
                <span class="ml-auto text-[11px] text-cream-300">Link có hiệu lực đến {{ $share->expires_at->format('d/m/Y') }}</span>
            @endif
        </div>
    </header>

    <main class="container-x py-8">
        {{-- ── Thông tin bộ sưu tập ── --}}
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-300">Bộ sưu tập</p>
        <h1 class="mt-1 font-display text-3xl font-semibold text-cream-50">{{ $project->name }}</h1>
        <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px]">
            <span class="rounded-full px-2.5 py-1 font-semibold" style="background: {{ ($project->status_color ?? '#559b78') }}26; color: {{ $project->status_color ?? '#85bd9f' }}">
                {{ $project->status_label ?? $project->status }}
            </span>
            <span class="rounded-full bg-ink-700 px-2.5 py-1 text-cream-200">{{ $images->count() }} ảnh</span>
            @if($project->deadline)
                <span class="rounded-full bg-ink-700 px-2.5 py-1 text-cream-200">Hạn: {{ $project->deadline->format('d/m/Y') }}</span>
            @endif
            @foreach($project->tags ?? [] as $tag)
                <span class="rounded-full border border-ink-700 px-2.5 py-1 text-cream-300">{{ $tag }}</span>
            @endforeach
        </div>

        @if(trim((string) $project->brief) !== '')
            <div class="card mt-5 p-4">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-cream-300">Yêu cầu của khách</p>
                <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-cream-100">{{ $project->brief }}</p>
            </div>
        @endif

        {{-- ── Ảnh ── --}}
        <h2 class="mt-8 font-display text-xl font-semibold text-cream-50">Ảnh tham chiếu</h2>
        @if($images->isEmpty())
            <p class="card mt-3 p-6 text-center text-sm text-cream-300">Bộ sưu tập chưa có ảnh nào. Vui lòng liên hệ người gửi link.</p>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($images as $i => $g)
                    <figure class="card overflow-hidden">
                        <a href="{{ $g->media_url }}" target="_blank" rel="noopener" class="block bg-ink-900">
                            <img src="{{ $g->media_url }}" alt="Ảnh {{ $i + 1 }} của bộ sưu tập {{ $project->name }}" loading="lazy" class="h-64 w-full object-cover">
                        </a>
                        <figcaption class="p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-cream-300">Ảnh {{ sprintf('%02d', $i + 1) }}</p>
                            @if($g->prompt)
                                <p class="mt-1 line-clamp-3 text-[11px] leading-relaxed text-cream-200">{{ \Illuminate\Support\Str::limit($g->prompt, 220) }}</p>
                            @endif
                        </figcaption>
                    </figure>
                @endforeach
            </div>
            <p class="mt-2 text-[11px] text-cream-300">Bấm vào ảnh để xem kích thước đầy đủ.</p>
        @endif

        {{-- ── Phản hồi ── --}}
        <section id="phan-hoi" class="mt-10">
            <h2 class="font-display text-xl font-semibold text-cream-50">Phản hồi của bạn</h2>

            @if($sent)
                <div class="mt-3 rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-200">
                    Đã gửi phản hồi — cảm ơn bạn. Người phụ trách bộ sưu tập sẽ nhận được ngay trong FabrikAI.
                </div>
            @endif

            @if($errors->any())
                <div class="mt-3 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ url('/chia-se/'.$token.'/phan-hoi') }}" class="card mt-3 p-4">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="fb-name">Tên của bạn</label>
                        <input id="fb-name" name="author_name" value="{{ old('author_name') }}" maxlength="120" required class="input !py-2" placeholder="VD: Chị Hương — khách">
                    </div>
                    <div>
                        <label class="label" for="fb-decision">Quyết định</label>
                        <select id="fb-decision" name="decision" required class="input !py-2">
                            <option value="approved" @selected(old('decision') === 'approved')>Duyệt — dùng được</option>
                            <option value="changes" @selected(old('decision') === 'changes')>Yêu cầu sửa</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="label" for="fb-message">Ghi chú (nếu yêu cầu sửa thì nêu rõ chỗ cần sửa)</label>
                    <textarea id="fb-message" name="message" rows="3" maxlength="1000" class="input !py-2" placeholder="VD: Ảnh 03 đổi nền sáng hơn, ảnh 05 giữ nguyên tay áo">{{ old('message') }}</textarea>
                </div>
                <button type="submit" class="btn-brand mt-3">Gửi phản hồi</button>
                <p class="mt-2 text-[11px] text-cream-300">Phản hồi được lưu vào bộ sưu tập trong FabrikAI (kèm tên bạn và thời điểm) — không cần tài khoản.</p>
            </form>

            @if($feedback->isNotEmpty())
                <div class="mt-5">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-cream-300">Phản hồi trước đó</p>
                    <ul class="mt-2 space-y-2">
                        @foreach($feedback as $fb)
                            <li class="card p-3">
                                <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                    <span class="font-semibold text-cream-100">{{ $fb->author_name }}</span>
                                    <span class="rounded-full px-2 py-0.5 font-semibold {{ $fb->decision === 'approved' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300' }}">{{ $fb->decisionLabel() }}</span>
                                    <span class="text-cream-300">{{ $fb->created_at?->format('d/m/Y H:i') }}</span>
                                </div>
                                @if($fb->message)
                                    <p class="mt-1 whitespace-pre-line text-xs text-cream-200">{{ $fb->message }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <footer class="mt-10 border-t border-ink-800 pt-6">
            <p class="text-[11px] leading-relaxed text-cream-300">
                <b class="text-cream-100">Lưu ý:</b> ảnh trong bộ sưu tập do AI tạo, dùng làm ảnh <b class="text-cream-100">tham chiếu</b> để duyệt ý tưởng —
                không dùng để in hoặc sản xuất hàng loạt khi chưa đối chiếu mẫu thật.
                Trang này chỉ dành cho người nhận link; vui lòng không chia sẻ công khai.
            </p>
        </footer>
    </main>
</div>
@endsection
