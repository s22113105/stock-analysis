<?php

namespace App\Http\Controllers;

use App\Models\Option;
use App\Models\OptionPrice;
use App\Services\TxoAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 選擇權資料 API 控制器
 *
 * 處理選擇權相關的所有 API 請求
 */
class OptionController extends Controller
{
    protected $txoAnalysisService;

    public function __construct(TxoAnalysisService $txoAnalysisService)
    {
        $this->txoAnalysisService = $txoAnalysisService;
    }

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
        $option = Option::with(['latestPrice', 'prices' => function ($query) {
            $query->orderBy('trade_date', 'desc')->limit(30);
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $option
        ]);
    }

    /**
     * 取得選擇權鏈 (Option Chain)
     * GET /api/options/chain/{underlying}
     */
    public function chain(Request $request, string $underlying): JsonResponse
    {
        $expiryDate = $request->input('expiry_date');

        $query = Option::where('underlying', $underlying)
            ->where('is_active', true)
            ->with('latestPrice');

        if ($expiryDate) {
            $query->where('expiry_date', $expiryDate);
        }

        $options = $query->orderBy('strike_price')->get();

        return response()->json([
            'success' => true,
            'data' => $options
        ]);
    }

    // ==========================================
    // TXO 分析相關端點
    // ==========================================

    /**
     * 取得 TXO 走勢資料
     * GET /api/options/txo/trend
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function txoTrend(Request $request): JsonResponse
    {
        try {
            $days = $request->input('days', 30);
            $strikePrice = $request->input('strike_price');

            $data = $this->txoAnalysisService->getTrend($days, $strikePrice);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('TXO 走勢資料取得失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得 TXO 走勢資料失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得 TXO 成交量分析
     * GET /api/options/txo/volume-analysis
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function txoVolumeAnalysis(Request $request): JsonResponse
    {
        try {
            $date = $request->input('date');
            $data = $this->txoAnalysisService->getVolumeAnalysis($date);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('TXO 成交量分析失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得成交量分析失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得 TXO 未平倉量(OI)分析
     * GET /api/options/txo/oi-analysis
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function txoOiAnalysis(Request $request): JsonResponse
    {
        try {
            $date = $request->input('date');
            $data = $this->txoAnalysisService->getOiAnalysis($date);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('TXO OI 分析失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得 OI 分析失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得 TXO 隱含波動率(IV)分析
     * GET /api/options/txo/iv-analysis
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function txoIvAnalysis(Request $request): JsonResponse
    {
        try {
            $date = $request->input('date');
            $data = $this->txoAnalysisService->getIvAnalysis($date);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('TXO IV 分析失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得 IV 分析失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得 TXO 市場情緒總覽
     * GET /api/options/txo/sentiment
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function txoSentiment(Request $request): JsonResponse
    {
        try {
            $date = $request->input('date');
            $data = $this->txoAnalysisService->getSentiment($date);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('TXO 市場情緒分析失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得市場情緒失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算日期範圍
     */
    private function getDateRange(string $period): string
    {
        return match ($period) {
            '1w' => now()->subWeek()->format('Y-m-d'),
            '1m' => now()->subMonth()->format('Y-m-d'),
            '3m' => now()->subMonths(3)->format('Y-m-d'),
            '6m' => now()->subMonths(6)->format('Y-m-d'),
            '1y' => now()->subYear()->format('Y-m-d'),
            default => now()->subMonth()->format('Y-m-d'),
        };
    }
}
