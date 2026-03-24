<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * ============================================
 * API Routes - Stock_Analysis System
 * ============================================
 *
 * @version 2.1
 * @updated 2024-12
 * - 新增 Black-Scholes 進階分析 API
 * - 新增 Admin 路由群組（含 auth:sanctum 保護）
 * - 移除 Debug 路由（正式環境安全）
 * - 統一 Controller 命名空間
 */

// ==========================================
// 統一引入所有 Controller（命名空間一致）
// ==========================================
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\OptionChainController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\Api\OptionController;
use App\Http\Controllers\Api\BlackScholesController;
use App\Http\Controllers\Api\VolatilityController;
use App\Http\Controllers\Api\BacktestController;
use App\Http\Controllers\Api\CrawlerController;
use App\Http\Controllers\Api\AdminController;

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

    // ⭐ 靜態路由必須在 {id} 之前 ⭐
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

    // ⭐ {id} 必須放最後 ⭐
    Route::get('/{id}', [OptionController::class, 'show']);
});

// ==========================================
// Black-Scholes API (選擇權定價模型)
// ==========================================
Route::prefix('black-scholes')->group(function () {
    Route::post('/calculate', [BlackScholesController::class, 'calculate']);
    Route::post('/batch', [BlackScholesController::class, 'batchCalculate']);
    Route::post('/implied-volatility', [BlackScholesController::class, 'impliedVolatility']);
    Route::post('/time-decay', [BlackScholesController::class, 'timeDecay']);
    Route::post('/payoff', [BlackScholesController::class, 'payoff']);
    Route::post('/batch-prices', [BlackScholesController::class, 'batchPrices']);
    Route::get('/volatility-smile', [BlackScholesController::class, 'volatilitySmile']);
    Route::post('/batch-greeks', [BlackScholesController::class, 'batchGreeks']);
});

// ==========================================
// Volatility API (波動率)
// ==========================================
Route::prefix('volatility')->group(function () {
    Route::get('/historical/{stock_id}', [VolatilityController::class, 'historical']);
    Route::get('/implied/{option_id}', [VolatilityController::class, 'implied']);
    Route::get('/market-iv/{stockId}', [VolatilityController::class, 'marketIV']);
    Route::get('/cone/{stock_id}', [VolatilityController::class, 'cone']);
    Route::get('/surface/{stock_id}', [VolatilityController::class, 'surface']);
    Route::get('/skew/{stock_id}', [VolatilityController::class, 'skew']);
    Route::get('/garch/{stock_id}', [VolatilityController::class, 'garch']);
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
// 測試路由 (開發用 - 正式環境可移除)
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
// Admin API (後台管理) - 需要登入認證
// ==========================================
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    // 系統總覽
    Route::get('/overview', [AdminController::class, 'overview']);

    // 系統日誌
    Route::get('/logs', [AdminController::class, 'logs']);

    // Queue Job 管理
    Route::prefix('jobs')->group(function () {
        Route::get('/queue', [AdminController::class, 'queueJobs']);
        Route::post('/trigger-stock-crawler', [AdminController::class, 'triggerStockCrawler']);
        Route::post('/trigger-option-crawler', [AdminController::class, 'triggerOptionCrawler']);
        Route::post('/retry/{id}', [AdminController::class, 'retryFailedJob']);
    });

    // 快取管理
    Route::post('/cache/clear', [AdminController::class, 'clearCache']);

    // 資料庫維護
    Route::post('/database/optimize', [AdminController::class, 'optimizeDatabase']);
});
