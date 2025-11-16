<?php

namespace App\Http\Controllers;

use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 選擇權資料 API 控制器 - 修正版
 */
class OptionController extends Controller
{
    /**
     * 取得選擇權列表
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

        // 移除 stock 關係，改用 latestPrice
        $options = $query->with(['latestPrice'])->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $options
        ]);
    }

    /**
     * 取得單一選擇權詳情
     * GET /api/options/{id}
     */
    public function show(int $id): JsonResponse
    {
        // 移除 stock 關係
        $option = Option::with(['latestPrice', 'prices' => function ($query) {
            $query->orderBy('trade_date', 'desc')->limit(30);
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $option
        ]);
    }

    /**
     * 取得選擇權價格歷史
     * GET /api/options/{id}/prices
     */
    public function prices(Request $request, int $id): JsonResponse
    {
        $option = Option::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:1w,1m,3m,6m,1y,all',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = OptionPrice::where('option_id', $id);

            // 日期範圍篩選
            if ($request->has('start_date')) {
                $query->where('trade_date', '>=', $request->input('start_date'));
            }

            if ($request->has('end_date')) {
                $query->where('trade_date', '<=', $request->input('end_date'));
            }

            // 預設期間篩選
            if (!$request->has('start_date') && $request->has('period')) {
                $startDate = $this->getDateRange($request->input('period'));
                $query->where('trade_date', '>=', $startDate);
            }

            $prices = $query->orderBy('trade_date', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'option' => $option,
                    'prices' => $prices,
                    'count' => $prices->count(),
                    'period' => $request->input('period', 'custom'),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('取得選擇權價格失敗', [
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
     * GET /api/options/expiring
     */
    public function expiring(Request $request): JsonResponse
    {
        $days = $request->input('days', 7);

        $options = Option::where('is_active', true)
            ->whereBetween('expiry_date', [
                now()->toDateString(),
                now()->addDays($days)->toDateString()
            ])
            ->with('latestPrice')
            ->orderBy('expiry_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'options' => $options,
                'count' => $options->count(),
                'days' => $days,
            ]
        ]);
    }

    /**
     * 進階篩選選擇權
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
            'sort_by' => 'nullable|in:expiry_date,strike_price,volume,open_interest',
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

            // 排序
            $sortBy = $request->input('sort_by', 'expiry_date');
            $sortOrder = $request->input('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // 分頁
            $perPage = $request->input('per_page', 20);
            $options = $query->with('latestPrice')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $options
            ]);
        } catch (\Exception $e) {
            Log::error('篩選選擇權失敗', [
                'error' => $e->getMessage()
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
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            default => now()->subMonth(),
        };
    }
}
