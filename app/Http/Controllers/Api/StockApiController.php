<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

/**
 * 股票資料 API 控制器
 */
class StockApiController extends Controller
{
    /**
     * 取得股票列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Stock::query()->where('is_active', true);

            // 搜尋
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('symbol', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            }

            // 交易所篩選
            if ($request->has('exchange')) {
                $query->where('exchange', $request->input('exchange'));
            }

            // 產業別篩選
            if ($request->has('industry')) {
                $query->where('industry', $request->input('industry'));
            }

            // 排序
            $sortBy = $request->input('sort_by', 'symbol');
            $sortOrder = $request->input('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // 分頁
            $perPage = $request->input('per_page', 50);
            $stocks = $query->paginate($perPage);

            // 附加最新價格資訊
            $stocks->getCollection()->transform(function ($stock) {
                $latestPrice = $stock->stockPrices()
                    ->orderBy('trade_date', 'desc')
                    ->first();

                $stock->latest_price = $latestPrice;
                $stock->has_price_data = !is_null($latestPrice);

                return $stock;
            });

            return response()->json([
                'success' => true,
                'data' => $stocks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '取得股票列表失敗',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得單一股票詳細資訊
     *
     * @param string $symbol
     * @return JsonResponse
     */
    public function show(string $symbol): JsonResponse
    {
        try {
            $stock = Stock::where('symbol', $symbol)->firstOrFail();

            // 取得最新價格
            $latestPrice = $stock->stockPrices()
                ->orderBy('trade_date', 'desc')
                ->first();

            // 取得價格統計
            $priceStats = $stock->stockPrices()
                ->selectRaw('
                    COUNT(*) as total_days,
                    MIN(trade_date) as first_date,
                    MAX(trade_date) as last_date,
                    MIN(low) as period_low,
                    MAX(high) as period_high,
                    AVG(volume) as avg_volume
                ')
                ->first();

            $stock->latest_price = $latestPrice;
            $stock->price_statistics = $priceStats;

            return response()->json([
                'success' => true,
                'data' => $stock
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '取得股票資訊失敗',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * 取得股票歷史價格
     *
     * @param string $symbol
     * @param Request $request
     * @return JsonResponse
     */
    public function prices(string $symbol, Request $request): JsonResponse
    {
        try {
            $stock = Stock::where('symbol', $symbol)->firstOrFail();

            $query = $stock->stockPrices()->orderBy('trade_date', 'desc');

            // 日期範圍篩選
            if ($request->has('start_date')) {
                $query->where('trade_date', '>=', $request->input('start_date'));
            }

            if ($request->has('end_date')) {
                $query->where('trade_date', '<=', $request->input('end_date'));
            }

            // 最近N天
            if ($request->has('days')) {
                $days = (int)$request->input('days');
                $query->where('trade_date', '>=', Carbon::now()->subDays($days)->format('Y-m-d'));
            }

            // 分頁或全部
            if ($request->input('paginate', true)) {
                $perPage = $request->input('per_page', 30);
                $prices = $query->paginate($perPage);
            } else {
                $prices = $query->get();
            }

            return response()->json([
                'success' => true,
                'data' => $prices,
                'stock' => [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '取得價格資料失敗',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * 取得股票價格圖表資料
     *
     * @param string $symbol
     * @param Request $request
     * @return JsonResponse
     */
    public function chartData(string $symbol, Request $request): JsonResponse
    {
        try {
            $stock = Stock::where('symbol', $symbol)->firstOrFail();

            // 預設查詢最近90天
            $days = $request->input('days', 90);
            $startDate = Carbon::now()->subDays($days)->format('Y-m-d');

            $prices = $stock->stockPrices()
                ->where('trade_date', '>=', $startDate)
                ->orderBy('trade_date', 'asc')
                ->get();

            // 轉換為圖表格式
            $chartData = [
                'labels' => $prices->pluck('trade_date')->toArray(),
                'datasets' => [
                    [
                        'label' => '收盤價',
                        'data' => $prices->pluck('close')->toArray(),
                        'borderColor' => 'rgb(75, 192, 192)',
                        'tension' => 0.1
                    ],
                    [
                        'label' => '成交量',
                        'data' => $prices->pluck('volume')->toArray(),
                        'type' => 'bar',
                        'yAxisID' => 'volume',
                        'backgroundColor' => 'rgba(153, 102, 255, 0.2)'
                    ]
                ]
            ];

            // K線資料
            $candlestickData = $prices->map(function ($price) {
                return [
                    'x' => $price->trade_date,
                    'o' => $price->open,
                    'h' => $price->high,
                    'l' => $price->low,
                    'c' => $price->close,
                    'v' => $price->volume
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'line_chart' => $chartData,
                    'candlestick' => $candlestickData,
                    'stock_info' => [
                        'symbol' => $stock->symbol,
                        'name' => $stock->name
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '取得圖表資料失敗',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * 取得股票技術指標
     *
     * @param string $symbol
     * @param Request $request
     * @return JsonResponse
     */
    public function technicalIndicators(string $symbol, Request $request): JsonResponse
    {
        try {
            $stock = Stock::where('symbol', $symbol)->firstOrFail();

            $days = $request->input('days', 90);
            $startDate = Carbon::now()->subDays($days)->format('Y-m-d');

            $prices = $stock->stockPrices()
                ->where('trade_date', '>=', $startDate)
                ->orderBy('trade_date', 'asc')
                ->get();

            if ($prices->count() < 20) {
                return response()->json([
                    'success' => false,
                    'message' => '資料不足,無法計算技術指標'
                ], 400);
            }

            // 計算簡單移動平均線 (SMA)
            $sma5 = $this->calculateSMA($prices, 5);
            $sma10 = $this->calculateSMA($prices, 10);
            $sma20 = $this->calculateSMA($prices, 20);
            $sma60 = $this->calculateSMA($prices, 60);

            // 計算 RSI
            $rsi = $this->calculateRSI($prices, 14);

            return response()->json([
                'success' => true,
                'data' => [
                    'sma' => [
                        'sma5' => $sma5,
                        'sma10' => $sma10,
                        'sma20' => $sma20,
                        'sma60' => $sma60
                    ],
                    'rsi' => $rsi,
                    'stock_info' => [
                        'symbol' => $stock->symbol,
                        'name' => $stock->name
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '計算技術指標失敗',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算簡單移動平均線 (SMA)
     */
    private function calculateSMA($prices, $period)
    {
        $result = [];
        $count = $prices->count();

        for ($i = $period - 1; $i < $count; $i++) {
            $sum = 0;
            for ($j = 0; $j < $period; $j++) {
                $sum += $prices[$i - $j]->close;
            }
            $result[] = [
                'date' => $prices[$i]->trade_date,
                'value' => round($sum / $period, 2)
            ];
        }

        return $result;
    }

    /**
     * 計算相對強弱指標 (RSI)
     */
    private function calculateRSI($prices, $period = 14)
    {
        $result = [];
        $count = $prices->count();

        if ($count < $period + 1) {
            return $result;
        }

        for ($i = $period; $i < $count; $i++) {
            $gains = 0;
            $losses = 0;

            for ($j = 1; $j <= $period; $j++) {
                $change = $prices[$i - $j + 1]->close - $prices[$i - $j]->close;
                if ($change > 0) {
                    $gains += $change;
                } else {
                    $losses += abs($change);
                }
            }

            $avgGain = $gains / $period;
            $avgLoss = $losses / $period;

            if ($avgLoss == 0) {
                $rsi = 100;
            } else {
                $rs = $avgGain / $avgLoss;
                $rsi = 100 - (100 / (1 + $rs));
            }

            $result[] = [
                'date' => $prices[$i]->trade_date,
                'value' => round($rsi, 2)
            ];
        }

        return $result;
    }
}
