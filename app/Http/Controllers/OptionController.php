<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Option;
use App\Models\OptionPrice;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 選擇權資料 API 控制器
 */
class OptionController extends Controller
{
    /**
     * 取得選擇權列表
     * 
     * GET /api/options
     */
    public function index(Request $request): JsonResponse
    {
        $query = Option::query();

        // 標的物篩選
        if ($request->has('underlying')) {
            $query->where('underlying', $request->input('underlying'));
        }

        // 選擇權類型篩選
        if ($request->has('option_type')) {
            $query->where('option_type', $request->input('option_type'));
        }

        // 到期日篩選
        if ($request->has('expiry_date')) {
            $query->where('expiry_date', $request->input('expiry_date'));
        }

        // 履約價篩選
        if ($request->has('strike_price')) {
            $query->where('strike_price', $request->input('strike_price'));
        }

        // 只顯示有效的選擇權
        if ($request->input('active_only', true)) {
            $query->where('is_active', true);
        }

        // 搜尋
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('option_code', 'like', "%{$search}%")
                    ->orWhere('underlying', 'like', "%{$search}%");
            });
        }

        // 排序
        $sortBy = $request->input('sort_by', 'expiry_date');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // 分頁
        $perPage = $request->input('per_page', 20);
        $options = $query->with(['latestPrice', 'stock'])->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $options
        ]);
    }

    /**
     * 取得單一選擇權詳情
     * 
     * GET /api/options/{id}
     */
    public function show(int $id): JsonResponse
    {
        $option = Option::with(['latestPrice', 'stock', 'prices' => function ($query) {
            $query->orderBy('trade_date', 'desc')->limit(30);
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $option
        ]);
    }

    /**
     * 取得選擇權鏈 (Option Chain)
     * 
     * GET /api/options/chain/{stockId}
     */
    public function chain(Request $request, int $stockId): JsonResponse
    {
        $stock = Stock::findOrFail($stockId);
        
        $validator = Validator::make($request->all(), [
            'expiry_date' => 'nullable|date',
            'option_type' => 'nullable|in:call,put,both',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $expiryDate = $request->input('expiry_date');
            $optionType = $request->input('option_type', 'both');

            $query = Option::where('underlying', $stock->symbol)
                ->where('is_active', true)
                ->with('latestPrice');

            if ($expiryDate) {
                $query->where('expiry_date', $expiryDate);
            } else {
                // 取得最近的到期日
                $query->where('expiry_date', '>=', now()->format('Y-m-d'))
                    ->orderBy('expiry_date')
                    ->limit(50);
            }

            if ($optionType !== 'both') {
                $query->where('option_type', $optionType);
            }

            $options = $query->orderBy('strike_price')->get();

            // 組織選擇權鏈資料
            $chain = [];
            $strikeGroups = $options->groupBy('strike_price');

            foreach ($strikeGroups as $strike => $strikeOptions) {
                $callOption = $strikeOptions->where('option_type', 'call')->first();
                $putOption = $strikeOptions->where('option_type', 'put')->first();

                $chain[] = [
                    'strike_price' => $strike,
                    'call' => $callOption ? [
                        'id' => $callOption->id,
                        'option_code' => $callOption->option_code,
                        'last_price' => $callOption->latestPrice->close ?? null,
                        'volume' => $callOption->latestPrice->volume ?? 0,
                        'open_interest' => $callOption->latestPrice->open_interest ?? 0,
                        'implied_volatility' => $callOption->latestPrice->implied_volatility ?? null,
                    ] : null,
                    'put' => $putOption ? [
                        'id' => $putOption->id,
                        'option_code' => $putOption->option_code,
                        'last_price' => $putOption->latestPrice->close ?? null,
                        'volume' => $putOption->latestPrice->volume ?? 0,
                        'open_interest' => $putOption->latestPrice->open_interest ?? 0,
                        'implied_volatility' => $putOption->latestPrice->implied_volatility ?? null,
                    ] : null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                        'current_price' => $stock->latestPrice->close ?? null,
                    ],
                    'option_chain' => $chain,
                    'expiry_date' => $expiryDate,
                    'total_strikes' => count($chain),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('選擇權鏈查詢錯誤', [
                'stock_id' => $stockId,
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
     * 取得選擇權歷史價格
     * 
     * GET /api/options/{id}/prices
     */
    public function prices(Request $request, int $id): JsonResponse
    {
        $option = Option::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'limit' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = OptionPrice::where('option_id', $id);

        if ($request->has('start_date')) {
            $query->where('trade_date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $query->where('trade_date', '<=', $request->input('end_date'));
        }

        $limit = $request->input('limit', 90);
        $prices = $query->orderBy('trade_date', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'option' => [
                    'id' => $option->id,
                    'option_code' => $option->option_code,
                    'option_type' => $option->option_type,
                    'strike_price' => $option->strike_price,
                    'expiry_date' => $option->expiry_date,
                ],
                'prices' => $prices,
                'total' => $prices->count(),
            ]
        ]);
    }

    /**
     * 取得即將到期的選擇權
     * 
     * GET /api/options/expiring
     */
    public function expiring(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);
        $expiryDate = now()->addDays($days)->format('Y-m-d');

        $options = Option::where('expiry_date', '<=', $expiryDate)
            ->where('expiry_date', '>=', now()->format('Y-m-d'))
            ->where('is_active', true)
            ->with(['latestPrice', 'stock'])
            ->orderBy('expiry_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'options' => $options,
                'expiry_within_days' => $days,
                'total' => $options->count(),
            ]
        ]);
    }

    /**
     * 篩選選擇權
     * 
     * POST /api/options/filter
     */
    public function filter(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'underlying' => 'nullable|string',
            'option_type' => 'nullable|in:call,put',
            'min_strike' => 'nullable|numeric',
            'max_strike' => 'nullable|numeric',
            'min_volume' => 'nullable|integer',
            'min_open_interest' => 'nullable|integer',
            'expiry_start' => 'nullable|date',
            'expiry_end' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = Option::query()->where('is_active', true);

            if ($request->has('underlying')) {
                $query->where('underlying', $request->input('underlying'));
            }

            if ($request->has('option_type')) {
                $query->where('option_type', $request->input('option_type'));
            }

            if ($request->has('min_strike')) {
                $query->where('strike_price', '>=', $request->input('min_strike'));
            }

            if ($request->has('max_strike')) {
                $query->where('strike_price', '<=', $request->input('max_strike'));
            }

            if ($request->has('expiry_start')) {
                $query->where('expiry_date', '>=', $request->input('expiry_start'));
            }

            if ($request->has('expiry_end')) {
                $query->where('expiry_date', '<=', $request->input('expiry_end'));
            }

            // 加入最新價格的篩選條件
            if ($request->has('min_volume') || $request->has('min_open_interest')) {
                $query->whereHas('latestPrice', function ($q) use ($request) {
                    if ($request->has('min_volume')) {
                        $q->where('volume', '>=', $request->input('min_volume'));
                    }
                    if ($request->has('min_open_interest')) {
                        $q->where('open_interest', '>=', $request->input('min_open_interest'));
                    }
                });
            }

            $options = $query->with(['latestPrice', 'stock'])
                ->orderBy('expiry_date')
                ->orderBy('strike_price')
                ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $options
            ]);

        } catch (\Exception $e) {
            Log::error('選擇權篩選錯誤', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '篩選失敗: ' . $e->getMessage()
            ], 500);
        }
    }
}