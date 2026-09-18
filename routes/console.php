<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// [R5] Dọn file orphan trong storage mỗi ngày lúc 03:00 — chạy qua queue để không chặn request.
Schedule::command('studio:clean-storage --queue')->dailyAt('03:00')->onOneServer();
