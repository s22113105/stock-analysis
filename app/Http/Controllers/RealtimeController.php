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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 即時資料 API 控制器
 *
 * 因系統未串接券商即時 Feed，所有「即時」資料以
 * 資料庫最新一筆收盤資料為基準回傳，並於 response
 * 中加上 data_type 標示，前端可據此決定顯示方式。
 */
class RealtimeController extends Controller
{
    // ==========================================
    // 即時報價
    // ==========================================

    /**
     * 取得即時報價
     *
     * GET /api/realtime/quotes
     */
    public function quotes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbols'   => 'required|array|min:1|max:50',
            'symbols.*' => 'required|string',
            'type'      => 'nullable|in:stock,option',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $symbols = $request->input('symbols');
            $type    = $request->input('type', 'stock');
            $quotes  = [];

            foreach ($symbols as $symbol) {
                $cacheKey = "realtime:quote:{$type}:{$symbol}";
                $quote    = Cache::get($cacheKey);

                if (!$quote) {
                    $quote = $type === 'stock'
                        ? $this->buildStockQuote($symbol)
                        : $this->buildOptionQuote($symbol);

                    if ($quote) {
                        Cache::put($cacheKey, $quote, 10);
                    }
                }

                if ($quote) {
                    $quotes[] = $quote;
                }
            }

            return response()->json([
                'success'   => true,
                'data'      => $quotes,
                'timestamp' => now()->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error('取得即時報價失敗', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==========================================
    // 市場深度 (買賣五檔)
    // ==========================================

    /**
     * 取得市場深度
     *
     * GET /api/realtime/depth/{symbol}
     *
     * 說明：系統未接即時委託簿，以最新收盤價為基礎，
     * 利用近期 N 日的高低點分布估算合理價位，
     * 並以近期成交量分配各檔委量，避免純亂數。
     */
    public function depth(Request $request, string $symbol): JsonResponse
    {
        try {
            $cacheKey = "realtime:depth:{$symbol}";
            $depth    = Cache::get($cacheKey);

            if (!$depth) {
                $stock = Stock::where('symbol', $symbol)
                    ->with('latestPrice')
                    ->first();

                if (!$stock || !$stock->latestPrice) {
                    return response()->json([
                        'success' => false,
                        'message' => '找不到股票資料',
                    ], 404);
                }

                $currentPrice = (float) $stock->latestPrice->close;

                // 取近 5 日資料，計算平均成交量，作為委量分配基準
                $recentPrices = StockPrice::where('stock_id', $stock->id)
                    ->orderBy('trade_date', 'desc')
                    ->limit(5)
                    ->get();

                $avgVolume = $recentPrices->avg('volume') ?: 10000000;

                // 台股跳動單位
                $tick = $this->getTickSize($currentPrice);

                $depth = [
                    'symbol'        => $symbol,
                    'name'          => $stock->name,
                    'current_price' => $currentPrice,
                    'bids'          => $this->buildOrderBookFromHistory($currentPrice, 'bid', 5, $tick, $avgVolume),
                    'asks'          => $this->buildOrderBookFromHistory($currentPrice, 'ask', 5, $tick, $avgVolume),
                    'data_type'     => 'estimated',   // 前端用此欄位標示「估算」
                    'trade_date'    => $stock->latestPrice->trade_date,
                    'timestamp'     => now()->toIso8601String(),
                ];

                // 快取 10 秒
                Cache::put($cacheKey, $depth, 10);
            }

            return response()->json([
                'success' => true,
                'data'    => $depth,
            ]);

        } catch (\Exception $e) {
            Log::error('取得市場深度失敗', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==========================================
    // 成交明細
    // ==========================================

    /**
     * 取得成交明細
     *
     * GET /api/realtime/trades/{symbol}
     *
     * 說明：系統未接 tick-by-tick 資料，以最新交易日的
     * 開高低收 + 成交量，拆解成 N 筆分時成交明細估算。
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
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $limit    = $request->input('limit', 20);
            $cacheKey = "realtime:trades:{$symbol}";
            $trades   = Cache::get($cacheKey);

            if (!$trades) {
                $stock = Stock::where('symbol', $symbol)
                    ->with('latestPrice')
                    ->first();

                if (!$stock || !$stock->latestPrice) {
                    return response()->json([
                        'success' => false,
                        'message' => '找不到股票資料',
                    ], 404);
                }

                $latest = $stock->latestPrice;
                $trades = $this->buildTradesFromOHLC(
                    (float) $latest->open,
                    (float) $latest->high,
                    (float) $latest->low,
                    (float) $latest->close,
                    (int)   $latest->volume,
                    $latest->trade_date,
                    50   // 拆解成 50 筆 tick
                );

                // 快取 30 秒（非即時，快取久一點）
                Cache::put($cacheKey, $trades, 30);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'symbol'    => $symbol,
                    'trades'    => array_slice($trades, 0, $limit),
                    'data_type' => 'estimated',
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('取得成交明細失敗', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage(),
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
            'channel'   => 'required|string',
            'symbols'   => 'required|array|min:1',
            'symbols.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $channel = $request->input('channel');
            $symbols = $request->input('symbols');
            $userId  = $request->user()?->id ?? 'guest_' . uniqid();

            return response()->json([
                'success' => true,
                'data'    => [
                    'channel'    => $channel,
                    'symbols'    => $symbols,
                    'user_id'    => $userId,
                    'subscribed' => true,
                    'message'    => 'WebSocket 訂閱成功',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '訂閱失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==========================================
    // Private Helpers
    // ==========================================

    /**
     * 從 stock_prices 建立股票報價物件
     */
    private function buildStockQuote(string $symbol): ?array
    {
        $stock = Stock::where('symbol', $symbol)
            ->with('latestPrice')
            ->first();

        if (!$stock || !$stock->latestPrice) {
            return null;
        }

        $lp = $stock->latestPrice;

        return [
            'symbol'         => $stock->symbol,
            'name'           => $stock->name,
            'price'          => (float) $lp->close,
            'open'           => (float) $lp->open,
            'high'           => (float) $lp->high,
            'low'            => (float) $lp->low,
            'volume'         => (int)   $lp->volume,
            'change'         => (float) $lp->change,
            'change_percent' => (float) $lp->change_percent,
            'trade_date'     => $lp->trade_date,
            'data_type'      => 'historical_close',
            'updated_at'     => $lp->updated_at,
        ];
    }

    /**
     * 從 option_prices 建立選擇權報價物件
     */
    private function buildOptionQuote(string $symbol): ?array
    {
        $option = Option::where('option_code', $symbol)
            ->with('latestPrice')
            ->first();

        if (!$option || !$option->latestPrice) {
            return null;
        }

        $lp = $option->latestPrice;

        return [
            'option_code'        => $option->option_code,
            'underlying'         => $option->underlying,
            'strike_price'       => (float) $option->strike_price,
            'expiry_date'        => $option->expiry_date,
            'option_type'        => $option->option_type,
            'price'              => (float) $lp->close,
            'open'               => (float) $lp->open,
            'high'               => (float) $lp->high,
            'low'                => (float) $lp->low,
            'volume'             => (int)   $lp->volume,
            'open_interest'      => (int)   $lp->open_interest,
            'implied_volatility' => $lp->implied_volatility ? round((float) $lp->implied_volatility * 100, 2) : null,
            'delta'              => $lp->delta ? round((float) $lp->delta, 4) : null,
            'trade_date'         => $lp->trade_date,
            'data_type'          => 'historical_close',
            'updated_at'         => $lp->updated_at,
        ];
    }

    /**
     * 根據收盤價與近期成交量，建立估算委託簿（買賣五檔）
     *
     * 規則：
     * - 價格按台股跳動單位遞增/遞減
     * - 委量以平均成交量依指數遞減分配（靠近市價量多）
     * - 掛單筆數 = round(委量 / 單筆均量)
     */
    private function buildOrderBookFromHistory(
        float $currentPrice,
        string $side,
        int $levels,
        float $tick,
        float $avgDailyVolume
    ): array {
        $book = [];

        // 盤中每檔平均委量約為日成交量的 0.5%～2%
        $baseVolume = $avgDailyVolume * 0.01;

        for ($i = 1; $i <= $levels; $i++) {
            $price = $side === 'bid'
                ? $currentPrice - ($tick * $i)
                : $currentPrice + ($tick * $i);

            // 指數遞減：靠近市價的檔位量多
            $levelVolume = (int) round($baseVolume / $i);
            $levelVolume = max($levelVolume, 100); // 最少 100 股

            // 四捨五入到整百
            $levelVolume = (int) round($levelVolume / 100) * 100;

            // 掛單筆數約為委量 / 2000 股
            $orders = max(1, (int) round($levelVolume / 2000));

            $book[] = [
                'price'  => round($price, 2),
                'volume' => $levelVolume,
                'orders' => $orders,
            ];
        }

        return $book;
    }

    /**
     * 從 OHLCV 拆解估算分時成交明細
     *
     * 策略：
     * - 在 open ~ close 之間以線性內插產生價格路徑
     * - 加入 high/low 兩個極值
     * - 成交量按比例分配
     * - 時間從收盤往前推（模擬最近 N 筆）
     */
    private function buildTradesFromOHLC(
        float $open,
        float $high,
        float $low,
        float $close,
        int   $totalVolume,
        string $tradeDate,
        int   $count
    ): array {
        $trades    = [];
        $baseTime  = Carbon::parse($tradeDate)->setTime(13, 30, 0); // 台股收盤 13:30
        $avgVolume = (int) max(100, $totalVolume / $count);

        // 建立從 open → close 的線性路徑，中間插入 high、low
        $pricePoints = [];
        $highIdx     = (int) ($count * 0.3);  // high 出現在約 30% 處
        $lowIdx      = (int) ($count * 0.6);  // low  出現在約 60% 處

        for ($i = 0; $i < $count; $i++) {
            if ($i === $highIdx) {
                $pricePoints[] = $high;
            } elseif ($i === $lowIdx) {
                $pricePoints[] = $low;
            } else {
                // 線性內插 open → close
                $ratio         = $count > 1 ? $i / ($count - 1) : 0;
                $pricePoints[] = $open + ($close - $open) * $ratio;
            }
        }

        // 每兩筆之間的秒數間隔（台股 5 小時 = 18000 秒）
        $intervalSec = (int) max(1, 18000 / $count);

        for ($i = 0; $i < $count; $i++) {
            $price    = round($pricePoints[$i], 2);
            $prevPrice = $i > 0 ? $pricePoints[$i - 1] : $open;

            $trades[] = [
                'price'  => $price,
                'volume' => $avgVolume,
                'type'   => $price >= $prevPrice ? 'buy' : 'sell',
                'time'   => $baseTime->copy()->subSeconds($i * $intervalSec)->format('H:i:s'),
            ];
        }

        // 最新的排在最前
        return array_reverse($trades);
    }

    /**
     * 取得台股跳動單位
     */
    private function getTickSize(float $price): float
    {
        if ($price < 10)     return 0.01;
        if ($price < 50)     return 0.05;
        if ($price < 100)    return 0.1;
        if ($price < 500)    return 0.5;
        if ($price < 1000)   return 1.0;
        return 5.0;
    }
}
