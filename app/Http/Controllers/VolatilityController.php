<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VolatilityService;
use App\Models\Stock;
use App\Models\Option;
use App\Models\StockPrice;
use App\Models\Volatility;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

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
     * 計算選擇權隱含波動率
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
            $iv = $this->volatilityService->calculateImpliedVolatilityForOption(  // ✅ 正確方法
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

            // 取得選擇權資訊
            $option = Option::with(['latestPrice', 'stock'])->findOrFail($optionId);

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
                    'implied_volatility' => $iv,
                    'implied_volatility_percentage' => round($iv * 100, 2) . '%',
                    'market_price' => $option->latestPrice?->close,
                    'date' => $date ?: now()->format('Y-m-d'),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算隱含波動率錯誤', [
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
    public function surface(Request $request, int $stockId): JsonResponse
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
            $stock = Stock::findOrFail($stockId);
            $date = $request->input('date', now()->format('Y-m-d'));

            // 取得所有有效的選擇權及其隱含波動率
            $options = Option::where('underlying', $stock->symbol)
                ->where('is_active', true)
                ->where('expiry_date', '>=', $date)
                ->with(['latestPrice' => function ($q) use ($date) {
                    $q->where('trade_date', $date);
                }])
                ->get();

            if ($options->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該股票的選擇權資料'
                ], 404);
            }

            // 按到期日和履約價組織資料
            $surfaceData = [];
            $expiries = [];
            $strikes = [];

            foreach ($options as $option) {
                if (!$option->latestPrice || !$option->latestPrice->implied_volatility) {
                    continue;
                }

                $expiry = $option->expiry_date;
                $strike = $option->strike_price;
                $iv = $option->latestPrice->implied_volatility;

                if (!isset($surfaceData[$expiry])) {
                    $surfaceData[$expiry] = [];
                    $expiries[] = $expiry;
                }

                if (!in_array($strike, $strikes)) {
                    $strikes[] = $strike;
                }

                if (!isset($surfaceData[$expiry][$strike])) {
                    $surfaceData[$expiry][$strike] = [
                        'call' => null,
                        'put' => null,
                    ];
                }

                if ($option->option_type === 'call') {
                    $surfaceData[$expiry][$strike]['call'] = $iv;
                } else {
                    $surfaceData[$expiry][$strike]['put'] = $iv;
                }
            }

            // 排序
            sort($expiries);
            sort($strikes);

            // 轉換為 3D 圖表格式
            $surface3D = [];
            foreach ($expiries as $expiry) {
                $row = [];
                foreach ($strikes as $strike) {
                    $iv = $surfaceData[$expiry][$strike] ?? ['call' => null, 'put' => null];
                    $row[] = [
                        'strike' => $strike,
                        'iv_call' => $iv['call'],
                        'iv_put' => $iv['put'],
                        'iv_avg' => ($iv['call'] && $iv['put']) 
                            ? ($iv['call'] + $iv['put']) / 2 
                            : ($iv['call'] ?? $iv['put']),
                    ];
                }
                $surface3D[] = [
                    'expiry' => $expiry,
                    'days_to_expiry' => Carbon::parse($expiry)->diffInDays(Carbon::parse($date)),
                    'data' => $row,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'surface' => $surface3D,
                    'expiries' => $expiries,
                    'strikes' => $strikes,
                    'date' => $date,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算波動率曲面錯誤', [
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
     * 計算波動率錐 (Volatility Cone)
     * 
     * GET /api/volatility/cone/{stockId}
     */
    public function cone(Request $request, int $stockId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lookback_days' => 'nullable|integer|min:30|max:730',
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

            // 不同時間週期
            $periods = [10, 20, 30, 60, 90, 120, 180, 252];
            $coneData = [];

            foreach ($periods as $period) {
                $hvValues = [];
                
                // 計算過去 lookbackDays 天內，每天的 period 天 HV
                for ($i = $period; $i < $lookbackDays; $i++) {
                    $date = now()->subDays($i)->format('Y-m-d');
                    $hv = $this->volatilityService->calculateHistoricalVolatility(
                        $stockId,
                        $period,
                        $date
                    );
                    
                    if ($hv !== null) {
                        $hvValues[] = $hv;
                    }
                }

                if (!empty($hvValues)) {
                    sort($hvValues);
                    $count = count($hvValues);
                    
                    $coneData[] = [
                        'period' => $period,
                        'min' => round(min($hvValues), 4),
                        'percentile_10' => round($hvValues[intval($count * 0.1)], 4),
                        'percentile_25' => round($hvValues[intval($count * 0.25)], 4),
                        'median' => round($hvValues[intval($count * 0.5)], 4),
                        'percentile_75' => round($hvValues[intval($count * 0.75)], 4),
                        'percentile_90' => round($hvValues[intval($count * 0.9)], 4),
                        'max' => round(max($hvValues), 4),
                        'current' => $this->volatilityService->calculateHistoricalVolatility($stockId, $period),
                        'sample_size' => $count,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'cone' => $coneData,
                    'lookback_days' => $lookbackDays,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算波動率錐錯誤', [
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
    public function skew(Request $request, int $stockId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'expiry_date' => 'required|date',
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
            $stock = Stock::findOrFail($stockId);
            $expiryDate = $request->input('expiry_date');
            $date = $request->input('date', now()->format('Y-m-d'));

            // 取得標的價格
            $spotPrice = $stock->latestPrice?->close;
            if (!$spotPrice) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到標的最新價格'
                ], 404);
            }

            // 計算波動率偏斜
            $skewData = $this->volatilityService->calculateVolatilitySkew(
                $stock->symbol,
                $expiryDate,
                $spotPrice,
                $date
            );

            if ($skewData === null) {
                return response()->json([
                    'success' => false,
                    'message' => '無法計算波動率偏斜，資料不足'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                        'spot_price' => $spotPrice,
                    ],
                    'skew' => $skewData,
                    'expiry_date' => $expiryDate,
                    'date' => $date,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算波動率偏斜錯誤', [
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
     * 使用 GARCH 模型預測波動率
     * 
     * GET /api/volatility/garch/{stockId}
     */
    public function garch(Request $request, int $stockId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'forecast_days' => 'nullable|integer|min:1|max:30',
            'model_type' => 'nullable|in:GARCH,EGARCH,TGARCH',
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
            $forecastDays = intval($request->input('forecast_days', 5));
            $modelType = $request->input('model_type', 'GARCH');

            // 取得歷史價格資料
            $prices = StockPrice::where('stock_id', $stockId)
                ->orderBy('trade_date', 'desc')
                ->limit(252)
                ->get()
                ->reverse()
                ->values();

            if ($prices->count() < 60) {
                return response()->json([
                    'success' => false,
                    'message' => '歷史資料不足，至少需要 60 天的資料'
                ], 400);
            }

            // 計算對數收益率
            $returns = [];
            for ($i = 1; $i < $prices->count(); $i++) {
                if ($prices[$i-1]->close > 0) {
                    $returns[] = log($prices[$i]->close / $prices[$i-1]->close);
                }
            }

            // 簡化的 GARCH(1,1) 實作
            $omega = 0.000001;
            $alpha = 0.1;
            $beta = 0.85;

            // 計算條件變異數
            $conditionalVariances = [];
            $unconditionalVar = array_sum(array_map(fn($r) => $r * $r, $returns)) / count($returns);
            $lastVariance = $unconditionalVar;

            foreach ($returns as $return) {
                $variance = $omega + $alpha * ($return * $return) + $beta * $lastVariance;
                $conditionalVariances[] = $variance;
                $lastVariance = $variance;
            }

            // 預測未來波動率
            $forecasts = [];
            $lastCondVar = end($conditionalVariances);
            
            for ($i = 1; $i <= $forecastDays; $i++) {
                $forecastVar = $omega + ($alpha + $beta) * $lastCondVar;
                $forecastVol = sqrt($forecastVar * 252);
                
                $forecasts[] = [
                    'day' => $i,
                    'date' => now()->addDays($i)->format('Y-m-d'),
                    'volatility' => round($forecastVol, 6),
                    'volatility_percentage' => round($forecastVol * 100, 2) . '%',
                ];

                $lastCondVar = $forecastVar;
            }

            $currentVol = sqrt(end($conditionalVariances) * 252);

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'model' => [
                        'type' => $modelType,
                        'parameters' => [
                            'omega' => $omega,
                            'alpha' => $alpha,
                            'beta' => $beta,
                        ],
                    ],
                    'current_volatility' => round($currentVol, 6),
                    'current_volatility_percentage' => round($currentVol * 100, 2) . '%',
                    'forecasts' => $forecasts,
                    'historical_data_points' => count($returns),
                ],
                'note' => '此為簡化版 GARCH 模型，建議使用專業統計軟體進行精確分析'
            ]);

        } catch (\Exception $e) {
            Log::error('GARCH 模型計算錯誤', [
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
}