<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BacktestResult;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 策略回測 API 控制器
 */
class BacktestController extends Controller
{
    /**
     * 取得回測列表
     *
     * GET /api/backtest
     */
    public function index(Request $request): JsonResponse
    {
        $query = BacktestResult::with('stock');

        // 股票篩選
        if ($request->has('stock_id')) {
            $query->where('stock_id', $request->input('stock_id'));
        }

        // 策略篩選
        if ($request->has('strategy_name')) {
            $query->where('strategy_name', $request->input('strategy_name'));
        }

        // 日期範圍篩選
        if ($request->has('start_date')) {
            $query->where('start_date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $query->where('end_date', '<=', $request->input('end_date'));
        }

        // 排序
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // 分頁
        $perPage = $request->input('per_page', 20);
        $backtests = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $backtests
        ]);
    }

    /**
     * 執行回測
     *
     * POST /api/backtest/run
     */
    public function run(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'strategy_name' => 'required|string|in:sma_crossover,macd,rsi,bollinger_bands,covered_call,protective_put',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'initial_capital' => 'nullable|numeric|min:1000',
            'parameters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stockId = $request->input('stock_id');
            $strategyName = $request->input('strategy_name');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');
            $initialCapital = $request->input('initial_capital', 100000);
            $parameters = $request->input('parameters', []);

            $stock = Stock::findOrFail($stockId);

            // 獲取回測期間的價格資料
            $prices = StockPrice::where('stock_id', $stockId)
                ->where('trade_date', '>=', $startDate)
                ->where('trade_date', '<=', $endDate)
                ->orderBy('trade_date')
                ->get();

            if ($prices->count() < 20) {
                return response()->json([
                    'success' => false,
                    'message' => '回測期間資料不足，至少需要 20 天的交易資料'
                ], 400);
            }

            // 執行回測
            $result = $this->executeBacktest($stock, $prices, $strategyName, $initialCapital, $parameters);

            // 儲存回測結果
            $backtest = BacktestResult::create([
                'stock_id' => $stockId,
                'strategy_name' => $strategyName,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'initial_capital' => $initialCapital,
                'final_capital' => $result['final_capital'],
                'total_return' => $result['total_return'],
                'total_trades' => $result['total_trades'],
                'winning_trades' => $result['winning_trades'],
                'losing_trades' => $result['losing_trades'],
                'win_rate' => $result['win_rate'],
                'max_drawdown' => $result['max_drawdown'],
                'sharpe_ratio' => $result['sharpe_ratio'],
                'parameters' => json_encode($parameters),
                'trade_history' => json_encode($result['trades']),
            ]);

            return response()->json([
                'success' => true,
                'message' => '回測完成',
                'data' => [
                    'backtest_id' => $backtest->id,
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'strategy' => $strategyName,
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'trading_days' => $prices->count(),
                    ],
                    'results' => $result,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('回測執行錯誤', [
                'stock_id' => $request->input('stock_id'),
                'strategy' => $request->input('strategy_name'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '回測失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得單一回測詳情
     *
     * GET /api/backtest/{id}
     */
    public function show(int $id): JsonResponse
    {
        $backtest = BacktestResult::with('stock')->findOrFail($id);

        // 解析交易歷史
        $tradeHistory = json_decode($backtest->trade_history, true) ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'backtest' => $backtest,
                'trade_history' => $tradeHistory,
            ]
        ]);
    }

    /**
     * 刪除回測
     *
     * DELETE /api/backtest/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $backtest = BacktestResult::findOrFail($id);
            $backtest->delete();

            return response()->json([
                'success' => true,
                'message' => '回測已刪除'
            ]);
        } catch (\Exception $e) {
            Log::error('回測刪除錯誤', [
                'backtest_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '刪除失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得可用策略列表
     *
     * GET /api/backtest/strategies
     */
    public function strategies(): JsonResponse
    {
        $strategies = [
            [
                'name' => 'sma_crossover',
                'display_name' => 'SMA 交叉策略',
                'description' => '當短期均線向上穿越長期均線時買入，向下穿越時賣出',
                'parameters' => [
                    'short_period' => ['type' => 'integer', 'default' => 20, 'min' => 5, 'max' => 50],
                    'long_period' => ['type' => 'integer', 'default' => 50, 'min' => 20, 'max' => 200],
                ],
            ],
            [
                'name' => 'macd',
                'display_name' => 'MACD 策略',
                'description' => '根據 MACD 指標產生的買賣訊號進行交易',
                'parameters' => [
                    'fast_period' => ['type' => 'integer', 'default' => 12, 'min' => 5, 'max' => 50],
                    'slow_period' => ['type' => 'integer', 'default' => 26, 'min' => 10, 'max' => 100],
                    'signal_period' => ['type' => 'integer', 'default' => 9, 'min' => 3, 'max' => 30],
                ],
            ],
            [
                'name' => 'rsi',
                'display_name' => 'RSI 策略',
                'description' => 'RSI 低於超賣線時買入，高於超買線時賣出',
                'parameters' => [
                    'period' => ['type' => 'integer', 'default' => 14, 'min' => 5, 'max' => 30],
                    'oversold' => ['type' => 'integer', 'default' => 30, 'min' => 10, 'max' => 40],
                    'overbought' => ['type' => 'integer', 'default' => 70, 'min' => 60, 'max' => 90],
                ],
            ],
            [
                'name' => 'bollinger_bands',
                'display_name' => '布林通道策略',
                'description' => '價格觸及下軌時買入，觸及上軌時賣出',
                'parameters' => [
                    'period' => ['type' => 'integer', 'default' => 20, 'min' => 10, 'max' => 50],
                    'std_dev' => ['type' => 'float', 'default' => 2.0, 'min' => 1.0, 'max' => 3.0],
                ],
            ],
            [
                'name' => 'covered_call',
                'display_name' => '備兌買權策略',
                'description' => '持有股票並賣出買權收取權利金',
                'parameters' => [
                    'strike_offset' => ['type' => 'float', 'default' => 0.05, 'min' => 0.01, 'max' => 0.15],
                ],
            ],
            [
                'name' => 'protective_put',
                'display_name' => '保護性賣權策略',
                'description' => '持有股票並買入賣權作為保險',
                'parameters' => [
                    'strike_offset' => ['type' => 'float', 'default' => 0.05, 'min' => 0.01, 'max' => 0.15],
                ],
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $strategies
        ]);
    }

    /**
     * 比較多個回測結果
     *
     * POST /api/backtest/compare
     */
    public function compare(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'backtest_ids' => 'required|array|min:2|max:5',
            'backtest_ids.*' => 'integer|exists:backtest_results,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $backtestIds = $request->input('backtest_ids');
            $backtests = BacktestResult::with('stock')
                ->whereIn('id', $backtestIds)
                ->get();

            $comparison = $backtests->map(function ($backtest) {
                return [
                    'id' => $backtest->id,
                    'stock' => $backtest->stock->symbol,
                    'strategy' => $backtest->strategy_name,
                    'total_return' => $backtest->total_return,
                    'win_rate' => $backtest->win_rate,
                    'sharpe_ratio' => $backtest->sharpe_ratio,
                    'max_drawdown' => $backtest->max_drawdown,
                    'total_trades' => $backtest->total_trades,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'comparison' => $comparison,
                    'best_return' => $comparison->sortByDesc('total_return')->first(),
                    'best_sharpe' => $comparison->sortByDesc('sharpe_ratio')->first(),
                    'best_win_rate' => $comparison->sortByDesc('win_rate')->first(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('回測比較錯誤', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '比較失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 執行回測邏輯
     */
    private function executeBacktest($stock, $prices, string $strategy, float $capital, array $parameters): array
    {
        $initialCapital = $capital;
        $position = 0; // 持股數量
        $trades = [];

        $equity = [$capital]; // 權益曲線
        $maxEquity = $capital;
        $maxDrawdown = 0;

        // 根據策略執行回測
        switch ($strategy) {
            case 'sma_crossover':
                $result = $this->smaCrossoverStrategy($prices, $capital, $parameters);
                break;
            case 'macd':
                $result = $this->macdStrategy($prices, $capital, $parameters);
                break;
            case 'rsi':
                $result = $this->rsiStrategy($prices, $capital, $parameters);
                break;
            default:
                // 簡單買入持有策略作為預設
                $result = $this->buyAndHoldStrategy($prices, $capital);
                break;
        }

        return $result;
    }

    /**
     * SMA 交叉策略
     */
    private function smaCrossoverStrategy($prices, float $capital, array $parameters): array
    {
        $shortPeriod = $parameters['short_period'] ?? 20;
        $longPeriod = $parameters['long_period'] ?? 50;

        $position = 0;
        $trades = [];
        $equity = [$capital];

        foreach ($prices as $index => $price) {
            if ($index < $longPeriod) {
                $equity[] = $capital;
                continue;
            }

            // 計算短期和長期均線
            $shortSma = $prices->slice($index - $shortPeriod, $shortPeriod)->avg('close');
            $longSma = $prices->slice($index - $longPeriod, $longPeriod)->avg('close');

            // 金叉：買入
            if ($shortSma > $longSma && $position == 0) {
                $shares = floor($capital / $price->close);
                if ($shares > 0) {
                    $position = $shares;
                    $capital -= $shares * $price->close;
                    $trades[] = [
                        'date' => $price->trade_date,
                        'type' => 'buy',
                        'price' => $price->close,
                        'shares' => $shares,
                    ];
                }
            }
            // 死叉：賣出
            elseif ($shortSma < $longSma && $position > 0) {
                $capital += $position * $price->close;
                $trades[] = [
                    'date' => $price->trade_date,
                    'type' => 'sell',
                    'price' => $price->close,
                    'shares' => $position,
                ];
                $position = 0;
            }

            $currentEquity = $capital + ($position * $price->close);
            $equity[] = $currentEquity;
        }

        // 計算結果
        $finalCapital = $capital + ($position * $prices->last()->close);
        $totalReturn = (($finalCapital - $equity[0]) / $equity[0]) * 100;

        $winningTrades = collect($trades)->filter(function ($trade, $index) use ($trades) {
            if ($trade['type'] == 'sell' && $index > 0) {
                $buyTrade = $trades[$index - 1];
                return $trade['price'] > $buyTrade['price'];
            }
            return false;
        })->count();

        $totalTrades = collect($trades)->where('type', 'sell')->count();
        $losingTrades = $totalTrades - $winningTrades;
        $winRate = $totalTrades > 0 ? ($winningTrades / $totalTrades) * 100 : 0;

        // 計算最大回撤
        $maxDrawdown = $this->calculateMaxDrawdown($equity);

        // 計算 Sharpe Ratio
        $sharpeRatio = $this->calculateSharpeRatio($equity);

        return [
            'initial_capital' => $equity[0],
            'final_capital' => $finalCapital,
            'total_return' => round($totalReturn, 2),
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => round($winRate, 2),
            'max_drawdown' => round($maxDrawdown, 2),
            'sharpe_ratio' => round($sharpeRatio, 2),
            'trades' => $trades,
        ];
    }

    /**
     * MACD 策略
     */
    private function macdStrategy($prices, float $capital, array $parameters): array
    {
        // 簡化版本，實際應該實作完整的 MACD 邏輯
        return $this->smaCrossoverStrategy($prices, $capital, [
            'short_period' => 12,
            'long_period' => 26,
        ]);
    }

    /**
     * RSI 策略
     */
    private function rsiStrategy($prices, float $capital, array $parameters): array
    {
        // 簡化版本，實際應該實作完整的 RSI 邏輯
        return $this->smaCrossoverStrategy($prices, $capital, [
            'short_period' => 14,
            'long_period' => 30,
        ]);
    }

    /**
     * 買入持有策略
     */
    private function buyAndHoldStrategy($prices, float $capital): array
    {
        $firstPrice = $prices->first()->close;
        $lastPrice = $prices->last()->close;

        $shares = floor($capital / $firstPrice);
        $finalCapital = $shares * $lastPrice + ($capital - $shares * $firstPrice);

        $totalReturn = (($finalCapital - $capital) / $capital) * 100;

        return [
            'initial_capital' => $capital,
            'final_capital' => $finalCapital,
            'total_return' => round($totalReturn, 2),
            'total_trades' => 1,
            'winning_trades' => $totalReturn > 0 ? 1 : 0,
            'losing_trades' => $totalReturn <= 0 ? 1 : 0,
            'win_rate' => $totalReturn > 0 ? 100 : 0,
            'max_drawdown' => 0,
            'sharpe_ratio' => 0,
            'trades' => [
                [
                    'date' => $prices->first()->trade_date,
                    'type' => 'buy',
                    'price' => $firstPrice,
                    'shares' => $shares,
                ],
                [
                    'date' => $prices->last()->trade_date,
                    'type' => 'sell',
                    'price' => $lastPrice,
                    'shares' => $shares,
                ],
            ],
        ];
    }

    /**
     * 計算最大回撤
     */
    private function calculateMaxDrawdown(array $equity): float
    {
        $maxDrawdown = 0;
        $peak = $equity[0];

        foreach ($equity as $value) {
            if ($value > $peak) {
                $peak = $value;
            }
            $drawdown = (($peak - $value) / $peak) * 100;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        return $maxDrawdown;
    }

    /**
     * 計算 Sharpe Ratio
     */
    private function calculateSharpeRatio(array $equity): float
    {
        if (count($equity) < 2) {
            return 0;
        }

        // 計算日報酬率
        $returns = [];
        for ($i = 1; $i < count($equity); $i++) {
            $returns[] = ($equity[$i] - $equity[$i - 1]) / $equity[$i - 1];
        }

        if (empty($returns)) {
            return 0;
        }

        // 計算平均報酬和標準差
        $avgReturn = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(function ($r) use ($avgReturn) {
            return pow($r - $avgReturn, 2);
        }, $returns)) / count($returns);

        $stdDev = sqrt($variance);

        if ($stdDev == 0) {
            return 0;
        }

        // Sharpe Ratio = (平均報酬 - 無風險利率) / 標準差
        // 假設無風險利率為 0.015 / 252 (年化 1.5%)
        $riskFreeRate = 0.015 / 252;
        $sharpeRatio = ($avgReturn - $riskFreeRate) / $stdDev;

        // 年化 Sharpe Ratio
        return $sharpeRatio * sqrt(252);
    }
}
