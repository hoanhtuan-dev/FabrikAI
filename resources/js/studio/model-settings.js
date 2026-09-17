import { createApp } from 'vue';
import ModelSettingsApp from './ModelSettingsApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(ModelSettingsApp), '#model-settings-root');
