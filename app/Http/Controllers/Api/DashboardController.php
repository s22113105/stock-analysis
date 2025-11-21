<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use App\Models\Volatility;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 儀表板 API 控制器
 *
 * 專門顯示 2330、2317、2454 三支股票
 * 提供儀表板所需的綜合資料
 */
class DashboardController extends Controller
{
    /**
     * 固定顯示的股票代碼
     */
    private const TARGET_STOCKS = ['2330', '2317', '2454'];

    /**
     * 取得股票走勢 (固定顯示 2330、2317、2454)
     *
     * GET /api/dashboard/stock-trends
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stockTrends(Request $request): JsonResponse
    {
        try {
            $days = $request->input('days', 30); // 預設30天 (一個月)

            // 計算日期範圍
            $endDate = now();
            $startDate = now()->subDays($days);

            // 取得固定的三支股票
            $stocks = Stock::whereIn('symbol', self::TARGET_STOCKS)
                ->with(['prices' => function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('trade_date', [$startDate, $endDate])
                        ->orderBy('trade_date', 'asc');
                }])
                ->get();

            // 確保有找到股票
            if ($stocks->isEmpty()) {
                Log::warning('找不到指定的股票', [
                    'symbols' => self::TARGET_STOCKS
                ]);

                return response()->json([
                    'success' => false,
                    'message' => '找不到指定的股票 (2330, 2317, 2454),請先執行爬蟲'
                ], 404);
            }

            // 整理資料
            $result = [
                'stocks' => [],
                'dates' => []
            ];

            // 取得所有交易日期 (用於 X 軸)
            $allDates = StockPrice::whereIn('stock_id', $stocks->pluck('id'))
                ->whereBetween('trade_date', [$startDate, $endDate])
                ->distinct()
                ->orderBy('trade_date', 'asc')
                ->pluck('trade_date')
                ->map(function ($date) {
                    return Carbon::parse($date)->format('m/d');
                })
                ->toArray();

            $result['dates'] = $allDates;

            // 處理每檔股票
            foreach ($stocks as $stock) {
                $prices = $stock->prices;

                // 計算漲跌幅 (與第一天比較)
                $changePercent = 0;
                if ($prices->count() >= 2) {
                    $firstPrice = $prices->first()->close;
                    $latestPrice = $prices->last()->close;

                    if ($firstPrice > 0) {
                        $changePercent = (($latestPrice - $firstPrice) / $firstPrice) * 100;
                    }
                }

                $result['stocks'][] = [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'change_percent' => round($changePercent, 2),
                    'latest_price' => $prices->count() > 0 ? $prices->last()->close : 0,
                    'prices' => $prices->pluck('close')->toArray()
                ];
            }

            Log::info('成功取得股票走勢', [
                'stocks' => $stocks->pluck('symbol'),
                'date_range' => [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')],
                'data_points' => count($result['dates'])
            ]);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('取得股票走勢失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得股票走勢失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得波動率概覽 (固定顯示 2330、2317、2454)
     *
     * GET /api/dashboard/volatility-overview
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function volatilityOverview(Request $request): JsonResponse
    {
        try {
            // 取得固定的三支股票
            $stocks = Stock::whereIn('symbol', self::TARGET_STOCKS)->get();

            if ($stocks->isEmpty()) {
                Log::warning('找不到指定的股票進行波動率計算', [
                    'symbols' => self::TARGET_STOCKS
                ]);

                return response()->json([
                    'success' => false,
                    'message' => '找不到指定的股票'
                ], 404);
            }

            $volatilities = [];
            $hvSum = 0;
            $ivSum = 0;
            $count = 0;

            foreach ($stocks as $stock) {
                // 計算歷史波動率 (HV) - 使用 30 天期間
                $hv = $this->calculateHistoricalVolatility($stock->id, 30);

                // 計算隱含波動率 (IV)
                $iv = $this->calculateImpliedVolatility($stock->symbol);

                $volatilities[] = [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'hv' => round($hv, 2),
                    'iv' => round($iv, 2)
                ];

                $hvSum += $hv;
                $ivSum += $iv;
                $count++;
            }

            // 計算平均值
            $avgHV = $count > 0 ? $hvSum / $count : 0;
            $avgIV = $count > 0 ? $ivSum / $count : 0;

            Log::info('成功計算波動率', [
                'stocks' => $stocks->pluck('symbol'),
                'avg_hv' => $avgHV,
                'avg_iv' => $avgIV
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'volatilities' => $volatilities,
                    'avg_hv' => round($avgHV, 2),
                    'avg_iv' => round($avgIV, 2)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得波動率概覽失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得波動率概覽失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算歷史波動率 (Historical Volatility)
     *
     * 使用對數報酬率的標準差計算
     *
     * @param int $stockId 股票ID
     * @param int $days 計算期間天數
     * @return float
     */
    private function calculateHistoricalVolatility(int $stockId, int $days = 30): float
    {
        try {
            // 取得最近N天的收盤價 (需要多一天來計算報酬率)
            $prices = StockPrice::where('stock_id', $stockId)
                ->orderBy('trade_date', 'desc')
                ->limit($days + 1)
                ->pluck('close')
                ->reverse()
                ->values();

            if ($prices->count() < 2) {
                Log::warning('資料不足以計算歷史波動率', [
                    'stock_id' => $stockId,
                    'data_count' => $prices->count()
                ]);
                return 0;
            }

            // 計算對數報酬率
            $returns = [];
            for ($i = 1; $i < $prices->count(); $i++) {
                if ($prices[$i - 1] > 0) {
                    $returns[] = log($prices[$i] / $prices[$i - 1]);
                }
            }

            if (empty($returns)) {
                return 0;
            }

            // 計算標準差
            $mean = array_sum($returns) / count($returns);
            $variance = 0;

            foreach ($returns as $return) {
                $variance += pow($return - $mean, 2);
            }

            $variance = $variance / count($returns);
            $stdDev = sqrt($variance);

            // 年化波動率 (假設一年252個交易日)
            $annualizedVol = $stdDev * sqrt(252) * 100;

            return $annualizedVol;
        } catch (\Exception $e) {
            Log::error('計算歷史波動率失敗', [
                'stock_id' => $stockId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * 計算隱含波動率 (Implied Volatility)
     *
     * 從選擇權價格反推波動率
     * 簡化版本:使用已計算的 IV 或估算值
     *
     * @param string $symbol 股票代碼
     * @return float
     */
    private function calculateImpliedVolatility(string $symbol): float
    {
        try {
            // 方法1: 如果有 volatilities 表的記錄,直接使用
            $volatility = Volatility::where('stock_id', function ($query) use ($symbol) {
                $query->select('id')
                    ->from('stocks')
                    ->where('symbol', $symbol)
                    ->limit(1);
            })
                ->orderBy('calculation_date', 'desc')
                ->first();

            if ($volatility && $volatility->implied_volatility) {
                return $volatility->implied_volatility * 100;
            }

            // 方法2: 從選擇權價格表取得最新的 IV
            $optionPrice = OptionPrice::whereHas('option', function ($query) use ($symbol) {
                // TXO 相關選擇權
                $query->where('underlying', 'TXO');
            })
                ->whereNotNull('implied_volatility')
                ->orderBy('trade_date', 'desc')
                ->first();

            if ($optionPrice && $optionPrice->implied_volatility) {
                return $optionPrice->implied_volatility * 100;
            }

            // 方法3: 使用 HV 的 1.2 倍作為估算 (IV 通常略高於 HV)
            $stockId = Stock::where('symbol', $symbol)->value('id');
            if ($stockId) {
                $hv = $this->calculateHistoricalVolatility($stockId, 30);
                return $hv * 1.2;
            }

            return 0;
        } catch (\Exception $e) {
            Log::error('計算隱含波動率失敗', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * 取得儀表板統計資訊
     *
     * GET /api/dashboard/stats
     *
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total_stocks' => Stock::where('is_active', true)->count(),
                'total_options' => Option::where('is_active', true)->count(),
                'latest_update' => StockPrice::max('updated_at'),
                'data_coverage_days' => StockPrice::distinct('trade_date')->count(),
                'target_stocks' => self::TARGET_STOCKS
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('取得儀表板統計失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得統計資訊失敗: ' . $e->getMessage()
            ], 500);
        }
    }
}
