import { createApp } from 'vue';
import { createPinia } from 'pinia';
import AdminApp from './AdminApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(AdminApp).use(createPinia()), '#admin-root');
