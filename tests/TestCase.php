<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Toàn bộ source của studio store NHƯ MỘT văn bản duy nhất.
     *
     * Từ đợt tối ưu 2026-09-24, store.js (5.658 dòng) được tách thành các module theo miền
     * ở resources/js/studio/store/. Các test khoá luật bằng cách quét source (safeMessage,
     * toast là cửa chặn, không double-notify…) vẫn phải đọc đủ mọi mảnh như trước — hàm này
     * nối store.js + store/*.js + store/actions/*.js theo thứ tự ổn định để điều đó đúng.
     */
    /**
     * Toàn bộ source của Agent Studio NHƯ MỘT văn bản duy nhất.
     *
     * Từ đợt tối ưu 2026-09-24, DesignAgents.vue tách template theo 4 bước sang
     * components/agents/*.vue (shell giữ script + khung). Test khoá luật quét giao diện vẫn
     * phải đọc đủ mọi mảnh như trước — hàm này nối shell + 4 bước theo thứ tự ổn định.
     */
    protected static function designAgentsSource(): string
    {
        $base = resource_path('js/studio/components');
        $files = array_merge(
            [$base.'/DesignAgents.vue'],
            glob($base.'/agents/*.vue') ?: [],
        );

        return implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $files));
    }

    protected static function studioStoreSource(): string
    {
        $base = resource_path('js/studio');
        $modules = array_merge(
            glob($base.'/store/*.js') ?: [],
            glob($base.'/store/actions/*.js') ?: [],
        );
        sort($modules);
        $files = array_merge([$base.'/store.js'], $modules);

        return implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $files));
    }
}
