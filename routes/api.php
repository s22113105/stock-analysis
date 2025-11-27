<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * ============================================
 * API Routes - Stock_Analysis System
 * ============================================
 */

// 導入所有需要的控制器
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\Api\OptionChainController; // [新功能] 選擇權 T 字報價表控制器
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\BlackScholesController;
use App\Http\Controllers\VolatilityController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\CrawlerController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 公開路由 (不需要認證)
// ==========================================

// 認證路由
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// ==========================================
// Dashboard API (儀表板)
// ==========================================
Route::prefix('dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/portfolio', [DashboardController::class, 'portfolio']);
    Route::get('/performance', [DashboardController::class, 'performance']);
    Route::get('/alerts', [DashboardController::class, 'alerts']);
    Route::get('/stock-trends', [DashboardController::class, 'stockTrends']);
    Route::get('/volatility-overview', [DashboardController::class, 'volatilityOverview']);
});

// ==========================================
// Stock API (股票)
// ==========================================
Route::prefix('stocks')->group(function () {
    Route::get('/', [StockController::class, 'index']);
    Route::get('/{id}', [StockController::class, 'show']);
    Route::get('/symbol/{symbol}', [StockController::class, 'getBySymbol']);
    Route::get('/{id}/prices', [StockController::class, 'prices']);
    Route::get('/{id}/latest-price', [StockController::class, 'latestPrice']);
    Route::get('/{id}/statistics', [StockController::class, 'statistics']);
});

// ==========================================
// Option API (選擇權)
// ==========================================
Route::prefix('options')->group(function () {
    // 列表
    Route::get('/', [OptionController::class, 'index']);

    // ⭐⭐⭐ 這兩行必須在 {id} 之前 ⭐⭐⭐
    Route::get('/chain-table', [OptionChainController::class, 'getChainTable']);
    Route::get('/chain/{underlying}', [OptionController::class, 'chain']);

    // TXO 分析
    Route::prefix('txo')->group(function () {
        Route::get('/trend', [OptionController::class, 'txoTrend']);
        Route::get('/volume-analysis', [OptionController::class, 'txoVolumeAnalysis']);
        Route::get('/oi-analysis', [OptionController::class, 'txoOiAnalysis']);
        Route::get('/iv-analysis', [OptionController::class, 'txoIvAnalysis']);
        Route::get('/sentiment', [OptionController::class, 'txoSentiment']);
        Route::get('/oi-distribution', [OptionController::class, 'txoOiDistribution']);
    });

    // ⭐⭐⭐ {id} 必須放最後 ⭐⭐⭐
    Route::get('/{id}', [OptionController::class, 'show']);
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
    Route::get('/market-iv/{stockId}', [VolatilityController::class, 'marketIV']);  // ← 加入這行

    // [修正] 補上缺失的路由，並移除不存在的 compare
    Route::get('/cone/{stock_id}', [VolatilityController::class, 'cone']);
    Route::get('/surface/{stock_id}', [VolatilityController::class, 'surface']);
    Route::get('/skew/{stock_id}', [VolatilityController::class, 'skew']);
    Route::get('/garch/{stock_id}', [VolatilityController::class, 'garch']);

    // 手動觸發計算
    Route::post('/calculate', [VolatilityController::class, 'calculate']);
});

// ==========================================
// Prediction API (預測)
// ==========================================
Route::prefix('predictions')->group(function () {
    Route::post('/run', [PredictionController::class, 'run']);
    Route::post('/lstm', [PredictionController::class, 'lstm']);
    Route::post('/arima', [PredictionController::class, 'arima']);
    Route::post('/garch', [PredictionController::class, 'garch']);
    Route::get('/history', [PredictionController::class, 'history']);
    Route::get('/{id}', [PredictionController::class, 'show']);
});

// ==========================================
// Backtest API (回測)
// ==========================================
Route::prefix('backtest')->group(function () {
    Route::post('/run', [BacktestController::class, 'run']);
    Route::get('/strategies', [BacktestController::class, 'strategies']);
    Route::get('/results', [BacktestController::class, 'results']);
    Route::get('/results/{id}', [BacktestController::class, 'showResult']);
});

// ==========================================
// Crawler API (爬蟲管理)
// ==========================================
Route::prefix('crawler')->group(function () {
    Route::post('/stocks', [CrawlerController::class, 'crawlStocks']);
    Route::post('/options', [CrawlerController::class, 'crawlOptions']);
    Route::get('/status', [CrawlerController::class, 'status']);
    Route::get('/logs', [CrawlerController::class, 'logs']);
});

// ==========================================
// 測試路由 (開發用)
// ==========================================
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is working!',
        'timestamp' => now()->toIso8601String()
    ]);
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
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

// ==========================================
// Debug 路由 (診斷用)
// ==========================================
Route::get('/debug/data-check', function () {
    $prices = \App\Models\OptionPrice::count();
    $options = \App\Models\Option::count();
    // 檢查有多少價格是對應不到合約的
    $orphaned = \App\Models\OptionPrice::doesntHave('option')->count();

    $latest = \App\Models\OptionPrice::max('trade_date');

    return [
        'status' => ($orphaned > 0) ? 'DATA_CORRUPTED' : 'DATA_OK',
        'total_prices' => $prices,
        'total_options' => $options,
        'orphaned_prices_count' => $orphaned . ' (這些價格找不到對應的合約)',
        'latest_trade_date' => $latest,
        'message' => ($orphaned > 0) ? '資料庫關聯已斷裂，必須重置資料庫！' : '資料庫結構正常'
    ];
});
