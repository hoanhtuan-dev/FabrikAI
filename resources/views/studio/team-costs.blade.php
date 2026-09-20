@extends('layouts.app')

@section('title', 'Chi phí theo nhóm')
@section('meta')
    <meta name="robots" content="noindex, nofollow">
@endsection

@section('content')
{{--
    BÁO CÁO CHI PHÍ THEO NHÓM (2026-09-23) — cấp Owner, server-render.

    Số liệu cộng thẳng từ bảng generations (cột credits_cost) trong khoảng đã chọn: cùng nguồn với việc
    trừ credit khi tạo ảnh, nên báo cáo không thể lệch với thực tế. Không có ô nhập nào ở đây — đây là
    trang ĐỌC; muốn đổi gói/ghế thì vào trang Quản trị.
--}}
<div class="studio-shell min-h-screen">
    <div class="container-x py-8">
        <header class="mb-6">
            <p class="kicker">FabrikAI · vận hành</p>
            <h1 class="mt-1 font-display text-2xl font-semibold text-cream-50">Chi phí theo nhóm</h1>
            <p class="mt-2 max-w-3xl text-body leading-relaxed text-cream-200">
                Mỗi dòng là MỘT NHÓM (chủ nhóm + các ghế thành viên dùng chung gói). Số ảnh và credit được
                cộng từ chính bảng <span class="font-mono">generations</span> trong
                <b class="text-cream-100">{{ $days }} ngày</b> gần nhất (từ {{ $since->format('d/m/Y') }}) — cùng nguồn
                với việc trừ credit khi tạo ảnh, nên không thể lệch với thực tế.
            </p>
            <nav class="mt-3 flex flex-wrap gap-2" aria-label="Khoảng thời gian">
                @foreach ($periods as $p)
                    <a href="{{ route('team-costs.page', ['days' => $p]) }}"
                       class="rounded-lg border px-3 py-1.5 text-label font-semibold {{ $days === $p ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-200 hover:border-brand-400' }}">
                        {{ $p }} ngày
                    </a>
                @endforeach
            </nav>
        </header>

        <div class="mb-5 grid gap-3 sm:grid-cols-3">
            <div class="card p-4">
                <p class="text-label uppercase tracking-wide text-cream-300">Tổng credit đã dùng</p>
                <p class="mt-1 font-display text-2xl font-semibold text-cream-50">{{ number_format($totalCredits, 0, ',', '.') }}</p>
            </div>
            <div class="card p-4">
                <p class="text-label uppercase tracking-wide text-cream-300">Tổng ảnh</p>
                <p class="mt-1 font-display text-2xl font-semibold text-cream-50">{{ number_format($totalImages, 0, ',', '.') }}</p>
            </div>
            <div class="card p-4">
                <p class="text-label uppercase tracking-wide text-cream-300">Số nhóm</p>
                <p class="mt-1 font-display text-2xl font-semibold text-cream-50">{{ count($rows) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem] border-collapse text-left text-xs">
                <thead>
                    <tr class="border-b border-ink-700 text-tiny uppercase tracking-wide text-cream-300">
                        <th class="py-2 pr-3">Nhóm (chủ nhóm)</th>
                        <th class="py-2 pr-3">Ghế</th>
                        <th class="py-2 pr-3">Ảnh</th>
                        <th class="py-2 pr-3">Credit</th>
                        <th class="py-2">Hoạt động gần nhất</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-ink-700/60">
                            <td class="py-2 pr-3">
                                <span class="font-semibold text-cream-100">{{ $row['owner']->name }}</span>
                                <span class="block text-tiny text-cream-400">{{ $row['owner']->email }}</span>
                            </td>
                            <td class="py-2 pr-3 text-cream-200">{{ $row['seats'] }}@if ($row['members'] > 0) <span class="text-cream-400">(+{{ $row['members'] }} thành viên)</span>@endif</td>
                            <td class="py-2 pr-3 tabular-nums text-cream-200">{{ number_format($row['images'], 0, ',', '.') }}</td>
                            <td class="py-2 pr-3 tabular-nums font-semibold text-cream-100">{{ number_format($row['credits'], 0, ',', '.') }}</td>
                            <td class="py-2 text-cream-300">{{ $row['last_at'] ? \Illuminate\Support\Carbon::parse($row['last_at'])->diffForHumans() : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-cream-300">Chưa có lượt tạo ảnh nào trong {{ $days }} ngày qua.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="mt-6 rounded-lg border border-ink-700 bg-ink-900 p-4 text-label leading-relaxed text-cream-300">
            Nhóm chỉ gồm <b class="text-cream-100">chủ nhóm</b> nếu chưa mời thành viên nào. Ảnh do thành viên tạo
            được cộng vào nhóm của chủ nhóm đó — đúng nghĩa "chi phí của nhóm dùng chung gói".
            Trang này chỉ ĐỌC: đổi gói/ghế ở <b class="text-cream-100">Quản trị Owner</b>.
        </footer>
    </div>
</div>
@endsection
