<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Option;
use App\Models\StockPrice;
use App\Models\Position;
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
            $stats = Cache::remember('dashboard_stats', 300, function () {
                return [
                    'stocks' => [
                        'total'         => Stock::count(),
                        'active'        => Stock::where('is_active', true)->count(),
                        'updated_today' => Stock::whereHas('prices', function ($q) {
                            $q->where('trade_date', now()->format('Y-m-d'));
                        })->count(),
                    ],
                    'options' => [
                        'total'          => Option::count(),
                        'active'         => Option::where('is_active', true)->count(),
                        'expiring_week'  => Option::where('is_active', true)
                            ->where('expiry_date', '<=', now()->addDays(7)->format('Y-m-d'))
                            ->where('expiry_date', '>=', now()->format('Y-m-d'))
                            ->count(),
                    ],
                    'predictions' => [
                        'total'    => Prediction::count(),
                        'today'    => Prediction::whereDate('prediction_date', today())->count(),
                        'accuracy' => $this->calculatePredictionAccuracy(),
                    ],
                    'backtests' => [
                        'total'      => BacktestResult::count(),
                        'profitable' => BacktestResult::where('total_return', '>', 0)->count(),
                        'avg_return' => BacktestResult::avg('total_return'),
                    ],
                    'system' => [
                        'last_update'    => $this->getLastUpdateTime(),
                        'data_freshness' => $this->getDataFreshness(),
                    ],
                ];
            });

            return response()->json(['success' => true, 'data' => $stats]);

        } catch (\Exception $e) {
            Log::error('儀表板統計查詢錯誤', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 取得投資組合資料（真實持倉）
     *
     * GET /api/dashboard/portfolio
     *
     * 從 positions 資料表取得登入用戶的實際持倉，
     * 並補上最新收盤價計算即時損益。
     */
    public function portfolio(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()?->id;

            // 未登入或無持倉 → 回傳空陣列，不再用假資料湊數
            if (!$userId) {
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'holdings' => [],
                        'summary'  => $this->emptyPortfolioSummary(),
                    ],
                ]);
            }

            // 取得有效持倉
            $positions = Position::where('user_id', $userId)
                ->active()
                ->orderBy('type')
                ->orderBy('symbol')
                ->get();

            // 補上最新收盤價，計算即時損益
            $holdings = $positions->map(function (Position $pos) {
                $currentPrice = $pos->current_price;
                $change       = 0.0;
                $changePct    = 0.0;

                if ($pos->type === 'stock') {
                    // 取最新兩筆收盤價計算漲跌
                    $latest2 = StockPrice::whereHas('stock', fn($q) => $q->where('symbol', $pos->symbol))
                        ->orderBy('trade_date', 'desc')
                        ->limit(2)
                        ->pluck('close');

                    if ($latest2->count() >= 2) {
                        $currentPrice = (float) $latest2[0];
                        $prevClose    = (float) $latest2[1];
                        $change       = $currentPrice - $prevClose;
                        $changePct    = $prevClose > 0 ? ($change / $prevClose) * 100 : 0;
                    } elseif ($latest2->count() === 1) {
                        $currentPrice = (float) $latest2[0];
                    }
                }

                $marketValue     = $currentPrice * $pos->quantity;
                $unrealizedPnl   = $marketValue - (float) $pos->cost;
                $unrealizedPnlPct = (float) $pos->cost > 0
                    ? ($unrealizedPnl / (float) $pos->cost) * 100
                    : 0;

                return [
                    'id'                     => $pos->id,
                    'symbol'                 => $pos->symbol,
                    'name'                   => $pos->name,
                    'type'                   => $pos->type,
                    'quantity'               => $pos->quantity,
                    'avg_price'              => (float) $pos->avg_price,
                    'current_price'          => round($currentPrice, 2),
                    'market_value'           => round($marketValue, 2),
                    'cost'                   => (float) $pos->cost,
                    'unrealized_pnl'         => round($unrealizedPnl, 2),
                    'unrealized_pnl_percent' => round($unrealizedPnlPct, 2),
                    'change'                 => round($change, 2),
                    'change_percent'         => round($changePct, 2),
                    'expiry_date'            => $pos->expiry_date?->toDateString(),
                ];
            });

            // 彙總
            $totalValue      = $holdings->sum('market_value');
            $totalCost       = $holdings->sum('cost');
            $totalUnrealized = $totalValue - $totalCost;
            $totalUnrealizedPct = $totalCost > 0
                ? ($totalUnrealized / $totalCost) * 100
                : 0;

            return response()->json([
                'success' => true,
                'data'    => [
                    'holdings' => $holdings->values(),
                    'summary'  => [
                        'total_holdings'             => $holdings->count(),
                        'total_market_value'         => round($totalValue, 2),
                        'total_cost'                 => round($totalCost, 2),
                        'total_unrealized_pnl'       => round($totalUnrealized, 2),
                        'total_unrealized_pnl_percent' => round($totalUnrealizedPct, 2),
                        'updated_at'                 => now()->toDateTimeString(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('投資組合查詢錯誤', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 取得績效資料
     *
     * GET /api/dashboard/performance
     */
    public function performance(Request $request): JsonResponse
    {
        try {
            $period = $request->input('period', 30);
            $startDate = now()->subDays($period)->format('Y-m-d');

            $backtests = BacktestResult::where('created_at', '>=', $startDate)
                ->orderBy('total_return', 'desc')
                ->limit(10)
                ->get(['strategy_name', 'total_return', 'annual_return', 'sharpe_ratio', 'max_drawdown', 'win_rate']);

            return response()->json([
                'success' => true,
                'data'    => $backtests,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 取得警示通知
     *
     * GET /api/dashboard/alerts
     */
    public function alerts(Request $request): JsonResponse
    {
        try {
            $alerts = [];

            // 即將到期的選擇權 (7 天內)
            $expiringOptions = \App\Models\Option::where('is_active', true)
                ->where('expiry_date', '<=', now()->addDays(7)->format('Y-m-d'))
                ->where('expiry_date', '>=', now()->format('Y-m-d'))
                ->limit(10)
                ->get();

            foreach ($expiringOptions as $opt) {
                $daysLeft = now()->diffInDays($opt->expiry_date);
                $alerts[] = [
                    'type'    => 'option_expiry',
                    'level'   => $daysLeft <= 3 ? 'danger' : 'warning',
                    'message' => "{$opt->option_code} 將於 {$opt->expiry_date} 到期（剩 {$daysLeft} 天）",
                ];
            }

            return response()->json([
                'success' => true,
                'data'    => ['alerts' => $alerts],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==========================================
    // Private helpers
    // ==========================================

    private function emptyPortfolioSummary(): array
    {
        return [
            'total_holdings'               => 0,
            'total_market_value'           => 0,
            'total_cost'                   => 0,
            'total_unrealized_pnl'         => 0,
            'total_unrealized_pnl_percent' => 0,
            'updated_at'                   => now()->toDateTimeString(),
        ];
    }

    private function calculatePredictionAccuracy(): float
    {
        $total = Prediction::whereNotNull('accuracy')->count();
        if ($total === 0) return 0.0;

        return round((float) Prediction::whereNotNull('accuracy')->avg('accuracy'), 2);
    }

    private function getLastUpdateTime(): ?string
    {
        $latest = StockPrice::orderBy('updated_at', 'desc')->value('updated_at');
        return $latest ? Carbon::parse($latest)->toDateTimeString() : null;
    }

    private function getDataFreshness(): string
    {
        $latestDate = StockPrice::orderBy('trade_date', 'desc')->value('trade_date');
        if (!$latestDate) return 'no_data';

        $daysDiff = now()->diffInDays(Carbon::parse($latestDate));

        if ($daysDiff === 0) return 'today';
        if ($daysDiff === 1) return 'yesterday';
        if ($daysDiff <= 3) return 'recent';
        return 'stale';
    }
}
