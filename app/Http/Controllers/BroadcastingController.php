<?php

namespace App\Http\Controllers;

use App\Events\StockPriceUpdated;
use App\Events\OptionPriceUpdated;
use App\Events\MarketAlert;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * Broadcasting 控制器
 * 
 * 處理 WebSocket 即時推播相關功能
 */
class BroadcastingController extends Controller
{
    /**
     * 測試 Broadcasting - 發送測試訊息
     * 
     * GET /api/broadcasting/test
     */
    public function test(): JsonResponse
    {
        try {
            // 發送測試警報
            broadcast(new MarketAlert(
                'system',
                '系統測試',
                'WebSocket 連線測試成功！',
                'info',
                ['test' => true]
            ));

            return response()->json([
                'success' => true,
                'message' => '測試訊息已發送',
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            Log::error('Broadcasting 測試失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '測試失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 發送股票價格更新
     * 
     * POST /api/broadcasting/stock-price
     */
    public function broadcastStockPrice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|exists:stocks,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stockId = $request->input('stock_id');
            
            // 取得最新價格
            $latestPrice = StockPrice::where('stock_id', $stockId)
                ->latest('trade_date')
                ->latest('created_at')
                ->first();

            if (!$latestPrice) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到股票價格資料'
                ], 404);
            }

            // 發送 Broadcasting 事件
            broadcast(new StockPriceUpdated($latestPrice));

            return response()->json([
                'success' => true,
                'message' => '股票價格已推播',
                'data' => [
                    'stock_id' => $stockId,
                    'symbol' => $latestPrice->stock->symbol,
                    'close' => $latestPrice->close,
                    'trade_date' => $latestPrice->trade_date
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('股票價格推播失敗', [
                'stock_id' => $request->input('stock_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '推播失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 發送選擇權價格更新
     * 
     * POST /api/broadcasting/option-price
     */
    public function broadcastOptionPrice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'option_id' => 'required|exists:options,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $optionId = $request->input('option_id');
            
            // 取得最新價格
            $latestPrice = OptionPrice::where('option_id', $optionId)
                ->latest('trade_date')
                ->latest('created_at')
                ->first();

            if (!$latestPrice) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到選擇權價格資料'
                ], 404);
            }

            // 發送 Broadcasting 事件
            broadcast(new OptionPriceUpdated($latestPrice));

            return response()->json([
                'success' => true,
                'message' => '選擇權價格已推播',
                'data' => [
                    'option_id' => $optionId,
                    'option_code' => $latestPrice->option->option_code,
                    'close' => $latestPrice->close,
                    'trade_date' => $latestPrice->trade_date
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('選擇權價格推播失敗', [
                'option_id' => $request->input('option_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '推播失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 發送市場警報
     * 
     * POST /api/broadcasting/alert
     */
    public function broadcastAlert(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:volatility,volume,price,system',
            'title' => 'required|string|max:200',
            'message' => 'required|string|max:1000',
            'severity' => 'nullable|in:info,warning,error,critical',
            'data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $alertType = $request->input('type');
            $title = $request->input('title');
            $message = $request->input('message');
            $severity = $request->input('severity', 'info');
            $data = $request->input('data', []);

            // 發送警報
            broadcast(new MarketAlert($alertType, $title, $message, $severity, $data));

            return response()->json([
                'success' => true,
                'message' => '警報已發送',
                'data' => [
                    'type' => $alertType,
                    'title' => $title,
                    'severity' => $severity
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('市場警報推播失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '推播失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 批次推播所有活躍股票的最新價格
     * 
     * POST /api/broadcasting/batch-stocks
     */
    public function batchBroadcastStocks(): JsonResponse
    {
        try {
            $stocks = Stock::where('is_active', true)->get();
            $broadcastCount = 0;

            foreach ($stocks as $stock) {
                $latestPrice = $stock->latestPrice;
                
                if ($latestPrice) {
                    broadcast(new StockPriceUpdated($latestPrice));
                    $broadcastCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "已推播 {$broadcastCount} 支股票的價格",
                'count' => $broadcastCount
            ]);
        } catch (\Exception $e) {
            Log::error('批次股票價格推播失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '批次推播失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得 Broadcasting 狀態
     * 
     * GET /api/broadcasting/status
     */
    public function status(): JsonResponse
    {
        try {
            $broadcastDriver = config('broadcasting.default');
            $queueConnection = config('queue.default');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'broadcast_driver' => $broadcastDriver,
                    'queue_connection' => $queueConnection,
                    'redis_connected' => $this->checkRedisConnection(),
                    'timestamp' => now()->toIso8601String()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '狀態檢查失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 檢查 Redis 連線狀態
     */
    private function checkRedisConnection(): bool
    {
        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}