<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use App\Models\Prediction;
use App\Models\BacktestResult;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * 報表生成 API 控制器
 * 
 * 處理日報、月報、績效報告等功能
 */
class ReportController extends Controller
{
    /**
     * 取得每日報表
     * 
     * GET /api/reports/daily
     */
    public function daily(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $date = $request->input('date', now()->format('Y-m-d'));

            // 市場摘要
            $marketSummary = [
                'date' => $date,
                'stocks_updated' => StockPrice::where('trade_date', $date)->count(),
                'options_updated' => OptionPrice::where('trade_date', $date)->count(),
                'total_volume' => StockPrice::where('trade_date', $date)->sum('volume'),
                'total_turnover' => StockPrice::where('trade_date', $date)->sum('turnover'),
            ];

            // 漲跌統計
            $priceChanges = StockPrice::where('trade_date', $date)
                ->select(
                    DB::raw('SUM(CASE WHEN change > 0 THEN 1 ELSE 0 END) as gainers'),
                    DB::raw('SUM(CASE WHEN change < 0 THEN 1 ELSE 0 END) as losers'),
                    DB::raw('SUM(CASE WHEN change = 0 THEN 1 ELSE 0 END) as unchanged')
                )
                ->first();

            // 前 10 大漲幅
            $topGainers = StockPrice::where('trade_date', $date)
                ->where('change_percent', '>', 0)
                ->with('stock')
                ->orderBy('change_percent', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($price) {
                    return [
                        'symbol' => $price->stock->symbol,
                        'name' => $price->stock->name,
                        'close' => $price->close,
                        'change' => $price->change,
                        'change_percent' => $price->change_percent,
                        'volume' => $price->volume,
                    ];
                });

            // 前 10 大跌幅
            $topLosers = StockPrice::where('trade_date', $date)
                ->where('change_percent', '<', 0)
                ->with('stock')
                ->orderBy('change_percent', 'asc')
                ->limit(10)
                ->get()
                ->map(function ($price) {
                    return [
                        'symbol' => $price->stock->symbol,
                        'name' => $price->stock->name,
                        'close' => $price->close,
                        'change' => $price->change,
                        'change_percent' => $price->change_percent,
                        'volume' => $price->volume,
                    ];
                });

            // 前 10 大成交量
            $topVolumes = StockPrice::where('trade_date', $date)
                ->with('stock')
                ->orderBy('volume', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($price) {
                    return [
                        'symbol' => $price->stock->symbol,
                        'name' => $price->stock->name,
                        'close' => $price->close,
                        'volume' => $price->volume,
                        'turnover' => $price->turnover,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'market_summary' => $marketSummary,
                    'price_changes' => $priceChanges,
                    'top_gainers' => $topGainers,
                    'top_losers' => $topLosers,
                    'top_volumes' => $topVolumes,
                    'generated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('生成每日報表失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '生成報表失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得月報
     * 
     * GET /api/reports/monthly
     */
    public function monthly(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $year = $request->input('year', now()->year);
            $month = $request->input('month', now()->month);
            
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();

            // 月度統計
            $monthlyStats = [
                'year' => $year,
                'month' => $month,
                'trading_days' => StockPrice::whereBetween('trade_date', [
                    $startDate->format('Y-m-d'),
                    $endDate->format('Y-m-d')
                ])->distinct('trade_date')->count(),
                'total_volume' => StockPrice::whereBetween('trade_date', [
                    $startDate->format('Y-m-d'),
                    $endDate->format('Y-m-d')
                ])->sum('volume'),
                'total_turnover' => StockPrice::whereBetween('trade_date', [
                    $startDate->format('Y-m-d'),
                    $endDate->format('Y-m-d')
                ])->sum('turnover'),
            ];

            // 月度表現最佳股票
            $topPerformers = DB::table('stock_prices as sp1')
                ->join('stock_prices as sp2', function ($join) use ($startDate, $endDate) {
                    $join->on('sp1.stock_id', '=', 'sp2.stock_id')
                         ->where('sp1.trade_date', '=', $startDate->format('Y-m-d'))
                         ->where('sp2.trade_date', '=', $endDate->format('Y-m-d'));
                })
                ->join('stocks', 'sp1.stock_id', '=', 'stocks.id')
                ->select(
                    'stocks.symbol',
                    'stocks.name',
                    'sp1.close as start_price',
                    'sp2.close as end_price',
                    DB::raw('((sp2.close - sp1.close) / sp1.close * 100) as monthly_return')
                )
                ->orderBy('monthly_return', 'desc')
                ->limit(10)
                ->get();

            // 預測準確度統計
            $predictionAccuracy = Prediction::whereBetween('prediction_date', [
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            ])
            ->select(
                'model_type',
                DB::raw('COUNT(*) as total_predictions'),
                DB::raw('AVG(accuracy) as avg_accuracy')
            )
            ->groupBy('model_type')
            ->get();

            // 回測統計
            $backtestStats = BacktestResult::whereBetween('created_at', [
                $startDate,
                $endDate
            ])
            ->select(
                'strategy_name',
                DB::raw('COUNT(*) as total_backtests'),
                DB::raw('AVG(total_return) as avg_return'),
                DB::raw('AVG(sharpe_ratio) as avg_sharpe'),
                DB::raw('AVG(max_drawdown) as avg_drawdown')
            )
            ->groupBy('strategy_name')
            ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'monthly_stats' => $monthlyStats,
                    'top_performers' => $topPerformers,
                    'prediction_accuracy' => $predictionAccuracy,
                    'backtest_stats' => $backtestStats,
                    'period' => [
                        'start' => $startDate->format('Y-m-d'),
                        'end' => $endDate->format('Y-m-d'),
                    ],
                    'generated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('生成月報失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '生成報表失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得績效報告
     * 
     * GET /api/reports/performance
     */
    public function performance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'stock_id' => 'nullable|integer|exists:stocks,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $stockId = $request->input('stock_id');

            $query = StockPrice::whereBetween('trade_date', [$startDate, $endDate]);

            if ($stockId) {
                $query->where('stock_id', $stockId);
                $stock = Stock::findOrFail($stockId);
            }

            $prices = $query->orderBy('trade_date')->get();

            if ($prices->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '該期間沒有資料'
                ], 404);
            }

            // 計算績效指標
            $startPrice = $prices->first()->close;
            $endPrice = $prices->last()->close;
            $totalReturn = (($endPrice - $startPrice) / $startPrice) * 100;

            // 計算日收益率
            $returns = [];
            for ($i = 1; $i < $prices->count(); $i++) {
                $returns[] = log($prices[$i]->close / $prices[$i-1]->close);
            }

            // 年化報酬率
            $tradingDays = count($returns);
            $avgReturn = array_sum($returns) / $tradingDays;
            $annualizedReturn = $avgReturn * 252 * 100;

            // 波動率
            $variance = 0;
            foreach ($returns as $return) {
                $variance += pow($return - $avgReturn, 2);
            }
            $volatility = sqrt($variance / $tradingDays) * sqrt(252);

            // Sharpe Ratio (假設無風險利率 1.5%)
            $riskFreeRate = 0.015;
            $sharpeRatio = ($annualizedReturn / 100 - $riskFreeRate) / $volatility;

            // 最大回撤
            $maxDrawdown = 0;
            $peak = $prices->first()->close;
            foreach ($prices as $price) {
                if ($price->close > $peak) {
                    $peak = $price->close;
                }
                $drawdown = (($peak - $price->close) / $peak) * 100;
                if ($drawdown > $maxDrawdown) {
                    $maxDrawdown = $drawdown;
                }
            }

            $performance = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'trading_days' => $tradingDays,
                ],
                'prices' => [
                    'start' => $startPrice,
                    'end' => $endPrice,
                    'high' => $prices->max('high'),
                    'low' => $prices->min('low'),
                ],
                'returns' => [
                    'total_return' => round($totalReturn, 2) . '%',
                    'annualized_return' => round($annualizedReturn, 2) . '%',
                ],
                'risk_metrics' => [
                    'volatility' => round($volatility, 4),
                    'volatility_percentage' => round($volatility * 100, 2) . '%',
                    'sharpe_ratio' => round($sharpeRatio, 2),
                    'max_drawdown' => round($maxDrawdown, 2) . '%',
                ],
            ];

            if (isset($stock)) {
                $performance['stock'] = [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $performance
            ]);

        } catch (\Exception $e) {
            Log::error('生成績效報告失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '生成報告失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得風險報告
     * 
     * GET /api/reports/risk
     */
    public function risk(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'portfolio' => 'required|array|min:1',
            'portfolio.*.stock_id' => 'required|integer|exists:stocks,id',
            'portfolio.*.weight' => 'required|numeric|min:0|max:1',
            'period' => 'nullable|integer|min:30|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $portfolio = $request->input('portfolio');
            $period = $request->input('period', 90);

            // 驗證權重總和
            $totalWeight = array_sum(array_column($portfolio, 'weight'));
            if (abs($totalWeight - 1.0) > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => '投資組合權重總和必須為 1.0'
                ], 400);
            }

            $portfolioRisk = [];
            $startDate = now()->subDays($period)->format('Y-m-d');

            foreach ($portfolio as $position) {
                $stockId = $position['stock_id'];
                $weight = $position['weight'];

                $stock = Stock::findOrFail($stockId);
                
                // 取得歷史價格
                $prices = StockPrice::where('stock_id', $stockId)
                    ->where('trade_date', '>=', $startDate)
                    ->orderBy('trade_date')
                    ->get();

                if ($prices->count() < 2) {
                    continue;
                }

                // 計算收益率
                $returns = [];
                for ($i = 1; $i < $prices->count(); $i++) {
                    $returns[] = log($prices[$i]->close / $prices[$i-1]->close);
                }

                // 計算波動率
                $avgReturn = array_sum($returns) / count($returns);
                $variance = 0;
                foreach ($returns as $return) {
                    $variance += pow($return - $avgReturn, 2);
                }
                $volatility = sqrt($variance / count($returns)) * sqrt(252);

                // VaR (Value at Risk) 95% 信賴水準
                sort($returns);
                $varIndex = intval(count($returns) * 0.05);
                $var95 = -$returns[$varIndex] * sqrt(252);

                $portfolioRisk[] = [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'weight' => $weight,
                    'volatility' => round($volatility, 4),
                    'var_95' => round($var95, 4),
                    'contribution_to_risk' => round($volatility * $weight, 4),
                ];
            }

            // 計算投資組合整體風險
            $portfolioVolatility = sqrt(array_sum(array_map(
                fn($pos) => pow($pos['volatility'] * $pos['weight'], 2),
                $portfolioRisk
            )));

            return response()->json([
                'success' => true,
                'data' => [
                    'portfolio' => $portfolioRisk,
                    'overall_risk' => [
                        'volatility' => round($portfolioVolatility, 4),
                        'volatility_percentage' => round($portfolioVolatility * 100, 2) . '%',
                    ],
                    'period_days' => $period,
                    'generated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('生成風險報告失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '生成報告失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 生成自訂報表
     * 
     * POST /api/reports/generate
     */
    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'report_type' => 'required|in:daily,monthly,performance,risk,custom',
            'parameters' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $reportType = $request->input('report_type');
            $parameters = $request->input('parameters');

            // 根據報表類型生成對應的報表
            $reportData = match ($reportType) {
                'daily' => $this->daily(new Request($parameters)),
                'monthly' => $this->monthly(new Request($parameters)),
                'performance' => $this->performance(new Request($parameters)),
                'risk' => $this->risk(new Request($parameters)),
                default => throw new \Exception('不支援的報表類型'),
            };

            return response()->json([
                'success' => true,
                'message' => '報表生成成功',
                'data' => $reportData->getData()
            ]);

        } catch (\Exception $e) {
            Log::error('生成報表失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '生成報表失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 匯出報表
     * 
     * GET /api/reports/export/{type}
     */
    public function export(Request $request, string $type): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'format' => 'required|in:pdf,xlsx,csv',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $format = $request->input('format');

            // 這裡應該實作實際的報表匯出邏輯
            // 使用如 Laravel Excel, DomPDF 等套件

            return response()->json([
                'success' => true,
                'message' => '報表匯出功能開發中',
                'data' => [
                    'type' => $type,
                    'format' => $format,
                    'note' => '請安裝 maatwebsite/excel 或 barryvdh/laravel-dompdf 套件以啟用此功能'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('匯出報表失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '匯出失敗: ' . $e->getMessage()
            ], 500);
        }
    }
}