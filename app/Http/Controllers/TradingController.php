<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\TradeHistory;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 交易管理 API 控制器
 *
 * 處理持倉、交易歷史、帳戶等功能
 * 注意：此為模擬交易系統，實際交易請對接券商 API
 */
class TradingController extends Controller
{
    // ==========================================
    // 手續費/稅率設定
    // ==========================================
    private const COMMISSION_RATE   = 0.001425; // 0.1425%
    private const STOCK_TAX_RATE    = 0.003;    // 0.3% (賣出)
    private const OPTION_COMMISSION = 50;        // 選擇權每口固定 $50

    /**
     * 取得持倉列表
     *
     * GET /api/trading/positions
     */
    public function positions(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            // 從 positions 資料表查詢有效持倉，並補上最新市價
            $positions = Position::where('user_id', $userId)
                ->active()
                ->orderBy('type')
                ->orderBy('symbol')
                ->get()
                ->map(function (Position $pos) {
                    // 嘗試從 stock_prices 取得最新收盤價更新市值
                    if ($pos->type === 'stock') {
                        $latestPrice = StockPrice::whereHas('stock', fn($q) => $q->where('symbol', $pos->symbol))
                            ->orderBy('trade_date', 'desc')
                            ->value('close');

                        if ($latestPrice) {
                            $pos->refreshPnl((float) $latestPrice);
                        }
                    }

                    return [
                        'id'                      => $pos->id,
                        'symbol'                  => $pos->symbol,
                        'name'                    => $pos->name,
                        'type'                    => $pos->type,
                        'quantity'                => $pos->quantity,
                        'avg_price'               => (float) $pos->avg_price,
                        'current_price'           => (float) $pos->current_price,
                        'market_value'            => (float) $pos->market_value,
                        'cost'                    => (float) $pos->cost,
                        'unrealized_pnl'          => (float) $pos->unrealized_pnl,
                        'unrealized_pnl_percent'  => (float) $pos->unrealized_pnl_percent,
                        'expiry_date'             => $pos->expiry_date?->toDateString(),
                        'updated_at'              => $pos->updated_at->toIso8601String(),
                    ];
                });

            // 彙總
            $totalValue   = $positions->sum('market_value');
            $totalCost    = $positions->sum('cost');
            $totalPnl     = $totalValue - $totalCost;
            $totalPnlPct  = $totalCost > 0 ? ($totalPnl / $totalCost) * 100 : 0;

            return response()->json([
                'success' => true,
                'data'    => [
                    'positions' => $positions->values(),
                    'summary'   => [
                        'total_positions'         => $positions->count(),
                        'total_market_value'      => round($totalValue, 2),
                        'total_cost'              => round($totalCost, 2),
                        'total_unrealized_pnl'    => round($totalPnl, 2),
                        'total_unrealized_pnl_percent' => round($totalPnlPct, 2),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('取得持倉失敗', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 取得交易歷史
     *
     * GET /api/trading/history
     */
    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'symbol'     => 'nullable|string|max:30',
            'type'       => 'nullable|in:stock,option',
            'order_type' => 'nullable|in:buy,sell',
            'per_page'   => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $userId  = $request->user()->id;
            $perPage = $request->input('per_page', 50);

            $query = TradeHistory::where('user_id', $userId)
                ->orderBy('trade_date', 'desc')
                ->orderBy('id', 'desc');

            // 篩選條件
            if ($request->filled('start_date')) {
                $query->where('trade_date', '>=', $request->input('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->where('trade_date', '<=', $request->input('end_date'));
            }
            if ($request->filled('symbol')) {
                $query->where('symbol', $request->input('symbol'));
            }
            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }
            if ($request->filled('order_type')) {
                $query->where('order_type', $request->input('order_type'));
            }

            $paginated = $query->paginate($perPage);

            // 彙總實現損益
            $totalRealizedPnl = TradeHistory::where('user_id', $userId)
                ->whereNotNull('realized_pnl')
                ->sum('realized_pnl');

            return response()->json([
                'success' => true,
                'data'    => [
                    'history'            => $paginated->items(),
                    'total'              => $paginated->total(),
                    'per_page'           => $paginated->perPage(),
                    'current_page'       => $paginated->currentPage(),
                    'last_page'          => $paginated->lastPage(),
                    'total_realized_pnl' => round((float) $totalRealizedPnl, 2),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('取得交易歷史失敗', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 取得帳戶資訊
     *
     * GET /api/trading/account
     */
    public function account(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            // 從持倉計算總市值
            $positions = Position::where('user_id', $userId)->active()->get();

            $totalMarketValue = $positions->sum('market_value');
            $totalCost        = $positions->sum('cost');
            $totalUnrealizedPnl = $totalMarketValue - $totalCost;

            // 從交易歷史計算實現損益
            $totalRealizedPnl = TradeHistory::where('user_id', $userId)
                ->whereNotNull('realized_pnl')
                ->sum('realized_pnl');

            return response()->json([
                'success' => true,
                'data'    => [
                    'user_id'             => $userId,
                    'total_positions'     => $positions->count(),
                    'total_market_value'  => round((float) $totalMarketValue, 2),
                    'total_cost'          => round((float) $totalCost, 2),
                    'total_unrealized_pnl'=> round((float) $totalUnrealizedPnl, 2),
                    'total_realized_pnl'  => round((float) $totalRealizedPnl, 2),
                    'updated_at'          => now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('取得帳戶資訊失敗', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 新增交易紀錄（模擬下單）
     *
     * POST /api/trading/order
     */
    public function order(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbol'     => 'required|string|max:30',
            'name'       => 'required|string|max:100',
            'type'       => 'required|in:stock,option',
            'order_type' => 'required|in:buy,sell',
            'quantity'   => 'required|integer|min:1',
            'price'      => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $userId    = $request->user()->id;
            $symbol    = $request->input('symbol');
            $name      = $request->input('name');
            $type      = $request->input('type');
            $orderType = $request->input('order_type');
            $quantity  = (int) $request->input('quantity');
            $price     = (float) $request->input('price');

            // 計算費用
            $amount     = $price * $quantity;
            $commission = $type === 'option'
                ? self::OPTION_COMMISSION * $quantity
                : ceil($amount * self::COMMISSION_RATE);
            $tax = $orderType === 'sell' && $type === 'stock'
                ? ceil($amount * self::STOCK_TAX_RATE)
                : 0;
            $netAmount = $orderType === 'buy'
                ? $amount + $commission + $tax
                : $amount - $commission - $tax;

            // ---- 更新持倉 ----
            $position = Position::firstOrNew([
                'user_id' => $userId,
                'symbol'  => $symbol,
                'type'    => $type,
            ]);

            $realizedPnl    = null;
            $realizedPnlPct = null;

            if ($orderType === 'buy') {
                // 加倉：重新計算平均成本
                $newQty      = ($position->quantity ?? 0) + $quantity;
                $newCost     = ($position->cost ?? 0) + $amount;
                $newAvgPrice = $newCost / $newQty;

                $position->fill([
                    'name'          => $name,
                    'quantity'      => $newQty,
                    'avg_price'     => $newAvgPrice,
                    'cost'          => $newCost,
                    'current_price' => $price,
                    'market_value'  => $price * $newQty,
                    'is_active'     => true,
                ]);
                $position->save();

            } else {
                // 減倉/平倉
                if (($position->quantity ?? 0) < $quantity) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => '持倉數量不足，無法賣出',
                    ], 422);
                }

                $realizedPnl = ($price - $position->avg_price) * $quantity;
                $realizedPnlPct = $position->avg_price > 0
                    ? ($realizedPnl / ($position->avg_price * $quantity)) * 100
                    : 0;

                $newQty  = $position->quantity - $quantity;
                $newCost = $position->avg_price * $newQty;

                $position->fill([
                    'quantity'      => $newQty,
                    'cost'          => $newCost,
                    'current_price' => $price,
                    'market_value'  => $price * $newQty,
                    'is_active'     => $newQty > 0,
                ]);
                $position->save();
            }

            // ---- 寫入交易歷史 ----
            $trade = TradeHistory::create([
                'user_id'              => $userId,
                'symbol'               => $symbol,
                'name'                 => $name,
                'type'                 => $type,
                'order_type'           => $orderType,
                'trade_date'           => now()->toDateString(),
                'quantity'             => $quantity,
                'price'                => $price,
                'amount'               => $amount,
                'commission'           => $commission,
                'tax'                  => $tax,
                'net_amount'           => $netAmount,
                'realized_pnl'         => $realizedPnl,
                'realized_pnl_percent' => $realizedPnlPct,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '交易成功',
                'data'    => [
                    'trade_id'   => $trade->id,
                    'symbol'     => $symbol,
                    'order_type' => $orderType,
                    'quantity'   => $quantity,
                    'price'      => $price,
                    'amount'     => $amount,
                    'commission' => $commission,
                    'tax'        => $tax,
                    'net_amount' => $netAmount,
                    'realized_pnl' => $realizedPnl,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('下單失敗', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => '下單失敗: ' . $e->getMessage(),
            ], 500);
        }
    }
}
