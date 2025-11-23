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
        // ✅ Vuetify 插件設定 (保留您的設定)
        vuetify({
            autoImport: true,
            styles: {
                configFile: 'resources/css/settings.scss',
            }
        }),
    ],
    resolve: {
        alias: {
            // ✅ 保留您正確的路徑設定
            '@': path.resolve(__dirname, 'resources/js'),
            '~': path.resolve(__dirname, 'node_modules'),
            'vue': 'vue/dist/vue.esm-bundler.js',
        },
    },
    // ✅ Docker 環境設定
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: 'localhost',
        },
        watch: {
            usePolling: true, // 保留這行，對 Windows Docker 很重要
        },
        // 👇👇👇 這裡就是讓前端能拿到資料的關鍵！ 👇👇👇
        proxy: {
            '/api': {
                target: 'http://stock-analysis-app:8000', // 指向後端容器
                changeOrigin: true,
                secure: false,
                // 確保路徑正確傳遞
                rewrite: (path) => path.replace(/^\/api/, '/api'),
                configure: (proxy, _options) => {
                    proxy.on('error', (err, _req, _res) => {
                        console.log('Proxy error:', err);
                    });
                    proxy.on('proxyReq', (proxyReq, req, _res) => {
                        console.log('Sending Request:', req.method, req.url);
                    });
                    proxy.on('proxyRes', (proxyRes, req, _res) => {
                        console.log('Received Response:', proxyRes.statusCode, req.url);
                    });
                },
            },
        },
        // 👆👆👆 結束 👆👆👆
    },
});
