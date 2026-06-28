# 📈 Stock_Analysis - Laravel 10 + Vue 3

台股選擇權交易分析系統，結合 Black-Scholes 定價模型、波動率分析與策略回測功能。

## 🎯 系統特色

- **即時數據爬蟲**：自動擷取 TWSE/Yahoo Finance 股票與選擇權資料
- **Black-Scholes 定價**：計算理論價格、隱含波動率(IV)、歷史波動率(HV)
- **預測模型**：整合 LSTM/ARIMA/GARCH 時間序列預測
- **策略回測**：支援多種交易策略回測與績效評估
- **即時推播**：WebSocket 即時資料更新
- **視覺化圖表**：Chart.js 互動式圖表展示

## 🛠️ 技術架構

### 後端
- Laravel 10 (PHP 8.2)
- MySQL 8.0
- Redis (Queue & Cache)
- Python (ML Models)

### 前端
- Vue 3 + Vite
- Vuetify 3 (UI Framework)
- Chart.js / ApexCharts
- Pinia (State Management)

### 部署
- Docker & Docker Compose
- Nginx
- Supervisor (Queue Worker)

## 📦 快速開始

### 系統需求
- Docker Desktop
- Git
- 至少 4GB RAM
- 10GB 可用硬碟空間

### 安裝步驟

1. **克隆專案**
```bash
git clone [your-repo-url]
cd stock_analysis
```

2. **執行初始化腳本**
```bash
chmod +x init.sh
./init.sh
```

3. **設定 API 金鑰**
編輯 `.env` 檔案，加入您的 API 金鑰：
```env
YAHOO_FINANCE_API_KEY=your_key
ALPHA_VANTAGE_API_KEY=your_key
```

4. **啟動開發伺服器**
```bash
# 啟動後端服務
docker-compose up -d

# 啟動前端開發伺服器
docker-compose exec node npm run dev
```

## 📁 專案結構

```
stock-analysis/
├── app/
│   ├── Models/           # Eloquent 模型
│   ├── Http/
│   │   ├── Controllers/  # API 控制器
│   │   └── Middleware/
│   ├── Jobs/             # 佇列任務
│   ├── Services/         # 商業邏輯
│   │   ├── BlackScholesService.php
│   │   ├── VolatilityService.php
│   │   ├── BacktestService.php
│   │   └── CrawlerService.php
│   └── Console/
│       └── Commands/     # Artisan 指令
├── database/
│   ├── migrations/       # 資料庫遷移
│   └── seeders/         # 測試資料
├── resources/
│   ├── js/              # Vue 3 應用程式
│   │   ├── components/  # Vue 元件
│   │   ├── views/       # 頁面視圖
│   │   ├── stores/      # Pinia stores
│   │   └── utils/       # 工具函數
│   └── css/
├── docker/              # Docker 設定檔
├── storage/            # 檔案儲存
└── public/            # 公開資源
```

## 🗄️ 資料庫架構

### 主要資料表
- `stocks` - 股票基本資料
- `stock_prices` - 股票價格歷史
- `options` - 選擇權合約
- `option_prices` - 選擇權價格
- `volatilities` - 波動率數據
- `predictions` - 預測結果
- `backtest_results` - 回測結果

## 📊 核心功能

### 1. 資料擷取 (Day 3-4)
```php
// 執行股票資料爬蟲
php artisan crawler:stocks

// 執行選擇權資料爬蟲
php artisan crawler:options
```

### 2. Black-Scholes 計算 (Day 5-6)
```php
// API 端點
POST /api/black-scholes/calculate
{
    "spot_price": 100,
    "strike_price": 105,
    "time_to_expiry": 0.25,
    "risk_free_rate": 0.02,
    "volatility": 0.3,
    "option_type": "call"
}
```

### 3. 波動率分析
```php
// 計算歷史波動率
GET /api/volatility/historical/{stock_id}?period=30

// 計算隱含波動率
GET /api/volatility/implied/{option_id}
```

### 4. 策略回測 (Day 10-11)
```php
// 執行回測
POST /api/backtest/run
{
    "strategy": "covered_call",
    "stock_id": 1,
    "start_date": "2024-01-01",
    "end_date": "2024-12-31",
    "parameters": {...}
}
```

## 🔧 常用指令

### Laravel Artisan
```bash
# 執行遷移
docker-compose exec app php artisan migrate

# 建立控制器
docker-compose exec app php artisan make:controller ApiController

# 清除快取
docker-compose exec app php artisan cache:clear

# 執行佇列
docker-compose exec app php artisan queue:work
```

### NPM 指令
```bash
# 開發模式
docker-compose exec node npm run dev

# 生產建置
docker-compose exec node npm run build

# 檢查程式碼
docker-compose exec node npm run lint
```

### Docker 指令
```bash
# 查看容器狀態
docker-compose ps

# 查看日誌
docker-compose logs -f app

# 進入容器
docker-compose exec app bash

# 重新建置
docker-compose build --no-cache

# 停止所有服務
docker-compose down
```

## 🌐 API 端點

| 方法 | 端點 | 描述 |
|------|------|------|
| GET | `/api/stocks` | 取得股票列表 |
| GET | `/api/stocks/{id}/prices` | 取得股價歷史 |
| GET | `/api/options/{id}` | 取得選擇權資料 |
| POST | `/api/black-scholes/calculate` | 計算理論價格 |
| GET | `/api/volatility/{id}` | 取得波動率數據 |
| POST | `/api/predictions/run` | 執行預測模型 |
| POST | `/api/backtest/run` | 執行回測 |

## 🔒 安全性

- 使用 Laravel Sanctum 進行 API 認證
- 所有 API 端點需要認證
- 敏感資料使用環境變數
- SQL Injection 防護
- XSS 防護
---
