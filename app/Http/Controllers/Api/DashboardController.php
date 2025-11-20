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
 * 提供儀表板所需的綜合資料
 */
class DashboardController extends Controller
{
    /**
     * 取得熱門股票走勢
     *
     * GET /api/dashboard/stock-trends
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stockTrends(Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 5); // 預設顯示5檔股票
            $days = $request->input('days', 30);   // 預設30天

            // 計算日期範圍
            $endDate = now();
            $startDate = now()->subDays($days);

            // 取得交易量最大的前N檔股票
            $topStockIds = StockPrice::select('stock_id')
                ->whereBetween('trade_date', [$startDate, $endDate])
                ->groupBy('stock_id')
                ->orderByRaw('SUM(volume) DESC')
                ->limit($limit)
                ->pluck('stock_id');

            // 如果沒有找到股票,使用預設股票
            if ($topStockIds->isEmpty()) {
                $topStockIds = Stock::where('is_active', true)
                    ->limit($limit)
                    ->pluck('id');
            }

            // 取得股票資訊和價格資料
            $stocks = Stock::whereIn('id', $topStockIds)
                ->with(['prices' => function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('trade_date', [$startDate, $endDate])
                        ->orderBy('trade_date', 'asc');
                }])
                ->get();

            // 整理資料
            $result = [
                'stocks' => [],
                'dates' => []
            ];

            // 取得所有交易日期
            $allDates = StockPrice::whereBetween('trade_date', [$startDate, $endDate])
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

                // 計算漲跌幅 (與前一天比較)
                $changePercent = 0;
                if ($prices->count() >= 2) {
                    $latestPrice = $prices->last()->close;
                    $previousPrice = $prices->get($prices->count() - 2)->close;

                    if ($previousPrice > 0) {
                        $changePercent = (($latestPrice - $previousPrice) / $previousPrice) * 100;
                    }
                }

                $result['stocks'][] = [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'change_percent' => round($changePercent, 2),
                    'prices' => $prices->pluck('close')->toArray()
                ];
            }

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
     * 取得波動率概覽
     *
     * GET /api/dashboard/volatility-overview
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function volatilityOverview(Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 5); // 預設顯示5檔股票

            // 取得前N檔有最新價格的股票
            $stockIds = Stock::whereHas('prices')
                ->with('latestPrice')
                ->limit($limit)
                ->pluck('id');

            $volatilities = [];
            $hvSum = 0;
            $ivSum = 0;
            $count = 0;

            foreach ($stockIds as $stockId) {
                $stock = Stock::find($stockId);

                // 計算歷史波動率 (HV)
                $hv = $this->calculateHistoricalVolatility($stockId, 30);

                // 計算隱含波動率 (IV) - 從選擇權價格推算
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
     * @param int $stockId
     * @param int $days 計算期間天數
     * @return float
     */
    private function calculateHistoricalVolatility(int $stockId, int $days = 30): float
    {
        try {
            // 取得最近N天的收盤價
            $prices = StockPrice::where('stock_id', $stockId)
                ->orderBy('trade_date', 'desc')
                ->limit($days + 1) // 需要多一天來計算報酬率
                ->pluck('close')
                ->reverse()
                ->values();

            if ($prices->count() < 2) {
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
     * @param string $symbol
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
            $hv = $this->calculateHistoricalVolatility(
                Stock::where('symbol', $symbol)->value('id'),
                30
            );

            return $hv * 1.2;
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
