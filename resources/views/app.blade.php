<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Stock_Analysis - 台股選擇權交易分析系統</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Material Design Icons -->
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">

    <!-- Vuetify CSS (如果不使用 Vite 編譯) -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/vuetify@3.3.0/dist/vuetify.min.css" rel="stylesheet"> -->

    <!-- Vite 處理的資源 -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Loading 畫面樣式 */
        #app-loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #1976D2;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            margin-top: 20px;
            font-size: 18px;
            color: #666;
            font-family: 'Noto Sans TC', sans-serif;
        }

        /* 隱藏未載入完成的內容 */
        [v-cloak] {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Loading 畫面 -->
    <div id="app-loading">
        <div class="text-center">
            <div class="loading-spinner"></div>
            <div class="loading-text">Stock_Analysis 載入中...</div>
        </div>
    </div>

    <!-- Vue 應用掛載點 -->
    <div id="app" v-cloak></div>

    <!-- 環境變數傳遞給 Vue -->
    <script>
        window.Laravel = {
            csrfToken: '{{ csrf_token() }}',
            apiUrl: '{{ config('app.url') }}/api',
            baseUrl: '{{ config('app.url') }}',
            appName: '{{ config('app.name', 'Stock_Analysis') }}',
            environment: '{{ config('app.env') }}'
        };
    </script>

    <!-- 移除 Loading 畫面的腳本 -->
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const loading = document.getElementById('app-loading');
                if (loading) {
                    loading.style.transition = 'opacity 0.3s';
                    loading.style.opacity = '0';
                    setTimeout(function() {
                        loading.style.display = 'none';
                    }, 300);
                }
            }, 500);
        });
    </script>
</body>
</html>
