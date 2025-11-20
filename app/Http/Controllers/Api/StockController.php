<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\TwseApiService;
use App\Jobs\FetchStockDataJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 股票資料 API 控制器
 */
class StockController extends Controller
{
    protected $twseApiService;

    public function __construct(TwseApiService $twseApiService)
    {
        $this->twseApiService = $twseApiService;
    }

    /**
     * 取得股票列表 (已修正: 計算漲跌幅)
     * * GET /api/stocks
     */
    public function index(Request $request): JsonResponse
    {
        $query = Stock::query();

        // 產業別篩選
        if ($request->has('industry')) {
            $query->where('industry', $request->input('industry'));
        }

        // 搜尋
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // **修正邏輯: 根據 has_prices 參數篩選只取有價格的股票**
        if ($request->boolean('has_prices', false)) {
            $query->whereHas('prices');
        }

        // 排序
        $sortBy = $request->input('sort_by', 'symbol');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // 分頁
        $perPage = $request->input('per_page', 20);

        // 使用 with('latestPrice') 確保載入最新的價格數據
        $stocks = $query->with('latestPrice')->paginate($perPage);

        // **修正點：為每一筆最新的價格數據計算漲跌幅**
        // 必須在 with('latestPrice') 之後執行
        $stocks->getCollection()->each(function ($stock) {
            if ($stock->latestPrice) {
                // 由於 latestPrice 是一個物件，直接修改其屬性，不會寫入資料庫
                $this->calculateChangeAndPercent($stock);
            }
        });
        // **修正點結束**

        return response()->json([
            'success' => true,
            'data' => $stocks
        ]);
    }

    /**
     * 計算單一股票的漲跌和漲跌幅，並更新 latestPrice 物件。
     */
    protected function calculateChangeAndPercent(Stock $stock): void
    {
        $latestPrice = $stock->latestPrice;

        if (!$latestPrice || !$latestPrice->close) {
            return;
        }

        // 查找前一個交易日的收盤價
        // 注意：這裡假設 stock_prices 表中有 stock_id 欄位
        $previousPrice = StockPrice::where('stock_id', $stock->id)
            ->where('trade_date', '<', $latestPrice->trade_date) // 找比最新日期小的
            ->orderBy('trade_date', 'desc')
            ->value('close');

        if ($previousPrice === null || $previousPrice == 0) {
            // 如果找不到前一筆資料，設為 0
            $latestPrice->change = 0.00;
            $latestPrice->change_percent = 0.00;
            return;
        }

        // 計算漲跌和漲跌幅
        $change = floatval($latestPrice->close) - floatval($previousPrice);
        $changePercent = ($change / floatval($previousPrice)) * 100;

        // 將計算結果賦值給 latestPrice (會自動格式化為 decimal:2)
        $latestPrice->change = round($change, 2);
        $latestPrice->change_percent = round($changePercent, 2);
    }


    /**
     * 根據 Symbol 取得單一股票的最新價格 (診斷用)
     * * GET /api/stocks/latest-by-symbol/{symbol}
     */
    public function latestBySymbol(string $symbol): JsonResponse
    {
        try {
            // 1. 尋找股票 (Stock)
            $stock = Stock::where('symbol', $symbol)
                ->with('latestPrice')
                ->first();

            if (!$stock) {
                return response()->json([
                    'success' => false,
                    'message' => "股票代碼 {$symbol} 不存在於 Stocks table 中。",
                    'data' => null
                ], 404);
            }

            // 修正點：在診斷 API 中也計算漲跌幅
            if ($stock->latestPrice) {
                $this->calculateChangeAndPercent($stock);
            }

            // 2. 檢查最新價格
            if (!$stock->latestPrice) {
                return response()->json([
                    'success' => true,
                    'message' => "股票 {$symbol} 存在，但沒有任何價格數據 (StockPrices table 中無資料)。",
                    'data' => [
                        'stock' => $stock,
                        'latest_price' => null
                    ]
                ], 200);
            }

            // 3. 返回數據
            return response()->json([
                'success' => true,
                'message' => "股票 {$symbol} 的最新數據已成功取出。",
                'data' => [
                    'stock' => $stock,
                    'latest_price' => $stock->latestPrice,
                    'close' => floatval($stock->latestPrice->close) // 驗證價格格式
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error("診斷 {$symbol} 資料時發生錯誤: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '後端發生錯誤: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 獲取單一股票的最新價格 (保持原結構兼容)
     * @param string $stockCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestPrice(string $stockCode)
    {
        try {
            $stock = Stock::where('symbol', $stockCode)->with('latestPrice')->first();

            if (!$stock) {
                return response()->json(['message' => 'Stock not found.'], 404);
            }
            if (!$stock->latestPrice) {
                return response()->json(['message' => 'No price data found for this stock.'], 404);
            }

            // 返回的結構與 index 方法的 latestPrice 保持一致，但簡化
            return response()->json([
                'code' => $stockCode,
                'name' => $stock->name,
                'latest_price' => floatval($stock->latestPrice->close),
                'date' => $stock->latestPrice->trade_date,
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching latest stock price for $stockCode: " . $e->getMessage());
            return response()->json(['message' => 'Internal server error.'], 500);
        }
    }


    /**
     * 取得單一股票詳情
     * * GET /api/stocks/{id}
     */
    public function show($id): JsonResponse
    {
        $stock = Stock::with(['latestPrice', 'options'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $stock
        ]);
    }

    /**
     * 取得股票歷史價格
     * * GET /api/stocks/{id}/prices
     */
    public function prices(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:1d,1w,1m,3m,6m,1y,ytd,all',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stock = Stock::findOrFail($id);

            $query = StockPrice::where('stock_id', $id);

            // 根據 period 參數設定日期範圍
            if ($request->has('period') && $request->input('period') !== 'all') {
                $period = $request->input('period');
                $startDate = $this->getDateRange($period);
                $query->where('trade_date', '>=', $startDate->format('Y-m-d'));
            }

            // 自訂日期範圍
            if ($request->has('start_date')) {
                $query->where('trade_date', '>=', $request->input('start_date'));
            }

            if ($request->has('end_date')) {
                $query->where('trade_date', '<=', $request->input('end_date'));
            }

            $prices = $query->orderBy('trade_date', 'asc')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'prices' => $prices,
                    'count' => $prices->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得股價歷史失敗', [
                'stock_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得股票圖表資料
     * * GET /api/stocks/{id}/chart
     */
    public function chart(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:1d,1w,1m,3m,6m,1y,ytd,all',
            'interval' => 'nullable|in:1m,5m,15m,30m,1h,1d',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stock = Stock::findOrFail($id);
            $period = $request->input('period', '1m');
            $interval = $request->input('interval', '1d');

            $query = StockPrice::where('stock_id', $id);

            // 設定日期範圍
            if ($period !== 'all') {
                $startDate = $this->getDateRange($period);
                $query->where('trade_date', '>=', $startDate->format('Y-m-d'));
            }

            $prices = $query->orderBy('trade_date', 'asc')->get();

            // 格式化為圖表資料格式
            $chartData = [
                'labels' => $prices->pluck('trade_date')->toArray(),
                'datasets' => [
                    [
                        'label' => $stock->name . ' 收盤價',
                        'data' => $prices->pluck('close')->toArray(),
                        'borderColor' => 'rgb(75, 192, 192)',
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                    ],
                    [
                        'label' => '成交量',
                        'data' => $prices->pluck('volume')->toArray(),
                        'type' => 'bar',
                        'yAxisID' => 'y1',
                        'backgroundColor' => 'rgba(153, 102, 255, 0.2)',
                    ]
                ],
                'ohlc' => $prices->map(function ($price) {
                    return [
                        'date' => $price->trade_date,
                        'open' => $price->open,
                        'high' => $price->high,
                        'low' => $price->low,
                        'close' => $price->close,
                        'volume' => $price->volume,
                    ];
                })->toArray(),
            ];

            return response()->json([
                'success' => true,
                'data' => $chartData
            ]);
        } catch (\Exception $e) {
            Log::error('取得圖表資料失敗', [
                'stock_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得股票技術指標
     * * GET /api/stocks/{id}/indicators
     */
    public function indicators(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|integer|min:5|max:200',
            'indicators' => 'nullable|array',
            'indicators.*' => 'in:sma,ema,rsi,macd,bollinger',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stock = Stock::findOrFail($id);
            $period = $request->input('period', 20);
            $requestedIndicators = $request->input('indicators', ['sma', 'rsi']);

            // 取得歷史價格資料
            $prices = StockPrice::where('stock_id', $id)
                ->orderBy('trade_date', 'desc')
                ->limit(200)
                ->get()
                ->reverse()
                ->values();

            if ($prices->count() < $period) {
                return response()->json([
                    'success' => false,
                    'message' => '歷史資料不足'
                ], 400);
            }

            $indicators = [];

            // 計算簡單移動平均線 (SMA)
            if (in_array('sma', $requestedIndicators)) {
                $sma = $this->calculateSMA($prices, $period);
                $indicators['sma'] = [
                    'period' => $period,
                    'values' => $sma,
                ];
            }

            // 計算指數移動平均線 (EMA)
            if (in_array('ema', $requestedIndicators)) {
                $ema = $this->calculateEMA($prices, $period);
                $indicators['ema'] = [
                    'period' => $period,
                    'values' => $ema,
                ];
            }

            // 計算 RSI
            if (in_array('rsi', $requestedIndicators)) {
                $rsi = $this->calculateRSI($prices, 14);
                $indicators['rsi'] = [
                    'period' => 14,
                    'values' => $rsi,
                    'current' => end($rsi)['value'] ?? null,
                ];
            }

            // 計算 MACD
            if (in_array('macd', $requestedIndicators)) {
                $macd = $this->calculateMACD($prices);
                $indicators['macd'] = $macd;
            }

            // 計算布林通道
            if (in_array('bollinger', $requestedIndicators)) {
                $bollinger = $this->calculateBollingerBands($prices, $period);
                $indicators['bollinger'] = [
                    'period' => $period,
                    'std_dev' => 2,
                    'values' => $bollinger,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'indicators' => $indicators,
                    'data_points' => $prices->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('計算技術指標失敗', [
                'stock_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 搜尋股票
     * * POST /api/stocks/search
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $searchQuery = $request->input('query');
            $limit = $request->input('limit', 20);

            $stocks = Stock::where(function ($q) use ($searchQuery) {
                $q->where('symbol', 'like', "%{$searchQuery}%")
                    ->orWhere('name', 'like', "%{$searchQuery}%");
            })
                ->where('is_active', true)
                ->with('latestPrice')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $stocks,
                'count' => $stocks->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('搜尋股票失敗', [
                'query' => $request->input('query'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '搜尋失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 匯入股票資料（觸發爬蟲）
     * * POST /api/stocks/import
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'symbol' => 'nullable|string',
            'date' => 'nullable|date',
            'sync' => 'nullable|boolean'
        ]);

        $symbol = $request->input('symbol');
        $date = $request->input('date', now()->format('Y-m-d'));
        $sync = $request->input('sync', false);

        try {
            $job = new FetchStockDataJob($date, $symbol);

            if ($sync) {
                // 同步執行
                dispatch($job)->onConnection('sync');
                $message = $symbol ? "股票 {$symbol} 資料已更新" : "所有股票資料已更新";
            } else {
                // 加入佇列
                dispatch($job);
                $message = $symbol ? "股票 {$symbol} 資料更新已加入佇列" : "股票資料更新已加入佇列";
            }

            Log::info($message);

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            Log::error('資料匯入失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '資料匯入失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算簡單移動平均線 (SMA)
     */
    private function calculateSMA($prices, $period)
    {
        $sma = [];
        $closes = $prices->pluck('close')->toArray();

        for ($i = $period - 1; $i < count($closes); $i++) {
            $sum = 0;
            for ($j = 0; $j < $period; $j++) {
                $sum += $closes[$i - $j];
            }
            $sma[] = [
                'date' => $prices[$i]->trade_date,
                'value' => round($sum / $period, 2)
            ];
        }

        return $sma;
    }

    /**
     * 計算指數移動平均線 (EMA)
     */
    private function calculateEMA($prices, $period)
    {
        $ema = [];
        $closes = $prices->pluck('close')->toArray();
        $multiplier = 2 / ($period + 1);

        // 第一個 EMA 使用 SMA
        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $closes[$i];
        }
        $previousEma = $sum / $period;
        $ema[] = [
            'date' => $prices[$period - 1]->trade_date,
            'value' => round($previousEma, 2)
        ];

        // 計算後續的 EMA
        for ($i = $period; $i < count($closes); $i++) {
            $currentEma = ($closes[$i] - $previousEma) * $multiplier + $previousEma;
            $ema[] = [
                'date' => $prices[$i]->trade_date,
                'value' => round($currentEma, 2)
            ];
            $previousEma = $currentEma;
        }

        return $ema;
    }

    /**
     * 計算相對強弱指標 (RSI)
     */
    private function calculateRSI($prices, $period = 14)
    {
        $rsi = [];
        $closes = $prices->pluck('close')->toArray();

        if (count($closes) < $period + 1) {
            return $rsi;
        }

        $gains = [];
        $losses = [];

        // 計算價格變化
        for ($i = 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? abs($change) : 0;
        }

        // 計算第一個 RSI
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        if ($avgLoss == 0) {
            $rs = 100;
        } else {
            $rs = $avgGain / $avgLoss;
        }

        $rsi[] = [
            'date' => $prices[$period]->trade_date,
            'value' => round(100 - (100 / (1 + $rs)), 2)
        ];

        // 計算後續的 RSI
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = ($avgGain * ($period - 1) + $gains[$i]) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;

            if ($avgLoss == 0) {
                $rs = 100;
            } else {
                $rs = $avgGain / $avgLoss;
            }

            $rsi[] = [
                'date' => $prices[$i + 1]->trade_date,
                'value' => round(100 - (100 / (1 + $rs)), 2)
            ];
        }

        return $rsi;
    }

    /**
     * 計算 MACD
     */
    private function calculateMACD($prices)
    {
        $closes = $prices->pluck('close')->toArray();

        // 計算 12 日和 26 日 EMA
        $ema12 = $this->calculateEMAValues($closes, 12);
        $ema26 = $this->calculateEMAValues($closes, 26);

        // 計算 MACD 線
        $macdLine = [];
        for ($i = 0; $i < count($ema12); $i++) {
            $macdLine[] = $ema12[$i] - $ema26[$i];
        }

        // 計算信號線 (9 日 EMA of MACD)
        $signalLine = $this->calculateEMAValues($macdLine, 9);

        // 計算柱狀圖
        $histogram = [];
        for ($i = 0; $i < count($signalLine); $i++) {
            $histogram[] = $macdLine[$i + (count($macdLine) - count($signalLine))] - $signalLine[$i];
        }

        $result = [];
        $startIndex = count($closes) - count($signalLine);

        for ($i = 0; $i < count($signalLine); $i++) {
            $result[] = [
                'date' => $prices[$startIndex + $i]->trade_date,
                'macd' => round($macdLine[$i + (count($macdLine) - count($signalLine))], 2),
                'signal' => round($signalLine[$i], 2),
                'histogram' => round($histogram[$i], 2),
            ];
        }

        return $result;
    }

    /**
     * 計算 EMA 數值陣列
     */
    private function calculateEMAValues($values, $period)
    {
        $ema = [];
        $multiplier = 2 / ($period + 1);

        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $values[$i];
        }
        $previousEma = $sum / $period;
        $ema[] = $previousEma;

        for ($i = $period; $i < count($values); $i++) {
            $currentEma = ($values[$i] - $previousEma) * $multiplier + $previousEma;
            $ema[] = $currentEma;
            $previousEma = $currentEma;
        }

        return $ema;
    }

    /**
     * 計算布林通道
     */
    private function calculateBollingerBands($prices, $period = 20, $stdDev = 2)
    {
        $bollinger = [];
        $closes = $prices->pluck('close')->toArray();

        for ($i = $period - 1; $i < count($closes); $i++) {
            $slice = array_slice($closes, $i - $period + 1, $period);
            $sma = array_sum($slice) / $period;

            // 計算標準差
            $variance = 0;
            foreach ($slice as $value) {
                $variance += pow($value - $sma, 2);
            }
            $std = sqrt($variance / $period);

            $bollinger[] = [
                'date' => $prices[$i]->trade_date,
                'middle' => round($sma, 2),
                'upper' => round($sma + ($stdDev * $std), 2),
                'lower' => round($sma - ($stdDev * $std), 2),
            ];
        }

        return $bollinger;
    }

    /**
     * 計算時間範圍
     */
    private function getDateRange($period)
    {
        return match ($period) {
            '1d' => now()->subDay(),
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            'ytd' => now()->startOfYear(),
            default => now()->subMonth(),
        };
    }
}
