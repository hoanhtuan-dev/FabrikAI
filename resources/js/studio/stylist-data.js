import { createApp } from 'vue';
import { createPinia } from 'pinia';
import StylistDataApp from './StylistDataApp.vue';
createApp(StylistDataApp).use(createPinia()).mount('#stylist-data-root');
