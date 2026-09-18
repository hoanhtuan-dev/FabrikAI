/**
 * Entry của KHU "CÀI ĐẶT CỦA TÔI" hợp nhất.
 *
 * Thay cho 3 entry cũ (presets.js · stylist-data.js · model-settings.js) vốn mount 3 app khác nhau
 * ở 3 trang rời rạc. Nay MỘT app duy nhất được 4 URL cùng dùng; server quyết định mở mục nào qua
 * thuộc tính data-section trên element gốc (xem StudioController::mySettingsPage).
 */
import { createApp } from 'vue';
import MySettingsApp from './MySettingsApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(MySettingsApp), '#my-settings-root');
