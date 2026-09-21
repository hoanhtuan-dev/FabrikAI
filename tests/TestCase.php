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
     * [2026-09-25] Agent Studio chuyển từ MODAL trong /studio (components/DesignAgents.vue) thành
     * một TRANG riêng: khung nằm ở AgentStudioApp.vue, logic ở composables/useAgentStudio.js,
     * còn 4 bước vẫn ở components/agents/*.vue. Test khoá luật quét giao diện vẫn phải đọc đủ mọi
     * mảnh như trước — hàm này nối đúng bốn nhóm đó theo thứ tự ổn định.
     */
    protected static function designAgentsSource(): string
    {
        $base = resource_path('js/studio');
        $files = array_merge(
            [$base.'/AgentStudioApp.vue', $base.'/composables/useAgentStudio.js'],
            glob($base.'/components/agents/*.vue') ?: [],
        );

        foreach ($files as $f) {
            if (! is_file($f)) {
                throw new \RuntimeException('Agent Studio: thiếu tệp nguồn '.$f.' — test khoá luật giao diện sẽ đọc thiếu mảnh.');
            }
        }

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
