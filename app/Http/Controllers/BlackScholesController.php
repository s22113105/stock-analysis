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
 * 
 * @version 2.0 改進版
 * - 新增時間衰減分析 API
 * - 新增到期損益計算 API
 * - 新增批次價格計算 API
 * - 改進錯誤處理
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
                    'greeks' => $greeks,
                    'intrinsic_value' => round($intrinsicValue, 4),
                    'time_value' => round($timeValue, 4),
                    'moneyness' => $moneyness,
                    'input_parameters' => [
                        'spot_price' => $spotPrice,
                        'strike_price' => $strikePrice,
                        'time_to_expiry' => $timeToExpiry,
                        'time_to_expiry_days' => round($timeToExpiry * 365),
                        'risk_free_rate' => $riskFreeRate,
                        'risk_free_rate_percentage' => round($riskFreeRate * 100, 2) . '%',
                        'volatility' => $volatility,
                        'volatility_percentage' => round($volatility * 100, 2) . '%',
                        'option_type' => $optionType,
                    ],
                    'calculated_at' => now()->toIso8601String(),
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
     * 計算隱含波動率 (Implied Volatility)
     *
     * POST /api/black-scholes/implied-volatility
     */
    public function impliedVolatility(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'spot_price' => 'required|numeric|min:0.01',
            'strike_price' => 'required|numeric|min:0.01',
            'time_to_expiry' => 'required|numeric|min:0.001',
            'risk_free_rate' => 'nullable|numeric|min:0|max:1',
            'market_price' => 'required|numeric|min:0.01',
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
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));
            $marketPrice = floatval($request->input('market_price'));
            $optionType = $request->input('option_type');

            $impliedVolatility = $this->blackScholesService->calculateImpliedVolatility(
                $marketPrice,
                $spotPrice,
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $optionType
            );

            if ($impliedVolatility === null) {
                return response()->json([
                    'success' => false,
                    'message' => '無法計算隱含波動率，請檢查輸入參數是否合理'
                ], 400);
            }

            // 判斷價性
            $moneyness = $this->blackScholesService->getMoneyness(
                $spotPrice,
                $strikePrice,
                $optionType
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'implied_volatility' => $impliedVolatility,
                    'implied_volatility_percentage' => round($impliedVolatility * 100, 2) . '%',
                    'moneyness' => $moneyness,
                    'input_parameters' => [
                        'spot_price' => $spotPrice,
                        'strike_price' => $strikePrice,
                        'time_to_expiry' => $timeToExpiry,
                        'time_to_expiry_days' => round($timeToExpiry * 365),
                        'risk_free_rate' => $riskFreeRate,
                        'market_price' => $marketPrice,
                        'option_type' => $optionType,
                    ],
                    'calculated_at' => now()->toIso8601String(),
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
     * 新增：計算時間衰減分析
     *
     * POST /api/black-scholes/time-decay
     */
    public function timeDecay(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'spot_price' => 'required|numeric|min:0.01',
            'strike_price' => 'required|numeric|min:0.01',
            'time_to_expiry' => 'required|numeric|min:0.001',
            'risk_free_rate' => 'nullable|numeric|min:0|max:1',
            'volatility' => 'required|numeric|min:0.001|max:10',
            'option_type' => 'required|in:call,put',
            'points' => 'nullable|integer|min:5|max:30',
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
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));
            $volatility = floatval($request->input('volatility'));
            $optionType = $request->input('option_type');
            $points = intval($request->input('points', 15));

            $timeDecayData = $this->blackScholesService->calculateTimeDecay(
                $spotPrice,
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $volatility,
                $optionType,
                $points
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'time_decay' => $timeDecayData,
                    'input_parameters' => [
                        'spot_price' => $spotPrice,
                        'strike_price' => $strikePrice,
                        'time_to_expiry' => $timeToExpiry,
                        'volatility_percentage' => round($volatility * 100, 2) . '%',
                        'option_type' => $optionType,
                    ],
                    'calculated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('時間衰減分析錯誤', [
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
     * 新增：計算到期損益 (Payoff)
     *
     * POST /api/black-scholes/payoff
     */
    public function payoff(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'strike_price' => 'required|numeric|min:0.01',
            'premium' => 'required|numeric|min:0',
            'option_type' => 'required|in:call,put',
            'position' => 'nullable|in:long,short',
            'spot_range_min' => 'nullable|numeric|min:0',
            'spot_range_max' => 'nullable|numeric|min:0',
            'points' => 'nullable|integer|min:10|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $strikePrice = floatval($request->input('strike_price'));
            $premium = floatval($request->input('premium'));
            $optionType = $request->input('option_type');
            $position = $request->input('position', 'long');
            $points = intval($request->input('points', 21));

            // 計算股價範圍
            $spotMin = floatval($request->input('spot_range_min', $strikePrice * 0.8));
            $spotMax = floatval($request->input('spot_range_max', $strikePrice * 1.2));
            $interval = ($spotMax - $spotMin) / ($points - 1);

            $spotPrices = [];
            for ($i = 0; $i < $points; $i++) {
                $spotPrices[] = round($spotMin + ($interval * $i), 2);
            }

            $payoffs = $this->blackScholesService->calculatePayoff(
                $strikePrice,
                $premium,
                $optionType,
                $position,
                $spotPrices
            );

            // 計算損益兩平點
            $breakeven = $optionType === 'call' 
                ? $strikePrice + $premium 
                : $strikePrice - $premium;

            // 計算最大獲利和最大虧損
            $maxProfit = $position === 'long' 
                ? ($optionType === 'call' ? '無限' : round($strikePrice - $premium, 2))
                : $premium;
            
            $maxLoss = $position === 'long'
                ? $premium
                : ($optionType === 'call' ? '無限' : round($strikePrice - $premium, 2));

            return response()->json([
                'success' => true,
                'data' => [
                    'spot_prices' => $spotPrices,
                    'payoffs' => $payoffs,
                    'breakeven' => round($breakeven, 2),
                    'max_profit' => $maxProfit,
                    'max_loss' => $maxLoss,
                    'input_parameters' => [
                        'strike_price' => $strikePrice,
                        'premium' => $premium,
                        'option_type' => $optionType,
                        'position' => $position,
                    ],
                    'calculated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('到期損益計算錯誤', [
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
     * 新增：批次計算不同股價的選擇權價格
     *
     * POST /api/black-scholes/batch-prices
     */
    public function batchPrices(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'strike_price' => 'required|numeric|min:0.01',
            'time_to_expiry' => 'required|numeric|min:0.001',
            'risk_free_rate' => 'nullable|numeric|min:0|max:1',
            'volatility' => 'required|numeric|min:0.001|max:10',
            'option_type' => 'required|in:call,put',
            'spot_prices' => 'required|array|min:2|max:100',
            'spot_prices.*' => 'numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $strikePrice = floatval($request->input('strike_price'));
            $timeToExpiry = floatval($request->input('time_to_expiry'));
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));
            $volatility = floatval($request->input('volatility'));
            $optionType = $request->input('option_type');
            $spotPrices = array_map('floatval', $request->input('spot_prices'));

            $prices = $this->blackScholesService->batchCalculatePrices(
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $volatility,
                $optionType,
                $spotPrices
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'spot_prices' => $spotPrices,
                    'option_prices' => $prices,
                    'input_parameters' => [
                        'strike_price' => $strikePrice,
                        'time_to_expiry' => $timeToExpiry,
                        'volatility_percentage' => round($volatility * 100, 2) . '%',
                        'option_type' => $optionType,
                    ],
                    'calculated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('批次價格計算錯誤', [
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
     * 取得波動率微笑數據
     *
     * GET /api/black-scholes/volatility-smile
     */
    public function volatilitySmile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'underlying' => 'required|string',
            'contract_month' => 'nullable|string',
            'trade_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $underlying = $request->input('underlying', 'TXO');
            $contractMonth = $request->input('contract_month');
            $tradeDate = $request->input('trade_date', now()->format('Y-m-d'));

            // 取得該標的的選擇權
            $query = Option::where('underlying', $underlying)
                ->where('expiry_date', '>=', now());

            if ($contractMonth) {
                $query->where('contract_month', $contractMonth);
            }

            $options = $query->orderBy('strike_price')->get();

            if ($options->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到相關選擇權資料'
                ], 404);
            }

            // 取得最近的到期日
            $expiryDate = $options->first()->expiry_date;

            $smileData = [];

            foreach ($options as $option) {
                // 取得最新價格資料
                $price = OptionPrice::where('option_id', $option->id)
                    ->where('trade_date', '<=', $tradeDate)
                    ->orderBy('trade_date', 'desc')
                    ->first();

                if (!$price || !$price->implied_volatility) {
                    continue;
                }

                $smileData[] = [
                    'option_code' => $option->option_code,
                    'option_type' => $option->option_type,
                    'strike_price' => floatval($option->strike_price),
                    'implied_volatility' => floatval($price->implied_volatility),
                    'implied_volatility_percentage' => round($price->implied_volatility * 100, 2),
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

                $moneyness = $this->blackScholesService->getMoneyness(
                    $spotPrice,
                    $option->strike_price,
                    $option->option_type
                );

                $results[] = [
                    'option_id' => $option->id,
                    'option_code' => $option->option_code,
                    'option_type' => $option->option_type,
                    'strike_price' => $option->strike_price,
                    'theoretical_price' => $theoreticalPrice,
                    'greeks' => $greeks,
                    'moneyness' => $moneyness,
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