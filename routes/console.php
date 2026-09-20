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
// Dọn lần đo cũ mỗi ngày để bảng tín hiệu không phình mãi.
Schedule::command('studio:market-signals --prune')->dailyAt('03:30')->onOneServer();
