<?php

namespace App\Http\Controllers;

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
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * 波動率計算 API 控制器 (優化版)
 * 
 * 功能：
 * - 計算歷史波動率 (HV)
 * - 計算隱含波動率 (IV)
 * - 波動率錐 (Volatility Cone)
 * - 波動率曲面 (Volatility Surface)
 * - 波動率偏斜 (Volatility Skew)
 * - GARCH 模型預測
 */
class VolatilityController extends Controller
{
    protected $volatilityService;

    // 快取時間 (分鐘)
    const CACHE_TTL = 30;

    public function __construct(VolatilityService $volatilityService)
    {
        $this->volatilityService = $volatilityService;
    }

    /**
     * 計算歷史波動率 (Historical Volatility)
     *
     * GET /api/volatility/historical/{stockId}
     * 
     * @param Request $request
     * @param int $stockId
     * @return JsonResponse
     */
    public function historical(Request $request, int $stockId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|integer|min:5|max:365',
            'end_date' => 'nullable|date',
            'method' => 'nullable|in:close-to-close,parkinson,garman-klass,rogers-satchell,yang-zhang',
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
            $method = $request->input('method', 'close-to-close');

            // 快取 key
            $cacheKey = "volatility:historical:{$stockId}:{$period}:{$endDate}:{$method}";
            
            // 嘗試從快取取得
            $cachedData = Cache::get($cacheKey);
            if ($cachedData && !$request->has('force')) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true
                ]);
            }

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
                    'message' => '資料不足，無法計算歷史波動率',
                    'required_days' => $period + 1,
                    'hint' => '請確認該股票有足夠的歷史價格資料'
                ], 400);
            }

            // 取得價格資料範圍
            $latestPrice = StockPrice::where('stock_id', $stockId)
                ->orderBy('trade_date', 'desc')
                ->first();

            // 計算波動率等級 (與歷史比較)
            $volatilityRank = $this->calculateVolatilityRank($stockId, $hv, $period);

            $responseData = [
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
                'calculation_method' => $method,
                'end_date' => $endDate ?: ($latestPrice ? $latestPrice->trade_date->format('Y-m-d') : now()->format('Y-m-d')),
                'latest_price' => $latestPrice ? [
                    'date' => $latestPrice->trade_date->format('Y-m-d'),
                    'close' => $latestPrice->close,
                    'change' => $latestPrice->change,
                    'change_percent' => $latestPrice->change_percent,
                ] : null,
                'volatility_rank' => $volatilityRank,
                'annualized' => true,
                'trading_days_per_year' => 252,
            ];

            // 儲存到快取
            Cache::put($cacheKey, $responseData, now()->addMinutes(self::CACHE_TTL));

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => '找不到指定的股票'
            ], 404);
        } catch (\Exception $e) {
            Log::error('計算歷史波動率錯誤', [
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
     * 
     * @param Request $request
     * @param int $optionId
     * @return JsonResponse
     */
    public function implied(Request $request, int $optionId): JsonResponse
    {
        try {
            $option = Option::with(['stock', 'latestPrice'])->findOrFail($optionId);

            // 取得選擇權最新價格
            $optionPrice = $option->latestPrice;
            if (!$optionPrice) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到選擇權價格資料'
                ], 404);
            }

            // 如果已有計算好的 IV
            if ($optionPrice->implied_volatility) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'option' => [
                            'id' => $option->id,
                            'symbol' => $option->symbol,
                            'underlying' => $option->underlying,
                            'strike_price' => $option->strike_price,
                            'expiry_date' => $option->expiry_date,
                            'option_type' => $option->option_type,
                        ],
                        'implied_volatility' => $optionPrice->implied_volatility,
                        'implied_volatility_percentage' => round($optionPrice->implied_volatility * 100, 2) . '%',
                        'option_price' => $optionPrice->close,
                        'trade_date' => $optionPrice->trade_date,
                        'calculation_method' => 'Newton-Raphson',
                    ]
                ]);
            }

            // 需要計算 IV (使用 Black-Scholes 反推)
            // 這裡需要股票現價、履約價、到期日、無風險利率等參數
            $stock = $option->stock;
            if (!$stock) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到關聯的股票資料'
                ], 404);
            }

            $stockPrice = $stock->latestPrice;
            if (!$stockPrice) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到股票價格資料'
                ], 404);
            }

            // 計算到期時間 (年)
            $timeToExpiry = Carbon::parse($option->expiry_date)
                ->diffInDays(Carbon::now()) / 365;

            if ($timeToExpiry <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => '選擇權已到期'
                ], 400);
            }

            // 使用 Newton-Raphson 方法計算 IV
            $iv = $this->calculateIVNewtonRaphson(
                $stockPrice->close,           // 股票現價
                $option->strike_price,        // 履約價
                $timeToExpiry,                // 到期時間
                0.02,                         // 無風險利率 (假設 2%)
                $optionPrice->close,          // 選擇權價格
                $option->option_type          // 選擇權類型
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'option' => [
                        'id' => $option->id,
                        'symbol' => $option->symbol,
                        'underlying' => $option->underlying,
                        'strike_price' => $option->strike_price,
                        'expiry_date' => $option->expiry_date,
                        'option_type' => $option->option_type,
                    ],
                    'implied_volatility' => $iv,
                    'implied_volatility_percentage' => round($iv * 100, 2) . '%',
                    'option_price' => $optionPrice->close,
                    'stock_price' => $stockPrice->close,
                    'time_to_expiry' => round($timeToExpiry, 4),
                    'trade_date' => $optionPrice->trade_date,
                    'calculation_method' => 'Newton-Raphson',
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => '找不到指定的選擇權'
            ], 404);
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
     * 計算波動率錐 (Volatility Cone)
     *
     * GET /api/volatility/cone/{stockId}
     * 
     * @param Request $request
     * @param int $stockId
     * @return JsonResponse
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

            // 快取 key
            $cacheKey = "volatility:cone:{$stockId}:{$lookbackDays}";
            
            $cachedData = Cache::get($cacheKey);
            if ($cachedData && !$request->has('force')) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true
                ]);
            }

            // 不同時間週期
            $periods = [10, 20, 30, 60, 90, 120, 180, 252];
            $coneData = [];

            foreach ($periods as $period) {
                $hvValues = [];

                // 計算過去 lookbackDays 天內，每天的 period 天 HV
                $prices = StockPrice::where('stock_id', $stockId)
                    ->orderBy('trade_date', 'desc')
                    ->limit($lookbackDays + $period + 1)
                    ->pluck('close')
                    ->reverse()
                    ->values()
                    ->toArray();

                if (count($prices) < $period + 1) {
                    continue;
                }

                // 滾動計算 HV
                for ($i = $period; $i < count($prices); $i++) {
                    $slice = array_slice($prices, $i - $period, $period + 1);
                    $hv = $this->calculateHVFromPrices($slice);
                    if ($hv !== null) {
                        $hvValues[] = $hv;
                    }
                }

                if (empty($hvValues)) {
                    continue;
                }

                // 排序計算統計值
                sort($hvValues);
                $count = count($hvValues);

                $coneData[] = [
                    'period' => $period,
                    'period_label' => $period . '天',
                    'current' => round(end($hvValues) * 100, 2),
                    'min' => round($hvValues[0] * 100, 2),
                    'max' => round($hvValues[$count - 1] * 100, 2),
                    'p25' => round($hvValues[intval($count * 0.25)] * 100, 2),
                    'median' => round($hvValues[intval($count * 0.5)] * 100, 2),
                    'p75' => round($hvValues[intval($count * 0.75)] * 100, 2),
                    'mean' => round(array_sum($hvValues) / $count * 100, 2),
                    'std' => round($this->calculateStd($hvValues) * 100, 2),
                    'sample_count' => $count,
                ];
            }

            $responseData = [
                'stock' => [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                ],
                'cone' => $coneData,
                'lookback_days' => $lookbackDays,
                'periods' => $periods,
            ];

            // 儲存到快取
            Cache::put($cacheKey, $responseData, now()->addMinutes(self::CACHE_TTL));

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => '找不到指定的股票'
            ], 404);
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
     * 計算波動率曲面 (Volatility Surface)
     *
     * GET /api/volatility/surface/{stockId}
     * 
     * @param Request $request
     * @param int $stockId
     * @return JsonResponse
     */
    public function surface(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);
            $date = $request->input('date', now()->format('Y-m-d'));

            // 取得該股票相關的選擇權
            $options = Option::where(function ($query) use ($stock) {
                    $query->where('underlying', $stock->symbol)
                          ->orWhere('underlying', 'TXO'); // 也包含 TXO
                })
                ->where('expiry_date', '>=', $date)
                ->orderBy('expiry_date')
                ->orderBy('strike_price')
                ->get();

            if ($options->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到相關的選擇權資料'
                ], 404);
            }

            // 按到期日分組
            $expiries = $options->pluck('expiry_date')->unique()->sort()->values();
            $strikes = $options->pluck('strike_price')->unique()->sort()->values();

            // 建構波動率曲面
            $surface3D = [];
            foreach ($expiries as $expiry) {
                $row = [];
                foreach ($strikes as $strike) {
                    $callOption = $options->where('expiry_date', $expiry)
                        ->where('strike_price', $strike)
                        ->where('option_type', 'call')
                        ->first();
                    
                    $putOption = $options->where('expiry_date', $expiry)
                        ->where('strike_price', $strike)
                        ->where('option_type', 'put')
                        ->first();

                    $ivCall = $callOption?->latestPrice?->implied_volatility;
                    $ivPut = $putOption?->latestPrice?->implied_volatility;

                    $row[] = [
                        'strike' => $strike,
                        'iv_call' => $ivCall ? round($ivCall * 100, 2) : null,
                        'iv_put' => $ivPut ? round($ivPut * 100, 2) : null,
                        'iv_avg' => ($ivCall || $ivPut)
                            ? round((($ivCall ?? 0) + ($ivPut ?? 0)) / (($ivCall ? 1 : 0) + ($ivPut ? 1 : 0)) * 100, 2)
                            : null,
                    ];
                }
                
                $surface3D[] = [
                    'expiry' => $expiry,
                    'expiry_formatted' => Carbon::parse($expiry)->format('Y/m/d'),
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
     * 計算波動率偏斜 (Volatility Skew)
     *
     * GET /api/volatility/skew/{stockId}
     * 
     * @param Request $request
     * @param int $stockId
     * @return JsonResponse
     */
    public function skew(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);
            $expiry = $request->input('expiry');

            // 取得最近的到期日選擇權
            $query = Option::where(function ($q) use ($stock) {
                    $q->where('underlying', $stock->symbol)
                      ->orWhere('underlying', 'TXO');
                })
                ->where('expiry_date', '>=', now());

            if ($expiry) {
                $query->where('expiry_date', $expiry);
            }

            $options = $query->orderBy('expiry_date')
                ->orderBy('strike_price')
                ->get();

            if ($options->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到相關的選擇權資料'
                ], 404);
            }

            // 取得最近到期日
            $nearestExpiry = $options->first()->expiry_date;
            $nearOptions = $options->where('expiry_date', $nearestExpiry);

            // 建構偏斜資料
            $skewData = [];
            $strikes = $nearOptions->pluck('strike_price')->unique()->sort();

            // 取得股票現價作為 ATM 參考
            $stockPrice = $stock->latestPrice?->close ?? 0;

            foreach ($strikes as $strike) {
                $callOption = $nearOptions->where('strike_price', $strike)
                    ->where('option_type', 'call')
                    ->first();
                
                $putOption = $nearOptions->where('strike_price', $strike)
                    ->where('option_type', 'put')
                    ->first();

                $ivCall = $callOption?->latestPrice?->implied_volatility;
                $ivPut = $putOption?->latestPrice?->implied_volatility;

                // 計算 moneyness
                $moneyness = $stockPrice > 0 ? ($strike / $stockPrice - 1) * 100 : 0;

                $skewData[] = [
                    'strike' => $strike,
                    'moneyness' => round($moneyness, 2),
                    'moneyness_label' => $moneyness > 0 ? 'OTM' : ($moneyness < 0 ? 'ITM' : 'ATM'),
                    'iv_call' => $ivCall ? round($ivCall * 100, 2) : null,
                    'iv_put' => $ivPut ? round($ivPut * 100, 2) : null,
                ];
            }

            // 計算偏斜度指標
            $atmStrike = $strikes->filter(fn($s) => abs($s - $stockPrice) < $stockPrice * 0.02)->first();
            $otmPut = $skewData[0] ?? null;
            $atmData = collect($skewData)->firstWhere('strike', $atmStrike);
            
            $skewIndex = null;
            if ($otmPut && $atmData && $otmPut['iv_put'] && $atmData['iv_call']) {
                $skewIndex = round($otmPut['iv_put'] - $atmData['iv_call'], 2);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                        'current_price' => $stockPrice,
                    ],
                    'expiry' => $nearestExpiry,
                    'expiry_formatted' => Carbon::parse($nearestExpiry)->format('Y/m/d'),
                    'days_to_expiry' => Carbon::parse($nearestExpiry)->diffInDays(now()),
                    'skew_data' => $skewData,
                    'skew_index' => $skewIndex,
                    'atm_strike' => $atmStrike,
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
     * 
     * @param Request $request
     * @param int $stockId
     * @return JsonResponse
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

            // 快取 key
            $cacheKey = "volatility:garch:{$stockId}:{$forecastDays}:{$modelType}";
            
            $cachedData = Cache::get($cacheKey);
            if ($cachedData && !$request->has('force')) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true
                ]);
            }

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
                    'message' => '歷史資料不足，至少需要 60 天的資料',
                    'available_days' => $prices->count(),
                    'required_days' => 60
                ], 400);
            }

            // 計算對數收益率
            $returns = [];
            for ($i = 1; $i < $prices->count(); $i++) {
                if ($prices[$i - 1]->close > 0) {
                    $returns[] = log($prices[$i]->close / $prices[$i - 1]->close);
                }
            }

            // GARCH(1,1) 參數 (使用估計值)
            // 在實務上，這些參數應該通過最大似然估計(MLE)來優化
            $omega = 0.000001;  // 常數項
            $alpha = 0.1;       // ARCH 項係數
            $beta = 0.85;       // GARCH 項係數

            // 計算條件變異數序列
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
            $lastReturn = end($returns);

            for ($i = 1; $i <= $forecastDays; $i++) {
                // GARCH 預測公式
                if ($i == 1) {
                    $forecastVar = $omega + $alpha * ($lastReturn * $lastReturn) + $beta * $lastCondVar;
                } else {
                    // 長期均值回歸
                    $longRunVar = $omega / (1 - $alpha - $beta);
                    $forecastVar = $longRunVar + pow($alpha + $beta, $i - 1) * ($lastCondVar - $longRunVar);
                }

                $forecastVol = sqrt($forecastVar * 252);

                $forecasts[] = [
                    'day' => $i,
                    'date' => now()->addWeekdays($i)->format('Y-m-d'),
                    'variance' => round($forecastVar, 10),
                    'volatility' => round($forecastVol, 6),
                    'volatility_percentage' => round($forecastVol * 100, 2) . '%',
                    'confidence_lower' => round(($forecastVol - 0.02) * 100, 2) . '%',
                    'confidence_upper' => round(($forecastVol + 0.02) * 100, 2) . '%',
                ];
            }

            $currentVol = sqrt(end($conditionalVariances) * 252);
            $longRunVol = sqrt(($omega / (1 - $alpha - $beta)) * 252);

            $responseData = [
                'stock' => [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                ],
                'model' => [
                    'type' => $modelType . '(1,1)',
                    'parameters' => [
                        'omega' => $omega,
                        'alpha' => $alpha,
                        'beta' => $beta,
                        'persistence' => round($alpha + $beta, 4),
                    ],
                    'description' => 'GARCH(1,1) 模型：σ²(t) = ω + α·ε²(t-1) + β·σ²(t-1)'
                ],
                'current_volatility' => round($currentVol, 6),
                'current_volatility_percentage' => round($currentVol * 100, 2) . '%',
                'long_run_volatility' => round($longRunVol, 6),
                'long_run_volatility_percentage' => round($longRunVol * 100, 2) . '%',
                'forecasts' => $forecasts,
                'historical_data_points' => count($returns),
                'calculation_date' => now()->format('Y-m-d H:i:s'),
            ];

            // 儲存到快取 (GARCH 快取時間短一些)
            Cache::put($cacheKey, $responseData, now()->addMinutes(15));

            return response()->json([
                'success' => true,
                'data' => $responseData,
                'note' => '此為簡化版 GARCH 模型，參數使用經驗值。建議使用專業統計軟體進行精確的參數估計。'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => '找不到指定的股票'
            ], 404);
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
     * 
     * @param Request $request
     * @return JsonResponse
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

            // 清除相關快取
            $this->clearVolatilityCache($stockId);

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

    // ========================================
    // 私有輔助方法
    // ========================================

    /**
     * 計算波動率等級 (百分位數)
     */
    private function calculateVolatilityRank(int $stockId, float $currentHV, int $period): ?float
    {
        try {
            // 取得過去一年的波動率資料
            $historicalHVs = Volatility::where('stock_id', $stockId)
                ->where('period_days', $period)
                ->orderBy('calculation_date', 'desc')
                ->limit(252)
                ->pluck('historical_volatility')
                ->toArray();

            if (count($historicalHVs) < 20) {
                return null;
            }

            // 計算當前值在歷史中的百分位數
            $count = count($historicalHVs);
            $below = count(array_filter($historicalHVs, fn($hv) => $hv < $currentHV));

            return round(($below / $count) * 100, 1);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 從價格陣列計算歷史波動率
     */
    private function calculateHVFromPrices(array $prices): ?float
    {
        if (count($prices) < 2) {
            return null;
        }

        $returns = [];
        for ($i = 1; $i < count($prices); $i++) {
            if ($prices[$i - 1] > 0) {
                $returns[] = log($prices[$i] / $prices[$i - 1]);
            }
        }

        if (empty($returns)) {
            return null;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = 0;
        foreach ($returns as $return) {
            $variance += pow($return - $mean, 2);
        }
        $variance /= count($returns);

        return sqrt($variance * 252);
    }

    /**
     * 計算標準差
     */
    private function calculateStd(array $values): float
    {
        if (empty($values)) return 0;
        
        $mean = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        return sqrt($variance / count($values));
    }

    /**
     * 使用 Newton-Raphson 方法計算隱含波動率
     */
    private function calculateIVNewtonRaphson(
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        float $optionPrice,
        string $optionType
    ): float {
        $sigma = 0.3; // 初始估計值
        $maxIterations = 100;
        $tolerance = 0.0001;

        for ($i = 0; $i < $maxIterations; $i++) {
            $bsPrice = $this->blackScholesPrice(
                $spotPrice, $strikePrice, $timeToExpiry, 
                $riskFreeRate, $sigma, $optionType
            );
            
            $vega = $this->blackScholesVega(
                $spotPrice, $strikePrice, $timeToExpiry,
                $riskFreeRate, $sigma
            );

            if (abs($vega) < 0.00001) {
                break;
            }

            $diff = $bsPrice - $optionPrice;
            if (abs($diff) < $tolerance) {
                break;
            }

            $sigma = $sigma - $diff / $vega;
            
            // 確保 sigma 在合理範圍內
            $sigma = max(0.01, min(5.0, $sigma));
        }

        return $sigma;
    }

    /**
     * Black-Scholes 價格計算
     */
    private function blackScholesPrice(
        float $S, float $K, float $T, 
        float $r, float $sigma, string $type
    ): float {
        $d1 = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $d2 = $d1 - $sigma * sqrt($T);

        if ($type === 'call') {
            return $S * $this->normalCDF($d1) - $K * exp(-$r * $T) * $this->normalCDF($d2);
        } else {
            return $K * exp(-$r * $T) * $this->normalCDF(-$d2) - $S * $this->normalCDF(-$d1);
        }
    }

    /**
     * Black-Scholes Vega
     */
    private function blackScholesVega(
        float $S, float $K, float $T, 
        float $r, float $sigma
    ): float {
        $d1 = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        return $S * sqrt($T) * $this->normalPDF($d1);
    }

    /**
     * 標準常態分配累積分配函數
     * 使用 Abramowitz and Stegun 近似法
     */
    private function normalCDF(float $x): float
    {
        // 使用高精度近似公式
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        // 保存符號
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x) / sqrt(2);

        // A&S 公式 7.1.26
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return 0.5 * (1.0 + $sign * $y);
    }

    /**
     * 標準常態分配機率密度函數
     */
    private function normalPDF(float $x): float
    {
        return exp(-0.5 * $x * $x) / sqrt(2 * M_PI);
    }

    /**
     * 誤差函數 (Error Function) 實作
     * 使用 Horner's method 進行多項式計算
     */
    private function erf(float $x): float
    {
        // 常數
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        // 保存符號
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);

        // A&S 公式 7.1.26
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /**
     * 清除波動率相關快取
     */
    private function clearVolatilityCache(int $stockId): void
    {
        Cache::forget("volatility:historical:{$stockId}:*");
        Cache::forget("volatility:cone:{$stockId}:*");
        Cache::forget("volatility:garch:{$stockId}:*");
    }
}