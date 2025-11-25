<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OptionChainService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class OptionChainController extends Controller
{
    protected $chainService;

    public function __construct(OptionChainService $chainService)
    {
        $this->chainService = $chainService;
    }

    /**
     * 取得選擇權 T 字報價表
     *
     * GET /api/options/chain-table
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getChainTable(Request $request): JsonResponse
    {
        try {
            // 驗證輸入參數
            $request->validate([
                'expiry_date' => 'nullable|date_format:Y-m-d'
            ]);

            $expiryDate = $request->input('expiry_date');

            // 使用快取機制（快取 30 秒）
            $cacheKey = 'option_chain_' . ($expiryDate ?? 'default');

            $data = Cache::remember($cacheKey, 30, function () use ($expiryDate) {
                return $this->chainService->getOptionChain($expiryDate);
            });

            // 記錄請求
            Log::info('OptionChain 請求', [
                'expiry_date' => $expiryDate,
                'has_data' => !empty($data['chain']),
                'chain_count' => count($data['chain'] ?? [])
            ]);

            // 檢查是否有錯誤訊息
            if (isset($data['success']) && !$data['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $data['message'] ?? '無法取得選擇權資料',
                    'data' => null
                ], 404);
            }

            // 成功回傳資料
            return response()->json([
                'success' => true,
                'message' => '成功取得選擇權鏈資料',
                'data' => $data
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('OptionChainController 錯誤', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '系統錯誤，請稍後再試',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * 測試資料庫連接（除錯用）
     *
     * GET /api/options/chain-table/test
     *
     * @return JsonResponse
     */
    public function testConnection(): JsonResponse
    {
        try {
            $result = $this->chainService->testDatabaseConnection();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 清除快取
     *
     * POST /api/options/chain-table/clear-cache
     *
     * @return JsonResponse
     */
    public function clearCache(): JsonResponse
    {
        try {
            // 清除所有選擇權鏈快取
            $patterns = ['option_chain_*'];
            foreach ($patterns as $pattern) {
                $keys = Cache::getRedis()->keys($pattern);
                foreach ($keys as $key) {
                    Cache::forget(str_replace(config('cache.prefix') . ':', '', $key));
                }
            }

            return response()->json([
                'success' => true,
                'message' => '快取已清除'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '清除快取失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得即時市場狀態
     *
     * GET /api/options/chain-table/market-status
     *
     * @return JsonResponse
     */
    public function getMarketStatus(): JsonResponse
    {
        try {
            $now = now('Asia/Taipei');
            $isWeekend = $now->isWeekend();
            $hour = $now->hour;
            $minute = $now->minute;

            // 判斷是否為交易時間（台灣時間 08:45 - 13:45）
            $isMarketHours = !$isWeekend &&
                (($hour == 8 && $minute >= 45) ||
                    ($hour > 8 && $hour < 13) ||
                    ($hour == 13 && $minute <= 45));

            // 判斷是否為盤後（13:45 之後）
            $isAfterHours = !$isWeekend &&
                (($hour == 13 && $minute > 45) || $hour > 13);

            return response()->json([
                'success' => true,
                'data' => [
                    'current_time' => $now->format('Y-m-d H:i:s'),
                    'timezone' => 'Asia/Taipei',
                    'is_weekend' => $isWeekend,
                    'is_market_hours' => $isMarketHours,
                    'is_after_hours' => $isAfterHours,
                    'market_status' => $isMarketHours ? '開盤中' : ($isAfterHours ? '已收盤' : '未開盤'),
                    'next_market_open' => $this->getNextMarketOpen($now)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '無法取得市場狀態'
            ], 500);
        }
    }

    /**
     * 計算下次開盤時間
     */
    private function getNextMarketOpen($now)
    {
        $nextOpen = $now->copy();

        // 如果是週末，跳到下週一
        if ($now->isWeekend()) {
            $nextOpen->next('Monday');
        }
        // 如果已經過了今天的開盤時間
        elseif ($now->hour > 8 || ($now->hour == 8 && $now->minute >= 45)) {
            $nextOpen->addDay();
            // 如果明天是週末，跳到下週一
            if ($nextOpen->isWeekend()) {
                $nextOpen->next('Monday');
            }
        }

        // 設定為 08:45
        $nextOpen->setTime(8, 45, 0);

        return $nextOpen->format('Y-m-d H:i:s');
    }
}
