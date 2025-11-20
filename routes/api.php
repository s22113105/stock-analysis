<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * ============================================
 * API Routes 完整範例
 * ============================================
 *
 * 這是完整的 routes/api.php 範例
 * 包含所有必要的路由設定
 */

// 導入所有需要的控制器
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\BlackScholesController;
use App\Http\Controllers\VolatilityController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\BacktestController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 公開路由 (不需要認證)
// ==========================================

// 認證相關
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// ==========================================
// Dashboard API (儀表板)
// ==========================================
Route::prefix('dashboard')->group(function () {
    // 基本統計資訊
    Route::get('/stats', [DashboardController::class, 'stats']);

    // 投資組合 (如果有實作)
    Route::get('/portfolio', [DashboardController::class, 'portfolio']);

    // 績效資訊 (如果有實作)
    Route::get('/performance', [DashboardController::class, 'performance']);

    // 警示資訊 (如果有實作)
    Route::get('/alerts', [DashboardController::class, 'alerts']);

    // 🌟 新增: 圖表資料端點
    Route::get('/stock-trends', [DashboardController::class, 'stockTrends']);
    Route::get('/volatility-overview', [DashboardController::class, 'volatilityOverview']);
});

// ==========================================
// Stock API (股票)
// ==========================================
Route::prefix('stocks')->group(function () {
    // 列表和查詢
    Route::get('/', [StockController::class, 'index']);
    Route::get('/{id}', [StockController::class, 'show']);
    Route::get('/symbol/{symbol}', [StockController::class, 'getBySymbol']);

    // 價格資料
    Route::get('/{id}/prices', [StockController::class, 'prices']);
    Route::get('/{id}/latest-price', [StockController::class, 'latestPrice']);

    // 統計資訊
    Route::get('/{id}/statistics', [StockController::class, 'statistics']);
});

// ==========================================
// Option API (選擇權)
// ==========================================
Route::prefix('options')->group(function () {
    Route::get('/', [OptionController::class, 'index']);
    Route::get('/{id}', [OptionController::class, 'show']);
    Route::get('/chain/{underlying}', [OptionController::class, 'chain']);
});

// ==========================================
// Black-Scholes API
// ==========================================
Route::prefix('black-scholes')->group(function () {
    Route::post('/calculate', [BlackScholesController::class, 'calculate']);
    Route::post('/batch', [BlackScholesController::class, 'batchCalculate']);
});

// ==========================================
// Volatility API (波動率)
// ==========================================
Route::prefix('volatility')->group(function () {
    Route::get('/historical/{stock_id}', [VolatilityController::class, 'historical']);
    Route::get('/implied/{option_id}', [VolatilityController::class, 'implied']);
    Route::get('/compare/{stock_id}', [VolatilityController::class, 'compare']);
});

// ==========================================
// Prediction API (預測)
// ==========================================
Route::prefix('predictions')->group(function () {
    // LSTM 預測
    Route::post('/lstm', [PredictionController::class, 'lstm']);

    // ARIMA 預測
    Route::post('/arima', [PredictionController::class, 'arima']);

    // GARCH 預測
    Route::post('/garch', [PredictionController::class, 'garch']);

    // 取得歷史預測
    Route::get('/history/{stock_id}', [PredictionController::class, 'history']);
});

// ==========================================
// Backtest API (回測)
// ==========================================
Route::prefix('backtest')->group(function () {
    Route::post('/run', [BacktestController::class, 'run']);
    Route::get('/results', [BacktestController::class, 'results']);
    Route::get('/results/{id}', [BacktestController::class, 'show']);
});

// ==========================================
// Crawler API (爬蟲管理)
// ==========================================
Route::prefix('crawler')->middleware('auth:sanctum')->group(function () {
    // 手動觸發爬蟲
    Route::post('/stocks', [CrawlerController::class, 'crawlStocks']);
    Route::post('/options', [CrawlerController::class, 'crawlOptions']);

    // 爬蟲狀態
    Route::get('/status', [CrawlerController::class, 'status']);
});

// ==========================================
// 需要認證的路由
// ==========================================
Route::middleware('auth:sanctum')->group(function () {
    // 用戶資訊
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // 登出
    Route::post('/logout', [AuthController::class, 'logout']);

    // 其他需要認證的路由...
});

// ==========================================
// 健康檢查
// ==========================================
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'Stock Analysis API'
    ]);
});

// ==========================================
// 測試路由 (開發環境)
// ==========================================
if (app()->environment('local')) {
    Route::get('/test', function () {
        return response()->json([
            'message' => 'API is working!',
            'environment' => app()->environment(),
            'timestamp' => now()->toISOString()
        ]);
    });
}
