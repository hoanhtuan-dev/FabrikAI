<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// [R5] Dọn file orphan trong storage mỗi ngày lúc 03:00 — chạy qua queue để không chặn request.
Schedule::command('studio:clean-storage --queue')->dailyAt('03:00')->onOneServer();

// [2026-09-23] NGUỒN NGOÀI + TÍN HIỆU THỊ TRƯỜNG.
//
// Vì sao phải có lịch: model đang chạy không tự ra internet được, nên dữ liệu thị trường CHỈ có khi máy
// chủ đi lấy. Trước đây không có lịch nào ⇒ tin chỉ được lấy khi có người mở màn hình, còn giao diện vẫn
// ghi "tự động lấy mỗi 30 phút" (một câu sai). Nay câu đó ĐÚNG: cứ 30 phút máy chủ tự lấy tin và ĐO tín
// hiệu, nên lúc nào cũng có dữ liệu sẵn và có LỊCH SỬ để nói tăng/giảm.
Schedule::command('studio:market-signals')->everyThirtyMinutes()->onOneServer()->withoutOverlapping();

// NHỊP TIM CỦA LỊCH CHẠY NỀN — để giao diện nói ĐÚNG "máy chủ tự làm mới tin mỗi 30 phút" hay không.
//
// Vì sao cần: câu đó từng được viết cứng trên giao diện trong khi host không có cron nào gọi schedule:run ⇒
// người dùng tin dữ liệu luôn tươi. Nay lịch chạy nền tự ghi nhịp 5 phút/lần (TTL 30 phút); không có cron
// thì nhịp hết hạn và giao diện tự đổi sang câu đúng: "tin chỉ mới khi mở màn hình hoặc bấm Cập nhật tin".
Schedule::call(function () {
    Cache::put('studio:scheduler:heartbeat', now()->toISOString(), now()->addMinutes(30));
})->everyFiveMinutes()->name('studio-scheduler-heartbeat')->onOneServer();
// Dọn lần đo cũ mỗi ngày để bảng tín hiệu không phình mãi.
Schedule::command('studio:market-signals --prune')->dailyAt('03:30')->onOneServer();
