<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 交易管理 API 控制器
 * 
 * 處理持倉、訂單等交易相關功能
 * 注意：此為模擬交易系統，實際交易請對接券商 API
 */
class TradingController extends Controller
{
    /**
     * 取得持倉列表
     * 
     * GET /api/trading/positions
     */
    public function positions(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id ?? 1; // 臨時使用固定 ID

            // 從資料庫或快取取得持倉資料
            // 實際專案中應該有 positions 資料表
            $positions = [
                [
                    'id' => 1,
                    'symbol' => '2330',
                    'name' => '台積電',
                    'type' => 'stock',
                    'quantity' => 1000,
                    'avg_price' => 585.0,
                    'current_price' => 615.0,
                    'market_value' => 615000,
                    'cost' => 585000,
                    'unrealized_pnl' => 30000,
                    'unrealized_pnl_percent' => 5.13,
                    'updated_at' => now()->toIso8601String(),
                ],
                [
                    'id' => 2,
                    'symbol' => 'TXO_2025_01_C_20000',
                    'name' => '台指選擇權 買權 20000',
                    'type' => 'option',
                    'quantity' => 5,
                    'avg_price' => 250.0,
                    'current_price' => 320.0,
                    'market_value' => 80000,
                    'cost' => 62500,
                    'unrealized_pnl' => 17500,
                    'unrealized_pnl_percent' => 28.0,
                    'expiry_date' => '2025-01-15',
                    'updated_at' => now()->toIso8601String(),
                ],
            ];

            // 計算總損益
            $totalValue = array_sum(array_column($positions, 'market_value'));
            $totalCost = array_sum(array_column($positions, 'cost'));
            $totalPnl = $totalValue - $totalCost;
            $totalPnlPercent = ($totalPnl / $totalCost) * 100;

            return response()->json([
                'success' => true,
                'data' => [
                    'positions' => $positions,
                    'summary' => [
                        'total_positions' => count($positions),
                        'total_market_value' => $totalValue,
                        'total_cost' => $totalCost,
                        'total_unrealized_pnl' => $totalPnl,
                        'total_unrealized_pnl_percent' => round($totalPnlPercent, 2),
                    ],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('取得持倉失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得訂單列表
     * 
     * GET /api/trading/orders
     */
    public function orders(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:all,pending,filled,cancelled',
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
            $userId = $request->user()->id ?? 1;
            $status = $request->input('status', 'all');

            // 模擬訂單資料
            // 實際專案中應該有 orders 資料表
            $orders = [
                [
                    'id' => 1,
                    'order_type' => 'buy',
                    'symbol' => '2330',
                    'name' => '台積電',
                    'quantity' => 1000,
                    'price' => 585.0,
                    'status' => 'filled',
                    'filled_quantity' => 1000,
                    'filled_price' => 585.0,
                    'order_time' => now()->subDays(5)->toIso8601String(),
                    'filled_time' => now()->subDays(5)->addHours(1)->toIso8601String(),
                ],
                [
                    'id' => 2,
                    'order_type' => 'buy',
                    'symbol' => 'TXO_2025_01_C_20000',
                    'name' => '台指選擇權 買權 20000',
                    'quantity' => 5,
                    'price' => 250.0,
                    'status' => 'filled',
                    'filled_quantity' => 5,
                    'filled_price' => 250.0,
                    'order_time' => now()->subDays(3)->toIso8601String(),
                    'filled_time' => now()->subDays(3)->addHours(2)->toIso8601String(),
                ],
                [
                    'id' => 3,
                    'order_type' => 'sell',
                    'symbol' => '2317',
                    'name' => '鴻海',
                    'quantity' => 2000,
                    'price' => 105.0,
                    'status' => 'pending',
                    'filled_quantity' => 0,
                    'filled_price' => null,
                    'order_time' => now()->subHours(2)->toIso8601String(),
                    'filled_time' => null,
                ],
            ];

            // 根據狀態篩選
            if ($status !== 'all') {
                $orders = array_filter($orders, fn($order) => $order['status'] === $status);
            }

            // 根據日期篩選
            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'));
                $orders = array_filter($orders, function ($order) use ($startDate) {
                    return Carbon::parse($order['order_time'])->gte($startDate);
                });
            }

            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'));
                $orders = array_filter($orders, function ($order) use ($endDate) {
                    return Carbon::parse($order['order_time'])->lte($endDate);
                });
            }

            $orders = array_values($orders); // 重新索引

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orders,
                    'total' => count($orders),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('取得訂單失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 建立訂單
     * 
     * POST /api/trading/orders
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_type' => 'required|in:buy,sell',
            'symbol' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'order_method' => 'nullable|in:limit,market,stop_limit',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userId = $request->user()->id ?? 1;
            
            // 驗證股票/選擇權存在
            $symbol = $request->input('symbol');
            $asset = Stock::where('symbol', $symbol)->first();
            
            if (!$asset) {
                $asset = Option::where('option_code', $symbol)->first();
            }

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該商品'
                ], 404);
            }

            // 建立訂單 (實際應該儲存到資料庫)
            $order = [
                'id' => rand(1000, 9999),
                'user_id' => $userId,
                'order_type' => $request->input('order_type'),
                'symbol' => $symbol,
                'quantity' => $request->input('quantity'),
                'price' => $request->input('price'),
                'order_method' => $request->input('order_method', 'limit'),
                'status' => 'pending',
                'order_time' => now()->toIso8601String(),
            ];

            Log::info('訂單建立成功', $order);

            return response()->json([
                'success' => true,
                'message' => '訂單建立成功',
                'data' => $order
            ], 201);

        } catch (\Exception $e) {
            Log::error('建立訂單失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '建立訂單失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取消訂單
     * 
     * DELETE /api/trading/orders/{id}
     */
    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        try {
            $userId = $request->user()->id ?? 1;

            // 檢查訂單是否存在且屬於該用戶 (實際應該查詢資料庫)
            // 這裡只是模擬

            Log::info('取消訂單', [
                'user_id' => $userId,
                'order_id' => $id
            ]);

            return response()->json([
                'success' => true,
                'message' => '訂單已取消',
                'data' => [
                    'order_id' => $id,
                    'status' => 'cancelled',
                    'cancelled_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('取消訂單失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取消訂單失敗: ' . $e->getMessage()
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
            $userId = $request->user()->id ?? 1;

            // 模擬帳戶資料 (實際應該從資料庫取得)
            $account = [
                'user_id' => $userId,
                'balance' => 1000000, // 現金餘額
                'buying_power' => 2000000, // 購買力
                'margin_used' => 500000, // 已使用保證金
                'total_assets' => 1500000, // 總資產
                'total_equity' => 1500000, // 淨值
                'maintenance_margin' => 300000, // 維持保證金
                'updated_at' => now()->toIso8601String(),
            ];

            return response()->json([
                'success' => true,
                'data' => $account
            ]);

        } catch (\Exception $e) {
            Log::error('取得帳戶資訊失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
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
            'end_date' => 'nullable|date',
            'symbol' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userId = $request->user()->id ?? 1;

            // 模擬交易歷史 (實際應該從資料庫取得)
            $history = [
                [
                    'id' => 1,
                    'date' => now()->subDays(5)->format('Y-m-d'),
                    'order_type' => 'buy',
                    'symbol' => '2330',
                    'name' => '台積電',
                    'quantity' => 1000,
                    'price' => 585.0,
                    'amount' => 585000,
                    'commission' => 409.5,
                    'tax' => 0,
                    'net_amount' => 585409.5,
                ],
                [
                    'id' => 2,
                    'date' => now()->subDays(3)->format('Y-m-d'),
                    'order_type' => 'buy',
                    'symbol' => 'TXO_2025_01_C_20000',
                    'name' => '台指選擇權',
                    'quantity' => 5,
                    'price' => 250.0,
                    'amount' => 62500,
                    'commission' => 50,
                    'tax' => 5,
                    'net_amount' => 62555,
                ],
            ];

            // 篩選
            if ($request->has('start_date')) {
                $startDate = $request->input('start_date');
                $history = array_filter($history, fn($h) => $h['date'] >= $startDate);
            }

            if ($request->has('end_date')) {
                $endDate = $request->input('end_date');
                $history = array_filter($history, fn($h) => $h['date'] <= $endDate);
            }

            if ($request->has('symbol')) {
                $symbol = $request->input('symbol');
                $history = array_filter($history, fn($h) => $h['symbol'] === $symbol);
            }

            $history = array_values($history);

            return response()->json([
                'success' => true,
                'data' => [
                    'history' => $history,
                    'total' => count($history),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('取得交易歷史失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算手續費和稅金
     * 
     * POST /api/trading/calculate-fees
     */
    public function calculateFees(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_type' => 'required|in:buy,sell',
            'symbol' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $orderType = $request->input('order_type');
            $quantity = $request->input('quantity');
            $price = $request->input('price');
            
            $amount = $quantity * $price;

            // 手續費 (0.1425% 打折後)
            $commissionRate = 0.001425 * 0.6; // 假設六折
            $commission = $amount * $commissionRate;

            // 證交稅 (賣出時才收，0.3%)
            $taxRate = $orderType === 'sell' ? 0.003 : 0;
            $tax = $amount * $taxRate;

            $totalFees = $commission + $tax;
            $netAmount = $orderType === 'buy' 
                ? $amount + $totalFees 
                : $amount - $totalFees;

            return response()->json([
                'success' => true,
                'data' => [
                    'amount' => $amount,
                    'commission' => round($commission, 0),
                    'tax' => round($tax, 0),
                    'total_fees' => round($totalFees, 0),
                    'net_amount' => round($netAmount, 0),
                    'rates' => [
                        'commission_rate' => $commissionRate * 100 . '%',
                        'tax_rate' => $taxRate * 100 . '%',
                    ],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算費用失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }
}