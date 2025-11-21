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
use App\Http\Controllers\OptionAnalysisController;  // 新增
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
    // 基本 CRUD
    Route::get('/', [OptionController::class, 'index']);
    Route::get('/{id}', [OptionController::class, 'show']);
    Route::get('/chain/{underlying}', [OptionController::class, 'chain']);

    // 🌟 TXO 分析功能 (新增)
    Route::prefix('txo')->group(function () {
        // TXO 收盤價走勢圖
        Route::get('/trend', [OptionAnalysisController::class, 'getTxoTrend']);

        // 成交量分析 (Call vs Put)
        Route::get('/volume-analysis', [OptionAnalysisController::class, 'getVolumeAnalysis']);

        // 未平倉量分析 (OI Analysis)
        Route::get('/oi-analysis', [OptionAnalysisController::class, 'getOiAnalysis']);

        // 隱含波動率分析 (IV Analysis)
        Route::get('/iv-analysis', [OptionAnalysisController::class, 'getIvAnalysis']);

        // 市場情緒總覽
        Route::get('/sentiment', [OptionAnalysisController::class, 'getSentiment']);

        // OI 分佈 (依履約價)
        Route::get('/oi-distribution', [OptionAnalysisController::class, 'getOiDistribution']);
    });
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
