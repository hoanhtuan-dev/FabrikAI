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

// [Việc #4 — 2026-09-26] NHẮC HẠN MẪU VẬT LÝ, mỗi sáng.
//
// Vì sao 08:00 chứ không phải nửa đêm: đây là thư cho NGƯỜI, không phải việc dọn dẹp máy. Gửi lúc 3 giờ
// sáng thì thư nằm đáy hộp thư lúc họ mở máy — và công dụng duy nhất của nó là để người ta LÀM gì đó
// trong ngày. Chính lệnh tự chống trùng theo (tài khoản · ngày) nên chạy lại cũng không dội hộp thư.
Schedule::command('studio:samples:remind')->dailyAt('08:00')->onOneServer()->withoutOverlapping();

// [2026-09-26] CẤP CREDIT THEO CHU KỲ GÓI — gộp về MỘT chỗ quản lịch.
//
// Vì sao chuyển vào đây: lệnh này vốn có một entry cron RIÊNG trong hPanel, nghĩa là lịch của hệ thống
// nằm ở HAI nơi (một phần trong mã, một phần trong panel) — và lần trước đã có người thêm lại đúng
// entry đó với dạng lệnh sai khiến nó không chạy mà không ai thấy. Từ khi `schedule:run` chạy mỗi phút,
// không còn lý do gì để giữ entry riêng: xoá nó trong hPanel và mọi lịch nằm trong tệp này.
//
// 00:30 giữ ĐÚNG giờ của entry cũ để không đổi hành vi cấp credit của khách đang dùng.
// Lệnh tự idempotent (PlanService dùng CAS + transaction) nên nếu entry cũ còn sót lại thì chạy hai lần
// cũng KHÔNG cấp trùng — nhưng vẫn nên xoá để chỉ còn một nguồn sự thật.
Schedule::command('studio:grant-plan-credits')->dailyAt('00:30')->onOneServer()->withoutOverlapping();

// [Việc #5 — 2026-09-26] CỦNG CỐ TRÍ NHỚ: suy yếu ký ức lâu không dùng + quên ký ức đã yếu và đã cũ.
//
// Vì sao phải theo LỊCH: "suy yếu" và "quên" là việc chỉ THỜI GIAN làm được. Không có lượt chạy này thì
// mọi ký ức nặng mãi như nhau, cửa sổ prompt bị lấp bởi ký ức cũ ⇒ trí nhớ DÀY lên nhưng không SẮC hơn.
// 04:00 chạy sau hai việc dọn dẹp kia (03:00 storage · 03:30 prune tín hiệu) để không tranh nhau ghi DB.
// [Việc #9 — 2026-09-26] Lập chỉ mục tìm kiếm thiết kế cũ. 04:30, sau khi trí nhớ đã củng cố xong (04:00)
// để bài học mới rút trong đêm cũng được nhúng trong cùng lượt.
Schedule::command('studio:search:index')->dailyAt('04:30')->onOneServer()->withoutOverlapping();

Schedule::command('studio:memory:consolidate')->dailyAt('04:00')->onOneServer()->withoutOverlapping();
