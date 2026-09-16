import { createApp } from 'vue';
import { createPinia } from 'pinia';
import StylistDataApp from './StylistDataApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(StylistDataApp).use(createPinia()), '#stylist-data-root');
