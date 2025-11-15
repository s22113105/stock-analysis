<?php

namespace App\Http\Controllers;

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
            $query = Option::where('underlying', $stock->symbol)
                ->where('is_active', true);

            // 到期日篩選
            if ($request->has('expiry_date')) {
                $query->where('expiry_date', $request->input('expiry_date'));
            } else {
                // 預設取得最近的到期日
                $nearestExpiry = Option::where('underlying', $stock->symbol)
                    ->where('is_active', true)
                    ->where('expiry_date', '>=', now()->format('Y-m-d'))
                    ->min('expiry_date');

                if ($nearestExpiry) {
                    $query->where('expiry_date', $nearestExpiry);
                }
            }

            // 選擇權類型篩選
            $optionType = $request->input('option_type', 'both');
            if ($optionType !== 'both') {
                $query->where('option_type', $optionType);
            }

            $options = $query->with('latestPrice')
                ->orderBy('strike_price')
                ->get();

            // 組織選擇權鏈資料
            $chainData = [];
            $strikes = $options->pluck('strike_price')->unique()->sort()->values();

            foreach ($strikes as $strike) {
                $callOption = $options->where('strike_price', $strike)
                    ->where('option_type', 'call')
                    ->first();

                $putOption = $options->where('strike_price', $strike)
                    ->where('option_type', 'put')
                    ->first();

                $chainData[] = [
                    'strike_price' => $strike,
                    'call' => $callOption ? [
                        'id' => $callOption->id,
                        'option_code' => $callOption->option_code,
                        'price' => $callOption->latestPrice?->close,
                        'volume' => $callOption->latestPrice?->volume,
                        'open_interest' => $callOption->latestPrice?->open_interest,
                        'implied_volatility' => $callOption->latestPrice?->implied_volatility,
                    ] : null,
                    'put' => $putOption ? [
                        'id' => $putOption->id,
                        'option_code' => $putOption->option_code,
                        'price' => $putOption->latestPrice?->close,
                        'volume' => $putOption->latestPrice?->volume,
                        'open_interest' => $putOption->latestPrice?->open_interest,
                        'implied_volatility' => $putOption->latestPrice?->implied_volatility,
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
                        'current_price' => $stock->latestPrice?->close,
                    ],
                    'chain' => $chainData,
                    'expiry_date' => $options->first()?->expiry_date,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得選擇權鏈失敗', [
                'stock_id' => $stockId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得選擇權價格歷史
     *
     * GET /api/options/{id}/prices
     */
    public function prices(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:1d,1w,1m,3m,6m,all',
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
            $option = Option::findOrFail($id);

            $query = OptionPrice::where('option_id', $id);

            // 根據 period 參數設定日期範圍
            if ($request->has('period') && $request->input('period') !== 'all') {
                $period = $request->input('period');
                $startDate = $this->getDateRange($period);
                $query->where('trade_date', '>=', $startDate->format('Y-m-d'));
            }

            // 自訂日期範圍
            if ($request->has('start_date')) {
                $query->where('trade_date', '>=', $request->input('start_date'));
            }

            if ($request->has('end_date')) {
                $query->where('trade_date', '<=', $request->input('end_date'));
            }

            $prices = $query->orderBy('trade_date', 'asc')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'option' => [
                        'id' => $option->id,
                        'option_code' => $option->option_code,
                        'underlying' => $option->underlying,
                        'strike_price' => $option->strike_price,
                        'expiry_date' => $option->expiry_date,
                        'option_type' => $option->option_type,
                    ],
                    'prices' => $prices,
                    'count' => $prices->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得選擇權價格歷史失敗', [
                'option_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 即將到期的選擇權
     *
     * GET /api/options/expiring
     */
    public function expiring(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:90',
            'underlying' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $days = $request->input('days', 7);
            $expiryDate = now()->addDays($days)->format('Y-m-d');

            $query = Option::where('is_active', true)
                ->where('expiry_date', '<=', $expiryDate)
                ->where('expiry_date', '>=', now()->format('Y-m-d'));

            // 標的篩選
            if ($request->has('underlying')) {
                $query->where('underlying', $request->input('underlying'));
            }

            $options = $query->with(['latestPrice', 'stock'])
                ->orderBy('expiry_date', 'asc')
                ->orderBy('strike_price', 'asc')
                ->get();

            // 按到期日分組
            $grouped = $options->groupBy('expiry_date')->map(function ($group, $date) {
                return [
                    'expiry_date' => $date,
                    'days_until_expiry' => Carbon::parse($date)->diffInDays(now()),
                    'options' => $group->map(function ($option) {
                        return [
                            'id' => $option->id,
                            'option_code' => $option->option_code,
                            'underlying' => $option->underlying,
                            'strike_price' => $option->strike_price,
                            'option_type' => $option->option_type,
                            'latest_price' => $option->latestPrice,
                        ];
                    }),
                    'count' => $group->count(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $grouped,
                'total_count' => $options->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('取得即將到期選擇權失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 進階篩選選擇權
     *
     * POST /api/options/filter
     */
    public function filter(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'underlying' => 'nullable|string',
            'option_type' => 'nullable|in:call,put',
            'expiry_date_from' => 'nullable|date',
            'expiry_date_to' => 'nullable|date',
            'strike_price_min' => 'nullable|numeric|min:0',
            'strike_price_max' => 'nullable|numeric',
            'moneyness' => 'nullable|in:ITM,ATM,OTM',
            'volume_min' => 'nullable|integer|min:0',
            'open_interest_min' => 'nullable|integer|min:0',
            'implied_volatility_min' => 'nullable|numeric|min:0',
            'implied_volatility_max' => 'nullable|numeric',
            'sort_by' => 'nullable|in:expiry_date,strike_price,volume,open_interest,implied_volatility',
            'sort_order' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
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

            // 標的篩選
            if ($request->has('underlying')) {
                $query->where('underlying', $request->input('underlying'));
            }

            // 選擇權類型篩選
            if ($request->has('option_type')) {
                $query->where('option_type', $request->input('option_type'));
            }

            // 到期日範圍篩選
            if ($request->has('expiry_date_from')) {
                $query->where('expiry_date', '>=', $request->input('expiry_date_from'));
            }

            if ($request->has('expiry_date_to')) {
                $query->where('expiry_date', '<=', $request->input('expiry_date_to'));
            }

            // 履約價範圍篩選
            if ($request->has('strike_price_min')) {
                $query->where('strike_price', '>=', $request->input('strike_price_min'));
            }

            if ($request->has('strike_price_max')) {
                $query->where('strike_price', '<=', $request->input('strike_price_max'));
            }

            // 價性篩選 (需要標的價格)
            if ($request->has('moneyness') && $request->has('underlying')) {
                $moneyness = $request->input('moneyness');
                $underlying = $request->input('underlying');

                // 取得標的最新價格
                $stock = Stock::where('symbol', $underlying)->first();
                if ($stock && $stock->latestPrice) {
                    $spotPrice = $stock->latestPrice->close;

                    switch ($moneyness) {
                        case 'ITM': // In the Money
                            $query->where(function ($q) use ($spotPrice) {
                                $q->where(function ($q2) use ($spotPrice) {
                                    // Call ITM: 履約價 < 現價
                                    $q2->where('option_type', 'call')
                                        ->where('strike_price', '<', $spotPrice);
                                })->orWhere(function ($q2) use ($spotPrice) {
                                    // Put ITM: 履約價 > 現價
                                    $q2->where('option_type', 'put')
                                        ->where('strike_price', '>', $spotPrice);
                                });
                            });
                            break;

                        case 'ATM': // At the Money (現價 ± 5%)
                            $range = $spotPrice * 0.05;
                            $query->whereBetween('strike_price', [
                                $spotPrice - $range,
                                $spotPrice + $range
                            ]);
                            break;

                        case 'OTM': // Out of the Money
                            $query->where(function ($q) use ($spotPrice) {
                                $q->where(function ($q2) use ($spotPrice) {
                                    // Call OTM: 履約價 > 現價
                                    $q2->where('option_type', 'call')
                                        ->where('strike_price', '>', $spotPrice);
                                })->orWhere(function ($q2) use ($spotPrice) {
                                    // Put OTM: 履約價 < 現價
                                    $q2->where('option_type', 'put')
                                        ->where('strike_price', '<', $spotPrice);
                                });
                            });
                            break;
                    }
                }
            }

            // 關聯最新價格並篩選
            $query->with('latestPrice');

            // 成交量篩選
            if ($request->has('volume_min')) {
                $query->whereHas('latestPrice', function ($q) use ($request) {
                    $q->where('volume', '>=', $request->input('volume_min'));
                });
            }

            // 未平倉量篩選
            if ($request->has('open_interest_min')) {
                $query->whereHas('latestPrice', function ($q) use ($request) {
                    $q->where('open_interest', '>=', $request->input('open_interest_min'));
                });
            }

            // 隱含波動率範圍篩選
            if ($request->has('implied_volatility_min')) {
                $query->whereHas('latestPrice', function ($q) use ($request) {
                    $q->where('implied_volatility', '>=', $request->input('implied_volatility_min'));
                });
            }

            if ($request->has('implied_volatility_max')) {
                $query->whereHas('latestPrice', function ($q) use ($request) {
                    $q->where('implied_volatility', '<=', $request->input('implied_volatility_max'));
                });
            }

            // 排序
            $sortBy = $request->input('sort_by', 'expiry_date');
            $sortOrder = $request->input('sort_order', 'asc');

            if (in_array($sortBy, ['volume', 'open_interest', 'implied_volatility'])) {
                // 如果排序欄位在 latestPrice 關聯中
                $query->leftJoin('option_prices as latest', function ($join) {
                    $join->on('options.id', '=', 'latest.option_id')
                        ->whereRaw('latest.trade_date = (SELECT MAX(trade_date) FROM option_prices WHERE option_id = options.id)');
                })->orderBy('latest.' . $sortBy, $sortOrder);
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // 分頁
            $perPage = $request->input('per_page', 20);
            $options = $query->select('options.*')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $options,
                'filters_applied' => array_filter($request->only([
                    'underlying',
                    'option_type',
                    'expiry_date_from',
                    'expiry_date_to',
                    'strike_price_min',
                    'strike_price_max',
                    'moneyness',
                    'volume_min',
                    'open_interest_min',
                    'implied_volatility_min',
                    'implied_volatility_max'
                ])),
            ]);
        } catch (\Exception $e) {
            Log::error('篩選選擇權失敗', [
                'filters' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '篩選失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算時間範圍
     */
    private function getDateRange($period)
    {
        return match ($period) {
            '1d' => now()->subDay(),
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            default => now()->subMonth(),
        };
    }
}
