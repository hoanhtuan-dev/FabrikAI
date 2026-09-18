import { createApp } from 'vue';
import { createPinia } from 'pinia';
import CollectionsPage from './pages/CollectionsPage.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(CollectionsPage).use(createPinia()), '#collections-root');
