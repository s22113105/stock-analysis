<?php

namespace App\Http\Controllers;

use App\Models\Option;
use App\Models\OptionPrice;
use App\Services\OptionAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 選擇權資料 API 控制器
 * 處理選擇權相關的所有 API 請求 (防呆增強版)
 */
class OptionController extends Controller
{
    protected $optionAnalysisService;

    public function __construct(OptionAnalysisService $optionAnalysisService)
    {
        $this->optionAnalysisService = $optionAnalysisService;
    }

    /**
     * [關鍵修改] 統一回傳格式的輔助方法
     * 確保無論 Service 回傳什麼，前端都能收到標準格式
     */
    private function sendResponse($result): JsonResponse
    {
        // 判斷 Service 是否已經包裝過
        $data = isset($result['data']) ? $result['data'] : $result;
        $success = isset($result['success']) ? $result['success'] : true;

        // 強制回傳標準結構
        return response()->json([
            'success' => $success,
            'data' => $data
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Option::query();
        if ($request->has('underlying')) $query->where('underlying', $request->input('underlying'));
        if ($request->has('option_type')) $query->where('option_type', $request->input('option_type'));
        if ($request->has('expiry_date')) $query->where('expiry_date', $request->input('expiry_date'));
        if ($request->input('active_only', true)) $query->where('is_active', true);

        $options = $query->with(['latestPrice'])->paginate($request->input('per_page', 20));

        // 使用統一回應方法
        return $this->sendResponse($options);
    }

    public function show(int $id): JsonResponse
    {
        $option = Option::with(['latestPrice', 'prices' => fn($q) => $q->orderBy('trade_date', 'desc')->limit(30)])->findOrFail($id);
        return $this->sendResponse($option);
    }

    public function chain(Request $request, string $underlying): JsonResponse
    {
        $query = Option::where('underlying', $underlying)->where('is_active', true)->with('latestPrice');
        if ($request->has('expiry_date')) $query->where('expiry_date', $request->input('expiry_date'));
        return $this->sendResponse($query->orderBy('strike_price')->get());
    }

    // ==========================================
    // TXO 分析相關端點 (全部改用 sendResponse)
    // ==========================================

    public function txoTrend(Request $request): JsonResponse
    {
        try {
            $days = $request->input('days', 30);
            $result = $this->optionAnalysisService->getTxoTrend($days);
            return $this->sendResponse($result);
        } catch (\Exception $e) {
            Log::error('TXO 走勢失敗', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function txoVolumeAnalysis(Request $request): JsonResponse
    {
        try {
            $result = $this->optionAnalysisService->getVolumeAnalysis($request->input('date'));
            return $this->sendResponse($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function txoOiAnalysis(Request $request): JsonResponse
    {
        try {
            $result = $this->optionAnalysisService->getOiAnalysis($request->input('date'));
            return $this->sendResponse($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function txoIvAnalysis(Request $request): JsonResponse
    {
        try {
            $result = $this->optionAnalysisService->getIvAnalysis($request->input('days', 30));
            return $this->sendResponse($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function txoSentiment(Request $request): JsonResponse
    {
        try {
            $result = $this->optionAnalysisService->getSentiment($request->input('date'));
            return $this->sendResponse($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function txoOiDistribution(Request $request): JsonResponse
    {
        try {
            $result = $this->optionAnalysisService->getOiDistribution($request->input('date'));
            return $this->sendResponse($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
