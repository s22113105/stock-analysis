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

// 新增的 Controller (優先級 HIGH 功能)
use App\Http\Controllers\BroadcastingController;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| API Routes - 公開訪問（移除認證要求）
|--------------------------------------------------------------------------
| 修改原因：產學合作展示系統，不需要登入認證
| 修改日期：2024-11-16
| 最後更新：2025-11-16 (新增 Broadcasting 和 Admin 路由)
|--------------------------------------------------------------------------
*/

// Dashboard
Route::prefix('dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/portfolio', [DashboardController::class, 'portfolio']);
    Route::get('/performance', [DashboardController::class, 'performance']);
    Route::get('/alerts', [DashboardController::class, 'alerts']);
});

// Stocks
Route::prefix('stocks')->group(function () {
    Route::get('/', [StockController::class, 'index']);
    Route::get('/{id}', [StockController::class, 'show']);
    Route::get('/{id}/prices', [StockController::class, 'prices']);
    Route::get('/{id}/chart', [StockController::class, 'chart']);
    Route::get('/{id}/indicators', [StockController::class, 'indicators']);
});

// Options
Route::prefix('options')->group(function () {
    Route::get('/', [OptionController::class, 'index']);
    Route::get('/{id}', [OptionController::class, 'show']);
    Route::get('/{id}/prices', [OptionController::class, 'prices']);
    Route::get('/chain/{underlying}', [OptionController::class, 'chain']);
    Route::get('/analysis/{id}', [OptionController::class, 'analysis']);
});

// Black-Scholes
Route::prefix('black-scholes')->group(function () {
    Route::post('/calculate', [BlackScholesController::class, 'calculate']);
    Route::post('/greeks', [BlackScholesController::class, 'greeks']);
    Route::post('/implied-volatility', [BlackScholesController::class, 'impliedVolatility']);
    Route::post('/batch', [BlackScholesController::class, 'batch']);
});

// Volatility
Route::prefix('volatility')->group(function () {
    Route::get('/historical/{stock_id}', [VolatilityController::class, 'historical']);
    Route::get('/implied/{option_id}', [VolatilityController::class, 'implied']);
    Route::get('/surface/{underlying}', [VolatilityController::class, 'surface']);
    Route::get('/cone/{stock_id}', [VolatilityController::class, 'cone']);
    Route::post('/garch', [VolatilityController::class, 'garch']);
});

// Predictions
Route::prefix('predictions')->group(function () {
    Route::get('/', [PredictionController::class, 'index']);
    Route::get('/{id}', [PredictionController::class, 'show']);
    Route::post('/run', [PredictionController::class, 'run']);
    Route::post('/compare', [PredictionController::class, 'compare']);
    Route::delete('/{id}', [PredictionController::class, 'destroy']);
});

// Backtest
Route::prefix('backtest')->group(function () {
    Route::get('/', [BacktestController::class, 'index']);
    Route::get('/{id}', [BacktestController::class, 'show']);
    Route::post('/run', [BacktestController::class, 'run']);
    Route::get('/{id}/equity-curve', [BacktestController::class, 'equityCurve']);
    Route::get('/{id}/trades', [BacktestController::class, 'trades']);
    Route::delete('/{id}', [BacktestController::class, 'destroy']);
});

// Realtime
Route::prefix('realtime')->group(function () {
    Route::get('/prices', [RealtimeController::class, 'prices']);
    Route::get('/subscribe', [RealtimeController::class, 'subscribe']);
    Route::post('/unsubscribe', [RealtimeController::class, 'unsubscribe']);
});

// Reports
Route::prefix('reports')->group(function () {
    Route::get('/daily', [ReportController::class, 'daily']);
    Route::get('/monthly', [ReportController::class, 'monthly']);
    Route::get('/performance', [ReportController::class, 'performance']);
    Route::get('/risk', [ReportController::class, 'risk']);
    Route::post('/generate', [ReportController::class, 'generate']);
    Route::get('/export/{type}', [ReportController::class, 'export']);
});

// Trading
Route::prefix('trading')->group(function () {
    Route::get('/positions', [TradingController::class, 'positions']);
    Route::get('/orders', [TradingController::class, 'orders']);
    Route::post('/orders', [TradingController::class, 'createOrder']);
    Route::get('/history', [TradingController::class, 'history']);
});

/*
|--------------------------------------------------------------------------
| Broadcasting Routes (WebSocket 即時推播) - 新增於 2025-11-16
|--------------------------------------------------------------------------
*/
Route::prefix('broadcasting')->group(function () {
    Route::get('/test', [BroadcastingController::class, 'test']);
    Route::get('/status', [BroadcastingController::class, 'status']);
    Route::post('/stock-price', [BroadcastingController::class, 'broadcastStockPrice']);
    Route::post('/option-price', [BroadcastingController::class, 'broadcastOptionPrice']);
    Route::post('/alert', [BroadcastingController::class, 'broadcastAlert']);
    Route::post('/batch-stocks', [BroadcastingController::class, 'batchBroadcastStocks']);
});

/*
|--------------------------------------------------------------------------
| Admin Routes (後台管理) - 新增於 2025-11-16
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Public Routes (公開路由)
|--------------------------------------------------------------------------
*/
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
| 認證路由（保留供未來使用）
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});