<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\TwseApiService;
use App\Jobs\FetchStockDataJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;  // 加入 Log Facade
use Carbon\Carbon;

class StockController extends Controller
{
    protected $twseApiService;

    public function __construct(TwseApiService $twseApiService)
    {
        $this->twseApiService = $twseApiService;
    }

    /**
     * 取得股票列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Stock::query();  // 使用 query() 方法

        // 產業別篩選
        if ($request->has('industry')) {
            $query->where('industry', $request->input('industry'));
        }

        // 搜尋
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // 排序
        $sortBy = $request->input('sort_by', 'symbol');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // 分頁
        $perPage = $request->input('per_page', 20);
        $stocks = $query->with('latestPrice')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $stocks
        ]);
    }

    /**
     * 取得單一股票詳情
     */
    public function show($id): JsonResponse
    {
        $stock = Stock::with(['latestPrice', 'options'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $stock
        ]);
    }

    /**
     * 匯入股票資料（觸發爬蟲）
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'symbol' => 'nullable|string',
            'date' => 'nullable|date',
            'sync' => 'nullable|boolean'
        ]);

        $symbol = $request->input('symbol');
        $date = $request->input('date', now()->format('Y-m-d'));
        $sync = $request->input('sync', false);

        try {
            $job = new FetchStockDataJob($date, $symbol);

            if ($sync) {
                // 同步執行
                dispatch($job)->onConnection('sync');
                $message = $symbol ? "股票 {$symbol} 資料已更新" : "所有股票資料已更新";
            } else {
                // 加入佇列
                dispatch($job);
                $message = $symbol ? "股票 {$symbol} 資料更新已加入佇列" : "股票資料更新已加入佇列";
            }

            Log::info($message);  // 使用 Log facade

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            Log::error('資料匯入失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '資料匯入失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算時間範圍函數
     */
    private function getDateRange($period)
    {
        return match ($period) {
            '1d' => now()->subDay(),
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            'ytd' => now()->startOfYear(),
            default => now()->subMonth(),
        };
    }
}
