<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
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

/*
|--------------------------------------------------------------------------
| 認證路由 (公開)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'changePassword']);
    });
});

/*
|--------------------------------------------------------------------------
| 公開路由
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

Route::prefix('public')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String()
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/
Route::prefix('dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/portfolio', [DashboardController::class, 'portfolio']);
    Route::get('/performance', [DashboardController::class, 'performance']);
    Route::get('/alerts', [DashboardController::class, 'alerts']);
});

/*
|--------------------------------------------------------------------------
| Stocks
|--------------------------------------------------------------------------
*/
Route::prefix('stocks')->group(function () {
    Route::get('/', [StockController::class, 'index']);
    Route::get('/{id}', [StockController::class, 'show']);
    Route::get('/{id}/prices', [StockController::class, 'prices']);
    Route::get('/{id}/chart', [StockController::class, 'chart']);
    Route::get('/{id}/indicators', [StockController::class, 'indicators']);
});

/*
|--------------------------------------------------------------------------
| Options
|--------------------------------------------------------------------------
*/
Route::prefix('options')->group(function () {
    Route::get('/', [OptionController::class, 'index']);
    Route::get('/{id}', [OptionController::class, 'show']);
    Route::get('/{id}/greeks', [OptionController::class, 'greeks']);
    Route::get('/chain/{stockId}', [OptionController::class, 'chain']);
});

/*
|--------------------------------------------------------------------------
| Black-Scholes
|--------------------------------------------------------------------------
*/
Route::prefix('black-scholes')->group(function () {
    Route::post('/calculate', [BlackScholesController::class, 'calculate']);
    Route::post('/greeks', [BlackScholesController::class, 'greeks']);
    Route::post('/implied-volatility', [BlackScholesController::class, 'impliedVolatility']);
});

/*
|--------------------------------------------------------------------------
| Volatility
|--------------------------------------------------------------------------
*/
Route::prefix('volatility')->group(function () {
    Route::get('/historical/{id}', [VolatilityController::class, 'historical']);
    Route::get('/implied/{id}', [VolatilityController::class, 'implied']);
    Route::post('/garch', [VolatilityController::class, 'garch']);
    Route::get('/surface/{stockId}', [VolatilityController::class, 'surface']);
});

/*
|--------------------------------------------------------------------------
| 需認證的路由
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Predictions
    Route::prefix('predictions')->group(function () {
        Route::get('/', [PredictionController::class, 'index']);
        Route::post('/run', [PredictionController::class, 'run']);
        Route::get('/{id}', [PredictionController::class, 'show']);
        Route::delete('/{id}', [PredictionController::class, 'destroy']);
    });

    // Backtest
    Route::prefix('backtest')->group(function () {
        Route::post('/run', [BacktestController::class, 'run']);
        Route::get('/results', [BacktestController::class, 'results']);
        Route::get('/results/{id}', [BacktestController::class, 'show']);
        Route::get('/results/{id}/trades', [BacktestController::class, 'trades']);
        Route::delete('/{id}', [BacktestController::class, 'destroy']);
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
        Route::delete('/orders/{id}', [TradingController::class, 'cancelOrder']);
        Route::get('/history', [TradingController::class, 'history']);
        Route::get('/account', [TradingController::class, 'account']);
    });

    // Realtime
    Route::prefix('realtime')->group(function () {
        Route::get('/prices', [RealtimeController::class, 'prices']);
        Route::get('/subscribe', [RealtimeController::class, 'subscribe']);
        Route::post('/unsubscribe', [RealtimeController::class, 'unsubscribe']);
    });

    // Broadcasting
    Route::prefix('broadcasting')->group(function () {
        Route::get('/test', [BroadcastingController::class, 'test']);
        Route::get('/status', [BroadcastingController::class, 'status']);
        Route::post('/stock-price', [BroadcastingController::class, 'broadcastStockPrice']);
        Route::post('/option-price', [BroadcastingController::class, 'broadcastOptionPrice']);
        Route::post('/alert', [BroadcastingController::class, 'broadcastAlert']);
        Route::post('/batch-stocks', [BroadcastingController::class, 'batchBroadcastStocks']);
    });

    // Admin
    Route::prefix('admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);

        Route::prefix('jobs')->group(function () {
            Route::get('/queue', [AdminController::class, 'queueJobs']);
            Route::post('/trigger-stock-crawler', [AdminController::class, 'triggerStockCrawler']);
            Route::post('/trigger-option-crawler', [AdminController::class, 'triggerOptionCrawler']);
            Route::post('/retry/{id}', [AdminController::class, 'retryFailedJob']);
        });

        Route::prefix('cache')->group(function () {
            Route::post('/clear', [AdminController::class, 'clearCache']);
        });

        Route::get('/logs', [AdminController::class, 'logs']);

        Route::prefix('database')->group(function () {
            Route::post('/optimize', [AdminController::class, 'optimizeDatabase']);
        });
    });
});
