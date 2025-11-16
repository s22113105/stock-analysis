<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

// 修正 Controller namespace - 實際位置不在 Api 子目錄
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

/*
|--------------------------------------------------------------------------
| API Routes - 公開訪問（移除認證要求）
|--------------------------------------------------------------------------
| 修改原因：產學合作展示系統，不需要登入認證
| 修改日期：2024-11-16
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
    Route::post('/search', [StockController::class, 'search']);
    Route::post('/import', [StockController::class, 'import']);
});

// Options
Route::prefix('options')->group(function () {
    Route::get('/', [OptionController::class, 'index']);
    Route::get('/{id}', [OptionController::class, 'show']);
    Route::get('/chain/{stockId}', [OptionController::class, 'chain']);
    Route::get('/{id}/prices', [OptionController::class, 'prices']);
    Route::get('/expiring', [OptionController::class, 'expiring']);
    Route::post('/filter', [OptionController::class, 'filter']);
});

// Black-Scholes
Route::prefix('black-scholes')->group(function () {
    Route::post('/calculate', [BlackScholesController::class, 'calculate']);
    Route::post('/implied-volatility', [BlackScholesController::class, 'impliedVolatility']);
    Route::post('/option-chain', [BlackScholesController::class, 'optionChain']);
    Route::post('/volatility-smile', [BlackScholesController::class, 'volatilitySmile']);
    Route::post('/batch-greeks', [BlackScholesController::class, 'batchGreeks']);
});

// Volatility
Route::prefix('volatility')->group(function () {
    Route::get('/historical/{stockId}', [VolatilityController::class, 'historical']);
    Route::get('/implied/{optionId}', [VolatilityController::class, 'implied']);
    Route::get('/surface/{stockId}', [VolatilityController::class, 'surface']);
    Route::get('/cone/{stockId}', [VolatilityController::class, 'cone']);
    Route::get('/skew/{stockId}', [VolatilityController::class, 'skew']);
    Route::post('/calculate', [VolatilityController::class, 'calculate']);
    Route::get('/garch/{stockId}', [VolatilityController::class, 'garch']);
});

// 預測相關路由
Route::prefix('predictions')->group(function () {
    Route::get('/', [PredictionController::class, 'index']);
    Route::post('/run', [PredictionController::class, 'run']);
    Route::get('/{id}', [PredictionController::class, 'show']);
    Route::delete('/{id}', [PredictionController::class, 'destroy']);

    // 特定模型路由
    Route::post('/lstm', [PredictionController::class, 'lstm']);
    Route::post('/arima', [PredictionController::class, 'arima']);
    Route::post('/garch', [PredictionController::class, 'garch']);
    Route::post('/monte-carlo', [PredictionController::class, 'monteCarlo']);
    Route::post('/compare', [PredictionController::class, 'compare']);
});

// Backtest
Route::prefix('backtest')->group(function () {
    Route::get('/', [BacktestController::class, 'index']);
    Route::post('/run', [BacktestController::class, 'run']);
    Route::get('/{id}', [BacktestController::class, 'show']);
    Route::delete('/{id}', [BacktestController::class, 'destroy']);
    Route::get('/strategies', [BacktestController::class, 'strategies']);
    Route::post('/compare', [BacktestController::class, 'compare']);
});

// Real-time data
Route::prefix('realtime')->group(function () {
    Route::get('/quotes', [RealtimeController::class, 'quotes']);
    Route::get('/depth/{symbol}', [RealtimeController::class, 'depth']);
    Route::get('/trades/{symbol}', [RealtimeController::class, 'trades']);
    Route::post('/subscribe', [RealtimeController::class, 'subscribe']);
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
    Route::post('/order', [TradingController::class, 'placeOrder']);
    Route::put('/order/{id}', [TradingController::class, 'updateOrder']);
    Route::delete('/order/{id}', [TradingController::class, 'cancelOrder']);
    Route::post('/close/{positionId}', [TradingController::class, 'closePosition']);
});

// Public routes
Route::prefix('public')->group(function () {
    // Market data
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

    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',
                'redis' => Redis::connection()->ping() ? 'connected' : 'disconnected',
            ]
        ]);
    });
});

// 保留用戶認證路由（如果未來需要）
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
