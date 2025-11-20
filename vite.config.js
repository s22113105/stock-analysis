import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import vuetify from 'vite-plugin-vuetify';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js'
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // ✅ Vuetify 插件設定
        // styles: { configFile: 'resources/sass/variables.scss' } // 如果您有自定義變數可開啟
        vuetify({ 
            autoImport: true,
            styles: {
                configFile: 'resources/css/settings.scss', // 或者是您的自定義樣式路徑，如果沒有可移除此行
            }
        }),
    ],
    resolve: {
        alias: {
            // ✅ 使用 path.resolve 確保路徑正確
            '@': path.resolve(__dirname, 'resources/js'), 
            '~': path.resolve(__dirname, 'node_modules'), // 有助於 SCSS 引用 node_modules
            'vue': 'vue/dist/vue.esm-bundler.js',
        },
    },
    // ✅ Docker 環境設定 (保持您原本正確的設定)
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: 'localhost',
        },
        watch: {
            usePolling: true, // 解決 Windows Docker 文件同步延遲
        },
    },
});
