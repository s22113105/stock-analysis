<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

// 原有的 Controller
use App\Http\Controllers\BlackScholesController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\VolatilityController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TradingController;
use App\Http\Controllers\BroadcastingController;
use App\Http\Controllers\AdminController;

// 認證 Controller
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes - 認證系統已啟用
|--------------------------------------------------------------------------
| 修改原因: 實作後台管理登入功能 (產學計畫 A5 要求)
| 修改日期: 2025-11-16
| 公開路由: 認證相關、市場資訊、健康檢查、部分查詢功能
| 需認證路由: 後台管理、交易、個人資料、敏感操作
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| 認證路由 (公開訪問)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // 公開路由
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    
    // 需要認證的路由
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'changePassword']);
    });
});

/*
|--------------------------------------------------------------------------
| 公開路由 - 查詢功能
|--------------------------------------------------------------------------
*/

// Dashboard (公開 - 可用於展示)
Route::prefix('dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/portfolio', [DashboardController::class, 'portfolio']);
    Route::get('/performance', [DashboardController::class, 'performance']);
    Route::get('/alerts', [DashboardController::class, 'alerts']);
});

// Stocks (公開 - 基本資訊查詢)
Route::prefix('stocks')->group(function () {
    Route::get('/', [StockController::class, 'index']);
    Route::get('/{id}', [StockController::class, 'show']);
    Route::get('/{id}/prices', [StockController::class, 'prices']);
    Route::get('/{id}/chart', [StockController::class, 'chart']);
    Route::get('/{id}/indicators', [StockController::class, 'indicators']);
});

// Options (公開 - 基本資訊查詢)
Route::prefix('options')->group(function () {
    Route::get('/', [OptionController::class, 'index']);
    Route::get('/{id}', [OptionController::class, 'show']);
    Route::get('/{id}/greeks', [OptionController::class, 'greeks']);
    Route::get('/chain/{stockId}', [OptionController::class, 'chain']);
});

// Black-Scholes (公開 - 計算工具)
Route::prefix('black-scholes')->group(function () {
    Route::post('/calculate', [BlackScholesController::class, 'calculate']);
    Route::post('/greeks', [BlackScholesController::class, 'greeks']);
    Route::post('/implied-volatility', [BlackScholesController::class, 'impliedVolatility']);
});

// Volatility (公開 - 波動率查詢)
Route::prefix('volatility')->group(function () {
    Route::get('/historical/{id}', [VolatilityController::class, 'historical']);
    Route::get('/implied/{id}', [VolatilityController::class, 'implied']);
    Route::post('/garch', [VolatilityController::class, 'garch']);
    Route::get('/surface/{stockId}', [VolatilityController::class, 'surface']);
});

// Realtime (公開 - 即時資訊)
Route::prefix('realtime')->group(function () {
    Route::get('/prices', [RealtimeController::class, 'prices']);
    Route::get('/subscribe', [RealtimeController::class, 'subscribe']);
    Route::post('/unsubscribe', [RealtimeController::class, 'unsubscribe']);
});

// Public Routes
Route::prefix('public')->group(function () {
    // 市場狀態
    Route::get('/market-status', function () {
        $now = now('Asia/Taipei');
        $hour = $now->hour;
        $minute = $now->minute;
        $dayOfWeek = $now->dayOfWeek;

        $isWeekend = in_array($dayOfWeek, [0, 6]);
        $isTrading = !$isWeekend && (
            ($hour === 9 && $minute >= 0) ||
            ($hour >= 10 && $hour < 13) ||
            ($hour === 13 && $minute <= 30)
        );

        return response()->json([
            'is_open' => $isTrading,
            'current_time' => $now->toIso8601String(),
            'next_open' => $isWeekend ? $now->next(1)->setTime(9, 0) : null,
            'next_close' => $isTrading ? $now->setTime(13, 30) : null,
        ]);
    });

    // 健康檢查
    Route::get('/health', function () {
        try {
            $dbConnected = DB::connection()->getPdo() ? true : false;
        } catch (\Exception $e) {
            $dbConnected = false;
        }

        try {
            $redisConnected = Redis::connection()->ping() ? true : false;
        } catch (\Exception $e) {
            $redisConnected = false;
        }

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => $dbConnected ? 'connected' : 'disconnected',
                'redis' => $redisConnected ? 'connected' : 'disconnected',
            ]
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| 需認證路由 - 敏感操作與後台管理
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Predictions (需認證 - 執行預測)
    Route::prefix('predictions')->group(function () {
        Route::get('/', [PredictionController::class, 'index']);
        Route::post('/run', [PredictionController::class, 'run']);
        Route::get('/{id}', [PredictionController::class, 'show']);
        Route::delete('/{id}', [PredictionController::class, 'destroy']);
    });

    // Backtest (需認證 - 執行回測)
    Route::prefix('backtest')->group(function () {
        Route::post('/run', [BacktestController::class, 'run']);
        Route::get('/results', [BacktestController::class, 'results']);
        Route::get('/results/{id}', [BacktestController::class, 'show']);
        Route::get('/results/{id}/trades', [BacktestController::class, 'trades']);
        Route::delete('/{id}', [BacktestController::class, 'destroy']);
    });

    // Reports (需認證 - 報表生成)
    Route::prefix('reports')->group(function () {
        Route::get('/daily', [ReportController::class, 'daily']);
        Route::get('/monthly', [ReportController::class, 'monthly']);
        Route::get('/performance', [ReportController::class, 'performance']);
        Route::get('/risk', [ReportController::class, 'risk']);
        Route::post('/generate', [ReportController::class, 'generate']);
        Route::get('/export/{type}', [ReportController::class, 'export']);
    });

    // Trading (需認證 - 交易操作)
    Route::prefix('trading')->group(function () {
        Route::get('/positions', [TradingController::class, 'positions']);
        Route::get('/orders', [TradingController::class, 'orders']);
        Route::post('/orders', [TradingController::class, 'createOrder']);
        Route::delete('/orders/{id}', [TradingController::class, 'cancelOrder']);
        Route::get('/history', [TradingController::class, 'history']);
        Route::get('/account', [TradingController::class, 'account']);
    });

    // Broadcasting (需認證 - WebSocket 推播)
    Route::prefix('broadcasting')->group(function () {
        Route::get('/test', [BroadcastingController::class, 'test']);
        Route::get('/status', [BroadcastingController::class, 'status']);
        Route::post('/stock-price', [BroadcastingController::class, 'broadcastStockPrice']);
        Route::post('/option-price', [BroadcastingController::class, 'broadcastOptionPrice']);
        Route::post('/alert', [BroadcastingController::class, 'broadcastAlert']);
        Route::post('/batch-stocks', [BroadcastingController::class, 'batchBroadcastStocks']);
    });

    // Admin Routes (需認證 - 後台管理)
    Route::prefix('admin')->group(function () {
        // 系統總覽
        Route::get('/overview', [AdminController::class, 'overview']);
        
        // Job 管理
        Route::prefix('jobs')->group(function () {
            Route::get('/queue', [AdminController::class, 'queueJobs']);
            Route::post('/trigger-stock-crawler', [AdminController::class, 'triggerStockCrawler']);
            Route::post('/trigger-option-crawler', [AdminController::class, 'triggerOptionCrawler']);
            Route::post('/retry/{id}', [AdminController::class, 'retryFailedJob']);
        });
        
        // 快取管理
        Route::prefix('cache')->group(function () {
            Route::post('/clear', [AdminController::class, 'clearCache']);
        });
        
        // 系統日誌
        Route::get('/logs', [AdminController::class, 'logs']);
        
        // 資料庫維護
        Route::prefix('database')->group(function () {
            Route::post('/optimize', [AdminController::class, 'optimizeDatabase']);
        });
    });
});