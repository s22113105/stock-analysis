<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VolatilityService;
use App\Models\Stock;
use App\Models\Volatility;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 波動率計算 API 控制器
 */
class VolatilityController extends Controller
{
    protected $volatilityService;

    public function __construct(VolatilityService $volatilityService)
    {
        $this->volatilityService = $volatilityService;
    }

    /**
     * 計算歷史波動率 (Historical Volatility)
     * 
     * GET /api/volatility/historical/{stockId}
     */
    public function historical(Request $request, int $stockId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|integer|min:5|max:365',
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
            $stock = Stock::findOrFail($stockId);
            $period = intval($request->input('period', 30));
            $endDate = $request->input('end_date');

            // 計算歷史波動率
            $hv = $this->volatilityService->calculateHistoricalVolatility(
                $stockId,
                $period,
                $endDate
            );

            // 計算實現波動率
            $rv = $this->volatilityService->calculateRealizedVolatility(
                $stockId,
                $period,
                $endDate
            );

            if ($hv === null) {
                return response()->json([
                    'success' => false,
                    'message' => '資料不足，無法計算歷史波動率'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'historical_volatility' => $hv,
                    'historical_volatility_percentage' => round($hv * 100, 2) . '%',
                    'realized_volatility' => $rv,
                    'realized_volatility_percentage' => $rv ? round($rv * 100, 2) . '%' : null,
                    'period_days' => $period,
                    'end_date' => $endDate ?: now()->format('Y-m-d'),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('歷史波動率計算錯誤', [
                'stock_id' => $stockId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算隱含波動率 (Implied Volatility)
     * 
     * GET /api/volatility/implied/{optionId}
     */
    public function implied(Request $request, int $optionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date',
            'risk_free_rate' => 'nullable|numeric|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $date = $request->input('date');
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));

            // 計算隱含波動率
            $iv = $this->volatilityService->calculateImpliedVolatilityForOption(
                $optionId,
                $date,
                $riskFreeRate
            );

            if ($iv === null) {
                return response()->json([
                    'success' => false,
                    'message' => '無法計算隱含波動率，可能是資料不足或選擇權已到期'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'option_id' => $optionId,
                    'implied_volatility' => $iv,
                    'implied_volatility_percentage' => round($iv * 100, 2) . '%',
                    'trade_date' => $date ?: now()->format('Y-m-d'),
                    'risk_free_rate' => $riskFreeRate,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('隱含波動率計算錯誤', [
                'option_id' => $optionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算波動率曲面 (Volatility Surface)
     * 
     * GET /api/volatility/surface/{stockId}
     */
    public function surface(Request $request, string $underlying): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $date = $request->input('date');

            // 建立波動率曲面
            $surface = $this->volatilityService->buildVolatilitySurface(
                $underlying,
                $date
            );

            if (empty($surface)) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到波動率曲面資料'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $surface
            ]);

        } catch (\Exception $e) {
            Log::error('波動率曲面計算錯誤', [
                'underlying' => $underlying,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算波動率錐 (Volatility Cone)
     * 
     * GET /api/volatility/cone/{stockId}
     */
    public function cone(Request $request, int $stockId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lookback_days' => 'nullable|integer|min:30|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stock = Stock::findOrFail($stockId);
            $lookbackDays = intval($request->input('lookback_days', 252));

            // 計算波動率錐
            $cone = $this->volatilityService->calculateVolatilityCone(
                $stockId,
                $lookbackDays
            );

            if (empty($cone)) {
                return response()->json([
                    'success' => false,
                    'message' => '資料不足，無法計算波動率錐'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'cone' => $cone,
                    'lookback_days' => $lookbackDays,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('波動率錐計算錯誤', [
                'stock_id' => $stockId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算波動率偏斜 (Volatility Skew)
     * 
     * GET /api/volatility/skew/{stockId}
     */
    public function skew(Request $request, string $underlying): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'expiry_date' => 'required|date',
            'spot_price' => 'required|numeric|min:0.01',
            'date' => 'nullable|date',
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
            $spotPrice = floatval($request->input('spot_price'));
            $date = $request->input('date');

            // 計算波動率偏斜
            $skew = $this->volatilityService->calculateVolatilitySkew(
                $underlying,
                $expiryDate,
                $spotPrice,
                $date
            );

            if ($skew === null) {
                return response()->json([
                    'success' => false,
                    'message' => '無法計算波動率偏斜，可能是資料不足'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $skew
            ]);

        } catch (\Exception $e) {
            Log::error('波動率偏斜計算錯誤', [
                'underlying' => $underlying,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手動計算並儲存波動率
     * 
     * POST /api/volatility/calculate
     */
    public function calculate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stockId = intval($request->input('stock_id'));
            $date = $request->input('date');

            // 批次更新波動率
            $results = $this->volatilityService->batchUpdateVolatilities(
                $stockId,
                $date
            );

            return response()->json([
                'success' => true,
                'message' => '波動率計算完成',
                'data' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('波動率計算錯誤', [
                'stock_id' => $request->input('stock_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得已儲存的波動率資料
     * 
     * GET /api/volatility/garch/{stockId}
     */
    public function garch(Request $request, int $stockId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'period' => 'nullable|integer|min:5|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stock = Stock::findOrFail($stockId);
            $startDate = $request->input('start_date', now()->subMonths(3)->format('Y-m-d'));
            $endDate = $request->input('end_date', now()->format('Y-m-d'));
            $period = $request->input('period', 30);

            // 查詢波動率資料
            $volatilities = Volatility::where('stock_id', $stockId)
                ->whereBetween('calculation_date', [$startDate, $endDate])
                ->when($period, function ($query, $period) {
                    return $query->where('period_days', $period);
                })
                ->orderBy('calculation_date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'volatilities' => $volatilities,
                    'period_days' => $period,
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate,
                    ],
                    'data_points' => $volatilities->count(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('波動率資料查詢錯誤', [
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
}