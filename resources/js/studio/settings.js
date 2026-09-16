import { createApp } from 'vue';
import { createPinia } from 'pinia';
import SettingsApp from './SettingsApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(SettingsApp).use(createPinia()), '#settings-root');
