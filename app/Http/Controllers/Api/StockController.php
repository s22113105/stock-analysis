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
use Carbon\Carbon;

class StockController extends Controller
{
    protected $twseApi;

    public function __construct(TwseApiService $twseApi)
    {
        $this->twseApi = $twseApi;
    }

    /**
     * 取得股票列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Stock::query()->active();

        // 產業別篩選
        if ($request->has('industry')) {
            $query->where('industry', $request->industry);
        }

        // 搜尋
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // 排序
        $sortBy = $request->get('sort_by', 'symbol');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // 分頁
        $perPage = $request->get('per_page', 20);
        $stocks = $query->with('latestPrice')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $stocks
        ]);
    }

    /**
     * 取得單一股票詳情
     */
    public function show($id): JsonResponse
    {
        $stock = Stock::with(['latestPrice', 'options' => function ($query) {
            $query->notExpired();
        }])->findOrFail($id);

        // 加入額外的計算資料
        $stock->append(['statistics']);

        return response()->json([
            'success' => true,
            'data' => $stock
        ]);
    }

    /**
     * 取得股票價格歷史
     */
    public function prices($id, Request $request): JsonResponse
    {
        $stock = Stock::findOrFail($id);

        $startDate = $request->get('start_date', now()->subMonths(3)->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $prices = $stock->prices()
            ->whereBetween('trade_date', [$startDate, $endDate])
            ->orderBy('trade_date', 'desc')
            ->get();

        // 計算統計資料
        $statistics = [
            'highest' => $prices->max('high'),
            'lowest' => $prices->min('low'),
            'average' => round($prices->avg('close'), 2),
            'total_volume' => $prices->sum('volume'),
            'price_change' => null,
            'price_change_percent' => null,
        ];

        if ($prices->count() >= 2) {
            $first = $prices->last();
            $last = $prices->first();
            $statistics['price_change'] = round($last->close - $first->close, 2);
            $statistics['price_change_percent'] = round((($last->close - $first->close) / $first->close) * 100, 2);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stock' => $stock,
                'prices' => $prices,
                'statistics' => $statistics
            ]
        ]);
    }

    /**
     * 取得股票圖表資料
     */
    public function chart($id, Request $request): JsonResponse
    {
        $stock = Stock::findOrFail($id);

        $period = $request->get('period', '1M'); // 1D, 1W, 1M, 3M, 6M, 1Y, 5Y
        $interval = $request->get('interval', 'daily'); // daily, weekly, monthly

        $startDate = $this->getStartDateByPeriod($period);
        $endDate = now();

        $query = $stock->prices()
            ->whereBetween('trade_date', [$startDate, $endDate])
            ->orderBy('trade_date');

        // 根據 interval 調整資料
        if ($interval === 'weekly') {
            $prices = $query->get()->groupBy(function ($item) {
                return Carbon::parse($item->trade_date)->startOfWeek()->format('Y-m-d');
            })->map(function ($week) {
                return [
                    'date' => $week->first()->trade_date,
                    'open' => $week->first()->open,
                    'high' => $week->max('high'),
                    'low' => $week->min('low'),
                    'close' => $week->last()->close,
                    'volume' => $week->sum('volume'),
                ];
            })->values();
        } elseif ($interval === 'monthly') {
            $prices = $query->get()->groupBy(function ($item) {
                return Carbon::parse($item->trade_date)->format('Y-m');
            })->map(function ($month) {
                return [
                    'date' => $month->first()->trade_date,
                    'open' => $month->first()->open,
                    'high' => $month->max('high'),
                    'low' => $month->min('low'),
                    'close' => $month->last()->close,
                    'volume' => $month->sum('volume'),
                ];
            })->values();
        } else {
            $prices = $query->get()->map(function ($price) {
                return [
                    'date' => $price->trade_date,
                    'open' => $price->open,
                    'high' => $price->high,
                    'low' => $price->low,
                    'close' => $price->close,
                    'volume' => $price->volume,
                ];
            });
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stock' => $stock,
                'chart_data' => $prices,
                'period' => $period,
                'interval' => $interval
            ]
        ]);
    }

    /**
     * 取得技術指標
     */
    public function indicators($id, Request $request): JsonResponse
    {
        $stock = Stock::findOrFail($id);

        $indicators = $request->get('indicators', ['ma', 'rsi', 'macd']);
        $period = $request->get('period', 60);

        $result = [];

        if (in_array('ma', $indicators)) {
            $result['ma'] = $this->calculateMA($stock, $period);
        }

        if (in_array('rsi', $indicators)) {
            $result['rsi'] = StockPrice::calculateRSI($stock->id, 14);
        }

        if (in_array('macd', $indicators)) {
            $result['macd'] = $this->calculateMACD($stock);
        }

        if (in_array('bollinger', $indicators)) {
            $result['bollinger'] = $this->calculateBollingerBands($stock, 20);
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }

    /**
     * 搜尋股票
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'keyword' => 'required|string|min:1'
        ]);

        $keyword = $request->keyword;

        $stocks = Stock::where('symbol', 'like', "{$keyword}%")
            ->orWhere('name', 'like', "%{$keyword}%")
            ->limit(20)
            ->get(['id', 'symbol', 'name', 'industry']);

        return response()->json([
            'success' => true,
            'data' => $stocks
        ]);
    }

    /**
     * 匯入股票資料（觸發爬蟲）
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'symbol' => 'nullable|string',
            'date' => 'nullable|date',
            'sync' => 'nullable|boolean'
        ]);

        $symbol = $request->symbol;
        $date = $request->get('date', now()->format('Y-m-d'));
        $sync = $request->get('sync', false);

        try {
            $job = new FetchStockDataJob($date, $symbol);

            if ($sync) {
                // 同步執行
                dispatch_sync($job);

                $message = $symbol
                    ? "股票 {$symbol} 資料已更新"
                    : "所有股票資料已更新";
            } else {
                // 加入佇列
                dispatch($job);

                $message = $symbol
                    ? "股票 {$symbol} 資料更新已加入佇列"
                    : "股票資料更新已加入佇列";
            }

            return response()->json([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '資料匯入失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 根據期間取得開始日期
     */
    private function getStartDateByPeriod($period)
    {
        switch ($period) {
            case '1D':
                return now()->subDay();
            case '1W':
                return now()->subWeek();
            case '1M':
                return now()->subMonth();
            case '3M':
                return now()->subMonths(3);
            case '6M':
                return now()->subMonths(6);
            case '1Y':
                return now()->subYear();
            case '5Y':
                return now()->subYears(5);
            default:
                return now()->subMonth();
        }
    }

    /**
     * 計算移動平均線
     */
    private function calculateMA($stock, $period)
    {
        $prices = $stock->prices()
            ->orderBy('trade_date', 'desc')
            ->limit($period + 30)
            ->get();

        $ma = [];

        for ($i = 0; $i <= 30; $i++) {
            if ($prices->count() > $i + $period - 1) {
                $subset = $prices->slice($i, $period);
                $ma[] = [
                    'date' => $prices[$i]->trade_date,
                    'ma' => round($subset->avg('close'), 2)
                ];
            }
        }

        return array_reverse($ma);
    }

    /**
     * 計算 MACD
     */
    private function calculateMACD($stock)
    {
        // 簡化的 MACD 計算
        $prices = $stock->prices()
            ->orderBy('trade_date', 'desc')
            ->limit(50)
            ->pluck('close')
            ->reverse()
            ->values();

        if ($prices->count() < 26) {
            return null;
        }

        // 計算 EMA12 和 EMA26
        $ema12 = $this->calculateEMA($prices->toArray(), 12);
        $ema26 = $this->calculateEMA($prices->toArray(), 26);

        // MACD = EMA12 - EMA26
        $macd = end($ema12) - end($ema26);

        return [
            'macd' => round($macd, 4),
            'signal' => round($macd * 0.9, 4), // 簡化計算
            'histogram' => round($macd * 0.1, 4)
        ];
    }

    /**
     * 計算 EMA
     */
    private function calculateEMA($prices, $period)
    {
        $ema = [];
        $multiplier = 2 / ($period + 1);

        // 初始 SMA
        $ema[0] = array_sum(array_slice($prices, 0, $period)) / $period;

        for ($i = $period; $i < count($prices); $i++) {
            $ema[] = ($prices[$i] - end($ema)) * $multiplier + end($ema);
        }

        return $ema;
    }

    /**
     * 計算布林通道
     */
    private function calculateBollingerBands($stock, $period = 20)
    {
        $ma = StockPrice::calculateMA($stock->id, $period);

        $prices = $stock->prices()
            ->orderBy('trade_date', 'desc')
            ->limit($period)
            ->pluck('close');

        if ($prices->count() < $period) {
            return null;
        }

        $stdDev = $this->standardDeviation($prices->toArray());

        return [
            'upper' => round($ma + (2 * $stdDev), 2),
            'middle' => round($ma, 2),
            'lower' => round($ma - (2 * $stdDev), 2)
        ];
    }

    /**
     * 計算標準差
     */
    private function standardDeviation($values)
    {
        $mean = array_sum($values) / count($values);
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / count($values));
    }
}
