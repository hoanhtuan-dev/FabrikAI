/**
 * Entry của TRANG HỢP NHẤT "Cài đặt & Quản trị".
 *
 * Thay cho ba entry rời settings.js · admin.js · my-settings.js (vẫn còn để các blade cũ không vỡ).
 * Một app (SettingsHubApp.vue) nhận biết đang ở KHU NÀO qua thuộc tính data-area trên #hub-root —
 * máy chủ quyết định, không đoán từ URL (xem StudioController::mySettingsPage / settingsPage,
 * AdminController::adminPage).
 */
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import SettingsHubApp from './SettingsHubApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
// MỘT pinia cho cả trang: SettingsApp và AdminApp đều dùng store, và chúng là component con của
// SettingsHubApp nên dùng chung đúng instance này (không tự tạo pinia riêng).
mountGuarded(createApp(SettingsHubApp).use(createPinia()), '#hub-root');
