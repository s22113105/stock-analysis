import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';

// Vue Router
import router from './router';

// Vuetify
import 'vuetify/styles';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';

// 主應用元件
import App from './App.vue';

// Axios 設定
import axios from 'axios';
axios.defaults.baseURL = '/api';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['X-CSRF-TOKEN'] = window.Laravel?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

// 建立 Vuetify 實例
const vuetify = createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: 'light',
        themes: {
            light: {
                dark: false,
                colors: {
                    primary: '#1976D2',
                    secondary: '#424242',
                    accent: '#82B1FF',
                    error: '#FF5252',
                    info: '#2196F3',
                    success: '#4CAF50',
                    warning: '#FFC107'
                }
            },
            dark: {
                dark: true,
                colors: {
                    primary: '#2196F3',
                    secondary: '#424242',
                    accent: '#FF4081',
                    error: '#FF5252',
                    info: '#2196F3',
                    success: '#4CAF50',
                    warning: '#FFC107'
                }
            }
        }
    }
});

// 建立 Vue 應用
const app = createApp(App);

// 使用插件
app.use(createPinia());
app.use(router);
app.use(vuetify);

// 全域屬性
app.config.globalProperties.$axios = axios;

// 掛載應用
app.mount('#app');

// 移除載入畫面
const loadingElement = document.getElementById('app-loading');
if (loadingElement) {
    setTimeout(() => {
        loadingElement.style.display = 'none';
    }, 100);
}
