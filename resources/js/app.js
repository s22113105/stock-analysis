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
import App from './app.vue';

// Axios 設定
import axios from 'axios';

// ==========================================
// Axios 基礎配置
// ==========================================
axios.defaults.baseURL = '/api';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['X-CSRF-TOKEN'] = 
    window.Laravel?.csrfToken || 
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

// ==========================================
// Axios 請求攔截器 - 自動添加 Token
// ==========================================
axios.interceptors.request.use(
    (config) => {
        // 從 localStorage 獲取 token
        const token = localStorage.getItem('authToken');
        
        if (token) {
            // 如果有 token,自動添加到 Authorization header
            config.headers.Authorization = `Bearer ${token}`;
        }
        
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// ==========================================
// Axios 響應攔截器 - 處理 401 錯誤
// ==========================================
axios.interceptors.response.use(
    (response) => {
        // 成功響應直接返回
        return response;
    },
    (error) => {
        if (error.response) {
            // 處理 401 未授權錯誤
            if (error.response.status === 401) {
                console.log('🔒 Token 已過期或無效,重新導向至登入頁');
                
                // 清除本地存儲
                localStorage.removeItem('authToken');
                localStorage.removeItem('user');
                
                // 移除 axios 預設 header
                delete axios.defaults.headers.common['Authorization'];
                
                // 如果不在登入頁,則導向登入頁
                if (window.location.pathname !== '/login') {
                    router.push({
                        path: '/login',
                        query: { redirect: window.location.pathname }
                    });
                }
            }
            
            // 處理 403 禁止訪問錯誤
            if (error.response.status === 403) {
                console.log('🚫 沒有權限訪問此資源');
            }
            
            // 處理 422 驗證錯誤
            if (error.response.status === 422) {
                console.log('⚠️ 驗證錯誤:', error.response.data.errors);
            }
            
            // 處理 500 伺服器錯誤
            if (error.response.status === 500) {
                console.error('❌ 伺服器錯誤:', error.response.data);
            }
        } else if (error.request) {
            // 請求已發送但沒有收到響應
            console.error('🌐 網路錯誤,請檢查網路連線');
        } else {
            // 設定請求時發生錯誤
            console.error('⚠️ 請求配置錯誤:', error.message);
        }
        
        return Promise.reject(error);
    }
);

// ==========================================
// 初始化載入 Token 到 Axios
// ==========================================
const initializeAuth = () => {
    const token = localStorage.getItem('authToken');
    
    if (token) {
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
        console.log('✅ Token 已載入到 Axios');
    } else {
        console.log('ℹ️ 未找到 Token');
    }
};

// 執行初始化
initializeAuth();

// ==========================================
// 建立 Vuetify 實例
// ==========================================
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
                    warning: '#FFC107',
                    // 台股特色配色
                    up: '#FF0000',      // 漲 - 紅色
                    down: '#00FF00',    // 跌 - 綠色
                    flat: '#FFFF00'     // 平盤 - 黃色
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
                    warning: '#FFC107',
                    // 台股特色配色 (深色模式)
                    up: '#FF6B6B',      // 漲 - 紅色 (較柔和)
                    down: '#51CF66',    // 跌 - 綠色 (較柔和)
                    flat: '#FFD43B'     // 平盤 - 黃色 (較柔和)
                }
            }
        }
    }
});

// ==========================================
// 建立 Vue 應用
// ==========================================
const app = createApp(App);

// 使用插件
app.use(createPinia());
app.use(router);
app.use(vuetify);

// 全域屬性
app.config.globalProperties.$axios = axios;

// 全域錯誤處理
app.config.errorHandler = (err, instance, info) => {
    console.error('全域錯誤:', err);
    console.error('錯誤資訊:', info);
};

// ==========================================
// 掛載應用
// ==========================================
app.mount('#app');

// 移除載入畫面
const loadingElement = document.getElementById('app-loading');
if (loadingElement) {
    setTimeout(() => {
        loadingElement.style.display = 'none';
    }, 100);
}

// ==========================================
// 開發環境日誌
// ==========================================
if (import.meta.env.DEV) {
    console.log('🚀 Stock_Analysis 系統已啟動');
    console.log('📦 Vue 版本:', app.version);
    console.log('🎨 Vuetify 已載入');
    console.log('🛣️ Vue Router 已載入');
    console.log('📡 Axios 攔截器已設定');
}