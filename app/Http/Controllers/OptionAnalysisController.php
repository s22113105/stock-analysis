<?php

namespace App\Http\Controllers;

use App\Services\OptionAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * 選擇權分析 API 控制器
 *
 * 提供 TXO 選擇權的各種分析 API
 */
class OptionAnalysisController extends Controller
{
    protected $analysisService;

    public function __construct(OptionAnalysisService $analysisService)
    {
        $this->analysisService = $analysisService;
    }

    /**
     * 取得 TXO 收盤價走勢
     *
     * GET /api/options/txo/trend
     */
    public function getTxoTrend(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $days = $request->input('days', 30);
        $result = $this->analysisService->getTxoTrend($days);

        return response()->json($result);
    }

    /**
     * 取得成交量分析
     *
     * GET /api/options/txo/volume-analysis
     */
    public function getVolumeAnalysis(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->input('date');
        $result = $this->analysisService->getVolumeAnalysis($date);

        return response()->json($result);
    }

    /**
     * 取得未平倉量分析
     *
     * GET /api/options/txo/oi-analysis
     */
    public function getOiAnalysis(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->input('date');
        $result = $this->analysisService->getOiAnalysis($date);

        return response()->json($result);
    }

    /**
     * 取得隱含波動率分析
     *
     * GET /api/options/txo/iv-analysis
     */
    public function getIvAnalysis(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $days = $request->input('days', 30);
        $result = $this->analysisService->getIvAnalysis($days);

        return response()->json($result);
    }

    /**
     * 取得市場情緒總覽
     *
     * GET /api/options/txo/sentiment
     */
    public function getSentiment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->input('date');
        $result = $this->analysisService->getSentiment($date);

        return response()->json($result);
    }

    /**
     * 取得 OI 分佈
     *
     * GET /api/options/txo/oi-distribution
     */
    public function getOiDistribution(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date',
            'limit' => 'nullable|integer|min:5|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->input('date');
        $limit = $request->input('limit', 20);
        $result = $this->analysisService->getOiDistribution($date, $limit);

        return response()->json($result);
    }
}
