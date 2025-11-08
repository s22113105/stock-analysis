<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Option;
use App\Models\StockPrice;
use App\Models\Prediction;
use App\Models\BacktestResult;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 儀表板統計 API 控制器
 */
class DashboardController extends Controller
{
    /**
     * 取得儀表板統計資料
     * 
     * GET /api/dashboard/stats
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            // 快取 5 分鐘
            $stats = Cache::remember('dashboard_stats', 300, function () {
                return [
                    // 股票統計
                    'stocks' => [
                        'total' => Stock::count(),
                        'active' => Stock::where('is_active', true)->count(),
                        'updated_today' => Stock::whereHas('prices', function ($q) {
                            $q->where('trade_date', now()->format('Y-m-d'));
                        })->count(),
                    ],
                    
                    // 選擇權統計
                    'options' => [
                        'total' => Option::count(),
                        'active' => Option::where('is_active', true)->count(),
                        'expiring_week' => Option::where('is_active', true)
                            ->where('expiry_date', '<=', now()->addDays(7)->format('Y-m-d'))
                            ->where('expiry_date', '>=', now()->format('Y-m-d'))
                            ->count(),
                    ],
                    
                    // 預測統計
                    'predictions' => [
                        'total' => Prediction::count(),
                        'today' => Prediction::whereDate('prediction_date', today())->count(),
                        'accuracy' => $this->calculatePredictionAccuracy(),
                    ],
                    
                    // 回測統計
                    'backtests' => [
                        'total' => BacktestResult::count(),
                        'profitable' => BacktestResult::where('total_return', '>', 0)->count(),
                        'avg_return' => BacktestResult::avg('total_return'),
                    ],
                    
                    // 系統狀態
                    'system' => [
                        'last_update' => $this->getLastUpdateTime(),
                        'data_freshness' => $this->getDataFreshness(),
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('儀表板統計查詢錯誤', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得投資組合資料
     * 
     * GET /api/dashboard/portfolio
     */
    public function portfolio(Request $request): JsonResponse
    {
        try {
            // 獲取用戶的持倉（這裡簡化處理，實際應該從用戶持倉表中查詢）
            $topStocks = Stock::with('latestPrice')
                ->has('prices')
                ->orderBy('market_cap', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($stock) {
                    $latestPrice = $stock->latestPrice;
                    $prevPrice = StockPrice::where('stock_id', $stock->id)
                        ->where('trade_date', '<', $latestPrice->trade_date ?? now())
                        ->orderBy('trade_date', 'desc')
                        ->first();

                    $change = 0;
                    $changePercent = 0;
                    
                    if ($latestPrice && $prevPrice) {
                        $change = $latestPrice->close - $prevPrice->close;
                        $changePercent = ($change / $prevPrice->close) * 100;
                    }

                    return [
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                        'current_price' => $latestPrice->close ?? 0,
                        'change' => round($change, 2),
                        'change_percent' => round($changePercent, 2),
                        'volume' => $latestPrice->volume ?? 0,
                        'market_cap' => $stock->market_cap,
                    ];
                });

            // 計算投資組合總覽
            $totalValue = $topStocks->sum('current_price');
            $avgChange = $topStocks->avg('change_percent');

            return response()->json([
                'success' => true,
                'data' => [
                    'holdings' => $topStocks,
                    'summary' => [
                        'total_value' => round($totalValue, 2),
                        'total_holdings' => $topStocks->count(),
                        'avg_change_percent' => round($avgChange, 2),
                        'updated_at' => now()->toDateTimeString(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('投資組合查詢錯誤', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得績效分析
     * 
     * GET /api/dashboard/performance
     */
    public function performance(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', 30); // 預設 30 天
            $startDate = now()->subDays($period)->format('Y-m-d');

            // 獲取市場表現
            $marketPerformance = StockPrice::select(
                    'trade_date',
                    DB::raw('AVG(close) as avg_close'),
                    DB::raw('SUM(volume) as total_volume')
                )
                ->where('trade_date', '>=', $startDate)
                ->groupBy('trade_date')
                ->orderBy('trade_date')
                ->get();

            // 計算累積報酬率
            if ($marketPerformance->isNotEmpty()) {
                $firstPrice = $marketPerformance->first()->avg_close;
                $lastPrice = $marketPerformance->last()->avg_close;
                $returnRate = (($lastPrice - $firstPrice) / $firstPrice) * 100;
            } else {
                $returnRate = 0;
            }

            // 獲取熱門股票
            $topGainers = Stock::with('latestPrice')
                ->whereHas('prices', function ($q) use ($startDate) {
                    $q->where('trade_date', '>=', $startDate);
                })
                ->get()
                ->map(function ($stock) use ($startDate) {
                    $firstPrice = StockPrice::where('stock_id', $stock->id)
                        ->where('trade_date', '>=', $startDate)
                        ->orderBy('trade_date')
                        ->first();
                    
                    $latestPrice = $stock->latestPrice;
                    
                    if ($firstPrice && $latestPrice) {
                        $change = (($latestPrice->close - $firstPrice->close) / $firstPrice->close) * 100;
                        return [
                            'symbol' => $stock->symbol,
                            'name' => $stock->name,
                            'change_percent' => round($change, 2),
                        ];
                    }
                    return null;
                })
                ->filter()
                ->sortByDesc('change_percent')
                ->take(5)
                ->values();

            $topLosers = Stock::with('latestPrice')
                ->whereHas('prices', function ($q) use ($startDate) {
                    $q->where('trade_date', '>=', $startDate);
                })
                ->get()
                ->map(function ($stock) use ($startDate) {
                    $firstPrice = StockPrice::where('stock_id', $stock->id)
                        ->where('trade_date', '>=', $startDate)
                        ->orderBy('trade_date')
                        ->first();
                    
                    $latestPrice = $stock->latestPrice;
                    
                    if ($firstPrice && $latestPrice) {
                        $change = (($latestPrice->close - $firstPrice->close) / $firstPrice->close) * 100;
                        return [
                            'symbol' => $stock->symbol,
                            'name' => $stock->name,
                            'change_percent' => round($change, 2),
                        ];
                    }
                    return null;
                })
                ->filter()
                ->sortBy('change_percent')
                ->take(5)
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'period_days' => $period,
                    'market_return' => round($returnRate, 2),
                    'performance_chart' => $marketPerformance,
                    'top_gainers' => $topGainers,
                    'top_losers' => $topLosers,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('績效分析查詢錯誤', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得警示資訊
     * 
     * GET /api/dashboard/alerts
     */
    public function alerts(Request $request): JsonResponse
    {
        try {
            $alerts = [];

            // 檢查即將到期的選擇權
            $expiringOptions = Option::where('is_active', true)
                ->where('expiry_date', '<=', now()->addDays(3)->format('Y-m-d'))
                ->where('expiry_date', '>=', now()->format('Y-m-d'))
                ->count();

            if ($expiringOptions > 0) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => '選擇權即將到期',
                    'message' => "有 {$expiringOptions} 個選擇權將在 3 天內到期",
                    'timestamp' => now()->toDateTimeString(),
                ];
            }

            // 檢查預測準確度
            $recentPredictions = Prediction::where('prediction_date', '>=', now()->subDays(7))
                ->count();

            if ($recentPredictions < 10) {
                $alerts[] = [
                    'type' => 'info',
                    'title' => '預測資料不足',
                    'message' => '最近 7 天預測資料較少，建議執行預測模型',
                    'timestamp' => now()->toDateTimeString(),
                ];
            }

            // 檢查資料更新狀態
            $outdatedStocks = Stock::whereDoesntHave('prices', function ($q) {
                $q->where('trade_date', '>=', now()->subDays(3)->format('Y-m-d'));
            })->count();

            if ($outdatedStocks > 0) {
                $alerts[] = [
                    'type' => 'error',
                    'title' => '資料需要更新',
                    'message' => "有 {$outdatedStocks} 支股票的資料超過 3 天未更新",
                    'timestamp' => now()->toDateTimeString(),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts,
                    'total' => count($alerts),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('警示查詢錯誤', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算預測準確度
     */
    private function calculatePredictionAccuracy(): float
    {
        try {
            $predictions = Prediction::where('target_date', '<=', now())
                ->where('actual_price', '>', 0)
                ->get();

            if ($predictions->isEmpty()) {
                return 0;
            }

            $accuracySum = 0;
            foreach ($predictions as $prediction) {
                $error = abs($prediction->predicted_price - $prediction->actual_price);
                $accuracy = 100 - (($error / $prediction->actual_price) * 100);
                $accuracySum += max(0, $accuracy);
            }

            return round($accuracySum / $predictions->count(), 2);

        } catch (\Exception $e) {
            Log::error('預測準確度計算錯誤', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 取得最後更新時間
     */
    private function getLastUpdateTime(): string
    {
        try {
            $latestPrice = StockPrice::orderBy('trade_date', 'desc')
                ->orderBy('updated_at', 'desc')
                ->first();

            return $latestPrice ? $latestPrice->updated_at->toDateTimeString() : 'N/A';

        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * 取得資料新鮮度
     */
    private function getDataFreshness(): string
    {
        try {
            $latestPrice = StockPrice::orderBy('trade_date', 'desc')->first();
            
            if (!$latestPrice) {
                return 'no_data';
            }

            $daysSinceUpdate = now()->diffInDays($latestPrice->trade_date);

            if ($daysSinceUpdate == 0) {
                return 'fresh';
            } elseif ($daysSinceUpdate <= 1) {
                return 'recent';
            } elseif ($daysSinceUpdate <= 3) {
                return 'moderate';
            } else {
                return 'stale';
            }

        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}