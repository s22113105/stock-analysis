<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Option;
use App\Models\StockPrice;
use App\Models\OptionPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 即時資料 API 控制器
 *
 * 處理即時報價、市場深度、成交明細等功能
 */
class RealtimeController extends Controller
{
    /**
     * 取得即時報價
     *
     * GET /api/realtime/quotes
     */
    public function quotes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbols' => 'required|array|min:1|max:50',
            'symbols.*' => 'required|string',
            'type' => 'nullable|in:stock,option',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $symbols = $request->input('symbols');
            $type = $request->input('type', 'stock');
            $quotes = [];

            foreach ($symbols as $symbol) {
                // 嘗試從 Redis 快取中取得即時報價
                $cacheKey = "realtime:quote:{$type}:{$symbol}";
                $quote = Cache::get($cacheKey);

                if (!$quote) {
                    // 如果快取中沒有，從資料庫取得最新資料
                    if ($type === 'stock') {
                        $stock = Stock::where('symbol', $symbol)
                            ->with('latestPrice')
                            ->first();

                        if ($stock && $stock->latestPrice) {
                            $quote = [
                                'symbol' => $stock->symbol,
                                'name' => $stock->name,
                                'price' => $stock->latestPrice->close,
                                'open' => $stock->latestPrice->open,
                                'high' => $stock->latestPrice->high,
                                'low' => $stock->latestPrice->low,
                                'volume' => $stock->latestPrice->volume,
                                'change' => $stock->latestPrice->change,
                                'change_percent' => $stock->latestPrice->change_percent,
                                'trade_date' => $stock->latestPrice->trade_date,
                                'updated_at' => $stock->latestPrice->updated_at,
                            ];
                        }
                    } else {
                        $option = Option::where('option_code', $symbol)
                            ->with('latestPrice')
                            ->first();

                        if ($option && $option->latestPrice) {
                            $quote = [
                                'option_code' => $option->option_code,
                                'underlying' => $option->underlying,
                                'strike_price' => $option->strike_price,
                                'expiry_date' => $option->expiry_date,
                                'option_type' => $option->option_type,
                                'price' => $option->latestPrice->close,
                                'open' => $option->latestPrice->open,
                                'high' => $option->latestPrice->high,
                                'low' => $option->latestPrice->low,
                                'volume' => $option->latestPrice->volume,
                                'open_interest' => $option->latestPrice->open_interest,
                                'implied_volatility' => $option->latestPrice->implied_volatility,
                                'trade_date' => $option->latestPrice->trade_date,
                                'updated_at' => $option->latestPrice->updated_at,
                            ];
                        }
                    }

                    // 快取 10 秒
                    if ($quote) {
                        Cache::put($cacheKey, $quote, 10);
                    }
                }

                if ($quote) {
                    $quotes[] = $quote;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $quotes,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('取得即時報價失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得市場深度 (買賣五檔)
     *
     * GET /api/realtime/depth/{symbol}
     */
    public function depth(Request $request, string $symbol): JsonResponse
    {
        try {
            // 從 Redis 或快取取得市場深度資料
            $cacheKey = "realtime:depth:{$symbol}";
            $depth = Cache::get($cacheKey);

            if (!$depth) {
                // 模擬市場深度資料 (實際應該從即時資料源取得)
                $stock = Stock::where('symbol', $symbol)->first();

                if (!$stock || !$stock->latestPrice) {
                    return response()->json([
                        'success' => false,
                        'message' => '找不到股票資料'
                    ], 404);
                }

                $currentPrice = $stock->latestPrice->close;

                // 生成模擬的買賣五檔
                $depth = [
                    'symbol' => $symbol,
                    'name' => $stock->name,
                    'current_price' => $currentPrice,
                    'bids' => $this->generateMockOrderBook($currentPrice, 'bid', 5),
                    'asks' => $this->generateMockOrderBook($currentPrice, 'ask', 5),
                    'timestamp' => now()->toIso8601String(),
                ];

                // 快取 3 秒
                Cache::put($cacheKey, $depth, 3);
            }

            return response()->json([
                'success' => true,
                'data' => $depth
            ]);
        } catch (\Exception $e) {
            Log::error('取得市場深度失敗', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得成交明細
     *
     * GET /api/realtime/trades/{symbol}
     */
    public function trades(Request $request, string $symbol): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $limit = $request->input('limit', 20);

            // 從 Redis 取得成交明細
            $cacheKey = "realtime:trades:{$symbol}";
            $trades = Cache::get($cacheKey);

            if (!$trades) {
                // 模擬成交明細 (實際應該從即時資料源取得)
                $stock = Stock::where('symbol', $symbol)->first();

                if (!$stock || !$stock->latestPrice) {
                    return response()->json([
                        'success' => false,
                        'message' => '找不到股票資料'
                    ], 404);
                }

                $currentPrice = $stock->latestPrice->close;
                $trades = $this->generateMockTrades($currentPrice, $limit);

                // 快取 5 秒
                Cache::put($cacheKey, $trades, 5);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'symbol' => $symbol,
                    'trades' => array_slice($trades, 0, $limit),
                    'timestamp' => now()->toIso8601String(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得成交明細失敗', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 訂閱即時資料
     *
     * POST /api/realtime/subscribe
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'channel' => 'required|string',
            'symbols' => 'required|array|min:1',
            'symbols.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $channel = $request->input('channel');
            $symbols = $request->input('symbols');
            $userId = $request->user()?->id ?? 'guest_' . uniqid();

            // 將訂閱資訊存入 Redis
            foreach ($symbols as $symbol) {
                $subscriptionKey = "subscription:{$userId}:{$channel}:{$symbol}";
                Redis::setex($subscriptionKey, 3600, json_encode([
                    'user_id' => $userId,
                    'channel' => $channel,
                    'symbol' => $symbol,
                    'subscribed_at' => now()->toIso8601String(),
                ]));
            }

            return response()->json([
                'success' => true,
                'message' => '訂閱成功',
                'data' => [
                    'channel' => $channel,
                    'symbols' => $symbols,
                    'user_id' => $userId,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('訂閱失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '訂閱失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取消訂閱
     *
     * POST /api/realtime/unsubscribe
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'channel' => 'required|string',
            'symbols' => 'required|array|min:1',
            'symbols.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $channel = $request->input('channel');
            $symbols = $request->input('symbols');
            $userId = $request->user()?->id ?? 'guest_' . uniqid();

            // 從 Redis 移除訂閱資訊
            foreach ($symbols as $symbol) {
                $subscriptionKey = "subscription:{$userId}:{$channel}:{$symbol}";
                Redis::del($subscriptionKey);
            }

            return response()->json([
                'success' => true,
                'message' => '取消訂閱成功',
                'data' => [
                    'channel' => $channel,
                    'symbols' => $symbols,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取消訂閱失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取消訂閱失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 生成模擬委託簿 (買賣檔位)
     */
    private function generateMockOrderBook(float $currentPrice, string $type, int $levels): array
    {
        $orderBook = [];
        $priceStep = $currentPrice * 0.001; // 0.1% 的價格間距

        for ($i = 1; $i <= $levels; $i++) {
            if ($type === 'bid') {
                $price = $currentPrice - ($priceStep * $i);
            } else {
                $price = $currentPrice + ($priceStep * $i);
            }

            $orderBook[] = [
                'price' => round($price, 2),
                'volume' => rand(100, 10000) * 100,
                'orders' => rand(1, 50),
            ];
        }

        return $orderBook;
    }

    /**
     * 生成模擬成交明細
     */
    private function generateMockTrades(float $currentPrice, int $count): array
    {
        $trades = [];
        $time = now();

        for ($i = 0; $i < $count; $i++) {
            $priceVariation = $currentPrice * 0.002; // ±0.2% 變動
            $price = $currentPrice + (rand(-100, 100) / 100 * $priceVariation);

            $trades[] = [
                'price' => round($price, 2),
                'volume' => rand(1, 100) * 100,
                'type' => rand(0, 1) ? 'buy' : 'sell',
                'time' => $time->copy()->subSeconds($i * 3)->format('H:i:s'),
            ];
        }

        return $trades;
    }
}
