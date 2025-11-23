<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BlackScholesService;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * Black-Scholes 計算 API 控制器
 */
class BlackScholesController extends Controller
{
    protected $blackScholesService;

    public function __construct(BlackScholesService $blackScholesService)
    {
        $this->blackScholesService = $blackScholesService;
    }

    /**
     * 計算選擇權理論價格與 Greeks
     *
     * POST /api/black-scholes/calculate
     */
    public function calculate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'spot_price' => 'required|numeric|min:0.01',
            'strike_price' => 'required|numeric|min:0.01',
            'time_to_expiry' => 'required|numeric|min:0.001', // 年為單位
            'risk_free_rate' => 'nullable|numeric|min:0|max:1',
            'volatility' => 'required|numeric|min:0.001|max:10',
            'option_type' => 'required|in:call,put',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $spotPrice = floatval($request->input('spot_price'));
            $strikePrice = floatval($request->input('strike_price'));
            $timeToExpiry = floatval($request->input('time_to_expiry'));
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015)); // 預設 1.5%
            $volatility = floatval($request->input('volatility'));
            $optionType = $request->input('option_type');

            // 計算理論價格
            $theoreticalPrice = $this->blackScholesService->calculatePrice(
                $spotPrice,
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $volatility,
                $optionType
            );

            // 計算 Greeks
            $greeks = $this->blackScholesService->calculateGreeks(
                $spotPrice,
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $volatility,
                $optionType
            );

            // 計算內在價值和時間價值
            $intrinsicValue = $this->blackScholesService->calculateIntrinsicValue(
                $spotPrice,
                $strikePrice,
                $optionType
            );

            $timeValue = $this->blackScholesService->calculateTimeValue(
                $theoreticalPrice,
                $spotPrice,
                $strikePrice,
                $optionType
            );

            // 判斷價性
            $moneyness = $this->blackScholesService->getMoneyness(
                $spotPrice,
                $strikePrice,
                $optionType
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'theoretical_price' => $theoreticalPrice,
                    'intrinsic_value' => round($intrinsicValue, 4),
                    'time_value' => round($timeValue, 4),
                    'moneyness' => $moneyness,
                    'greeks' => $greeks,
                ],
                'inputs' => [
                    'spot_price' => $spotPrice,
                    'strike_price' => $strikePrice,
                    'time_to_expiry' => $timeToExpiry,
                    'risk_free_rate' => $riskFreeRate,
                    'volatility' => $volatility,
                    'option_type' => $optionType,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Black-Scholes 計算錯誤', [
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
     * 計算隱含波動率
     *
     * POST /api/black-scholes/implied-volatility
     */
    public function impliedVolatility(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'market_price' => 'required|numeric|min:0',
            'spot_price' => 'required|numeric|min:0.01',
            'strike_price' => 'required|numeric|min:0.01',
            'time_to_expiry' => 'required|numeric|min:0.001',
            'risk_free_rate' => 'nullable|numeric|min:0|max:1',
            'option_type' => 'required|in:call,put',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $marketPrice = floatval($request->input('market_price'));
            $spotPrice = floatval($request->input('spot_price'));
            $strikePrice = floatval($request->input('strike_price'));
            $timeToExpiry = floatval($request->input('time_to_expiry'));
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));
            $optionType = $request->input('option_type');

            // 計算隱含波動率
            $impliedVol = $this->blackScholesService->calculateImpliedVolatility(
                $marketPrice,
                $spotPrice,
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $optionType
            );

            if ($impliedVol === null) {
                return response()->json([
                    'success' => false,
                    'message' => '無法計算隱含波動率，可能是市場價格異常或參數不合理'
                ], 400);
            }

            // 使用計算出的 IV 反算理論價格，驗證準確性
            $verifyPrice = $this->blackScholesService->calculatePrice(
                $spotPrice,
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $impliedVol,
                $optionType
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'implied_volatility' => $impliedVol,
                    'implied_volatility_percentage' => round($impliedVol * 100, 2) . '%',
                    'market_price' => $marketPrice,
                    'theoretical_price' => $verifyPrice,
                    'price_difference' => round($verifyPrice - $marketPrice, 4),
                ],
                'inputs' => [
                    'market_price' => $marketPrice,
                    'spot_price' => $spotPrice,
                    'strike_price' => $strikePrice,
                    'time_to_expiry' => $timeToExpiry,
                    'risk_free_rate' => $riskFreeRate,
                    'option_type' => $optionType,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('隱含波動率計算錯誤', [
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
     * 批次計算選擇權鏈 (Option Chain)
     *
     * POST /api/black-scholes/option-chain
     */
    public function optionChain(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'spot_price' => 'required|numeric|min:0.01',
            'strikes' => 'required|array|min:1',
            'strikes.*' => 'numeric|min:0.01',
            'time_to_expiry' => 'required|numeric|min:0.001',
            'risk_free_rate' => 'nullable|numeric|min:0|max:1',
            'volatility' => 'required|numeric|min:0.001|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $spotPrice = floatval($request->input('spot_price'));
            $strikes = $request->input('strikes');
            $timeToExpiry = floatval($request->input('time_to_expiry'));
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));
            $volatility = floatval($request->input('volatility'));

            $optionChain = [];

            foreach ($strikes as $strike) {
                $strike = floatval($strike);

                // 計算 Call
                $callPrice = $this->blackScholesService->calculatePrice(
                    $spotPrice, $strike, $timeToExpiry, $riskFreeRate, $volatility, 'call'
                );
                $callGreeks = $this->blackScholesService->calculateGreeks(
                    $spotPrice, $strike, $timeToExpiry, $riskFreeRate, $volatility, 'call'
                );

                // 計算 Put
                $putPrice = $this->blackScholesService->calculatePrice(
                    $spotPrice, $strike, $timeToExpiry, $riskFreeRate, $volatility, 'put'
                );
                $putGreeks = $this->blackScholesService->calculateGreeks(
                    $spotPrice, $strike, $timeToExpiry, $riskFreeRate, $volatility, 'put'
                );

                $optionChain[] = [
                    'strike_price' => $strike,
                    'moneyness' => $this->blackScholesService->getMoneyness($spotPrice, $strike, 'call'),
                    'call' => [
                        'price' => $callPrice,
                        'greeks' => $callGreeks,
                    ],
                    'put' => [
                        'price' => $putPrice,
                        'greeks' => $putGreeks,
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'option_chain' => $optionChain,
                    'spot_price' => $spotPrice,
                    'time_to_expiry' => $timeToExpiry,
                    'volatility' => $volatility,
                    'risk_free_rate' => $riskFreeRate,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('選擇權鏈計算錯誤', [
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
     * 計算波動率微笑 (Volatility Smile)
     * 使用實際選擇權市場價格反推 IV
     *
     * POST /api/black-scholes/volatility-smile
     */
    public function volatilitySmile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'underlying' => 'required|string',
            'expiry_date' => 'nullable|date',
            'contract_month' => 'nullable|string', // 新增：合約月份代碼 (例如: 202511W2)
            'trade_date' => 'nullable|date',
            'option_type' => 'required|in:call,put,both',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $underlying = $request->input('underlying');
            $expiryDate = $request->input('expiry_date');
            $contractMonth = $request->input('contract_month'); // 例如 202511 或 202511W2
            $tradeDate = $request->input('trade_date', now()->format('Y-m-d'));
            $optionType = $request->input('option_type');

            // 查詢選擇權資料
            $query = Option::where('underlying', $underlying)
                ->where('is_active', true);

            // 如果有指定到期日，使用到期日過濾
            if ($expiryDate) {
                $query->where('expiry_date', $expiryDate);
            }

            // ⚠️ 重要更新：如果是真實資料模式，建議使用 Contract Month 過濾
            // 因為 option_code 格式為 TXO_202511W2_C_20000
            if ($contractMonth) {
                $query->where('option_code', 'like', "%_{$contractMonth}_%");
            }

            // 關聯查詢價格
            $query->with(['prices' => function ($q) use ($tradeDate) {
                $q->where('trade_date', $tradeDate)
                  ->whereNotNull('implied_volatility'); // 必須要有 IV 才能畫微笑曲線
            }]);

            // 選擇權類型過濾
            if ($optionType !== 'both') {
                $query->where('option_type', $optionType);
            }

            $options = $query->orderBy('strike_price')->get();

            if ($options->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到符合條件的選擇權資料，請確認該月份/日期是否有資料',
                    'debug' => [
                        'underlying' => $underlying,
                        'date' => $tradeDate,
                        'contract_month' => $contractMonth
                    ]
                ], 404);
            }

            $smileData = [];

            foreach ($options as $option) {
                $price = $option->prices->first();

                if (!$price || !$price->implied_volatility) {
                    continue;
                }

                $smileData[] = [
                    'option_code' => $option->option_code,
                    'option_type' => $option->option_type,
                    'strike_price' => floatval($option->strike_price),
                    'implied_volatility' => floatval($price->implied_volatility),
                    'market_price' => floatval($price->close),
                    'volume' => $price->volume,
                    'open_interest' => $price->open_interest,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'smile_data' => $smileData,
                    'underlying' => $underlying,
                    'contract_month' => $contractMonth,
                    'expiry_date' => $expiryDate,
                    'trade_date' => $tradeDate,
                    'data_points' => count($smileData),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('波動率微笑計算錯誤', [
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
     * 批次計算多個選擇權的 Greeks
     *
     * POST /api/black-scholes/batch-greeks
     */
    public function batchGreeks(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'options' => 'required|array|min:1',
            'options.*.option_id' => 'required|integer|exists:options,id',
            'options.*.spot_price' => 'required|numeric|min:0.01',
            'options.*.volatility' => 'required|numeric|min:0.001|max:10',
            'trade_date' => 'nullable|date',
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
            $optionsInput = $request->input('options');
            $tradeDate = $request->input('trade_date', now()->format('Y-m-d'));
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));

            $results = [];

            foreach ($optionsInput as $optionInput) {
                $option = Option::find($optionInput['option_id']);

                if (!$option) {
                    continue;
                }

                // 計算到期時間
                $now = Carbon::parse($tradeDate);
                $expiry = Carbon::parse($option->expiry_date);
                $timeToExpiry = max(0.001, $now->diffInDays($expiry) / 365);

                $spotPrice = floatval($optionInput['spot_price']);
                $volatility = floatval($optionInput['volatility']);

                // 計算理論價格和 Greeks
                $theoreticalPrice = $this->blackScholesService->calculatePrice(
                    $spotPrice,
                    $option->strike_price,
                    $timeToExpiry,
                    $riskFreeRate,
                    $volatility,
                    $option->option_type
                );

                $greeks = $this->blackScholesService->calculateGreeks(
                    $spotPrice,
                    $option->strike_price,
                    $timeToExpiry,
                    $riskFreeRate,
                    $volatility,
                    $option->option_type
                );

                $results[] = [
                    'option_id' => $option->id,
                    'option_code' => $option->option_code,
                    'option_type' => $option->option_type,
                    'strike_price' => $option->strike_price,
                    'theoretical_price' => $theoreticalPrice,
                    'greeks' => $greeks,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'results' => $results,
                    'trade_date' => $tradeDate,
                    'risk_free_rate' => $riskFreeRate,
                    'total_calculated' => count($results),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('批次 Greeks 計算錯誤', [
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