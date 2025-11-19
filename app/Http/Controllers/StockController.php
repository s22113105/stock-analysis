<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 股票查詢 API Controller
 * 支援篩選、排序、分頁功能
 */
class StockController extends Controller
{
    /**
     * 取得股票列表
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Stock::query();

            // ========================================
            // 修正 1: 支援 has_prices 參數
            // 只返回有價格資料的股票
            // ========================================
            if ($request->boolean('has_prices')) {
                $query->whereHas('prices', function ($q) {
                    $q->whereNotNull('close');
                });
            }

            // 搜尋功能
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('symbol', 'LIKE', "%{$search}%")
                        ->orWhere('name', 'LIKE', "%{$search}%");
                });
            }

            // 市場篩選
            if ($request->filled('market')) {
                $query->where('market', $request->input('market'));
            }

            // 產業篩選
            if ($request->filled('industry')) {
                $query->where('industry', $request->input('industry'));
            }

            // 只顯示活躍的股票
            if ($request->boolean('is_active', true)) {
                $query->where('is_active', true);
            }

            // ========================================
            // 修正 2: 載入最新價格資料
            // ========================================
            $query->with(['latestPrice']);

            // 排序
            $sortBy = $request->input('sort_by', 'symbol');
            $sortOrder = $request->input('sort_order', 'asc');

            $validSortFields = ['symbol', 'name', 'market', 'created_at'];
            if (in_array($sortBy, $validSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            }

            // 分頁
            $perPage = $request->input('per_page', 15);
            $perPage = min($perPage, 200); // 最多 200 筆

            $stocks = $query->paginate($perPage);

            // ========================================
            // 修正 3: 格式化回應資料
            // ========================================
            $stocks->getCollection()->transform(function ($stock) {
                return [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'market' => $stock->market,
                    'industry' => $stock->industry,
                    'is_active' => $stock->is_active,
                    'latest_price' => $stock->latestPrice ? [
                        'date' => $stock->latestPrice->trade_date,
                        'open' => $stock->latestPrice->open,
                        'high' => $stock->latestPrice->high,
                        'low' => $stock->latestPrice->low,
                        'close' => $stock->latestPrice->close,
                        'volume' => $stock->latestPrice->volume,
                    ] : null,
                    'created_at' => $stock->created_at,
                    'updated_at' => $stock->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $stocks,
                'message' => '成功取得股票列表'
            ]);
        } catch (\Exception $e) {
            Log::error('取得股票列表失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得股票列表失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得單一股票詳細資訊
     *
     * @param string $symbol
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($symbol)
    {
        try {
            $stock = Stock::where('symbol', $symbol)
                ->with(['latestPrice', 'prices' => function ($query) {
                    $query->orderBy('trade_date', 'desc')->limit(30);
                }])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'market' => $stock->market,
                    'industry' => $stock->industry,
                    'is_active' => $stock->is_active,
                    'latest_price' => $stock->latestPrice,
                    'price_history' => $stock->prices,
                    'created_at' => $stock->created_at,
                    'updated_at' => $stock->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得股票詳細資訊失敗', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '找不到該股票'
            ], 404);
        }
    }

    /**
     * 取得股票的歷史價格
     *
     * @param string $symbol
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function prices($symbol, Request $request)
    {
        try {
            $stock = Stock::where('symbol', $symbol)->firstOrFail();

            $query = $stock->prices();

            // 日期範圍篩選
            if ($request->filled('start_date')) {
                $query->where('trade_date', '>=', $request->input('start_date'));
            }

            if ($request->filled('end_date')) {
                $query->where('trade_date', '<=', $request->input('end_date'));
            }

            // 排序
            $query->orderBy('trade_date', 'desc');

            // 限制筆數
            $limit = $request->input('limit', 100);
            $limit = min($limit, 1000); // 最多 1000 筆

            $prices = $query->limit($limit)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'symbol' => $stock->symbol,
                        'name' => $stock->name
                    ],
                    'prices' => $prices,
                    'count' => $prices->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得股票價格歷史失敗', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得價格歷史失敗'
            ], 500);
        }
    }

    /**
     * 取得可用的股票列表（簡化版，用於下拉選單）
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function available()
    {
        try {
            $stocks = Stock::where('is_active', true)
                ->whereHas('prices')
                ->select('id', 'symbol', 'name')
                ->orderBy('symbol')
                ->get()
                ->map(function ($stock) {
                    return [
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                        'display_name' => "{$stock->symbol} - {$stock->name}"
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $stocks
            ]);
        } catch (\Exception $e) {
            Log::error('取得可用股票列表失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得可用股票列表失敗'
            ], 500);
        }
    }
}
