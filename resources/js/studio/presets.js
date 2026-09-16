import { createApp } from 'vue';
import PresetsApp from './PresetsApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(PresetsApp), '#presets-root');
