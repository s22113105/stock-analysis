<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BlackScholesService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BlackScholesController extends Controller
{
    protected $blackScholesService;

    public function __construct(BlackScholesService $blackScholesService)
    {
        $this->blackScholesService = $blackScholesService;
    }

    /**
     * 計算選擇權理論價格
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spot_price' => 'required|numeric|min:0',
            'strike_price' => 'required|numeric|min:0',
            'time_to_expiry' => 'required|numeric|min:0',
            'risk_free_rate' => 'required|numeric|min:0|max:1',
            'volatility' => 'required|numeric|min:0|max:5',
            'option_type' => 'required|in:call,put'
        ]);

        try {
            $result = $this->blackScholesService->calculatePrice(
                $validated['spot_price'],
                $validated['strike_price'],
                $validated['time_to_expiry'],
                $validated['risk_free_rate'],
                $validated['volatility'],
                $validated['option_type']
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算隱含波動率
     */
    public function impliedVolatility(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'market_price' => 'required|numeric|min:0',
            'spot_price' => 'required|numeric|min:0',
            'strike_price' => 'required|numeric|min:0',
            'time_to_expiry' => 'required|numeric|min:0',
            'risk_free_rate' => 'required|numeric|min:0|max:1',
            'option_type' => 'required|in:call,put'
        ]);

        try {
            $iv = $this->blackScholesService->calculateImpliedVolatility(
                $validated['market_price'],
                $validated['spot_price'],
                $validated['strike_price'],
                $validated['time_to_expiry'],
                $validated['risk_free_rate'],
                $validated['option_type']
            );

            if ($iv === null) {
                return response()->json([
                    'success' => false,
                    'message' => '無法計算隱含波動率'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'implied_volatility' => $iv,
                    'annualized_percentage' => round($iv * 100, 2) . '%'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算選擇權鏈的理論價格
     */
    public function optionChain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spot_price' => 'required|numeric|min:0',
            'strikes' => 'required|array|min:1',
            'strikes.*' => 'numeric|min:0',
            'time_to_expiry' => 'required|numeric|min:0',
            'risk_free_rate' => 'required|numeric|min:0|max:1',
            'volatility' => 'required|numeric|min:0|max:5'
        ]);

        try {
            $chain = $this->blackScholesService->calculateOptionChain(
                $validated['spot_price'],
                $validated['strikes'],
                $validated['time_to_expiry'],
                $validated['risk_free_rate'],
                $validated['volatility']
            );

            return response()->json([
                'success' => true,
                'data' => $chain
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 計算波動率微笑
     */
    public function volatilitySmile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spot_price' => 'required|numeric|min:0',
            'option_data' => 'required|array|min:1',
            'option_data.*.strike' => 'required|numeric|min:0',
            'option_data.*.market_price' => 'required|numeric|min:0',
            'option_data.*.type' => 'required|in:call,put',
            'time_to_expiry' => 'required|numeric|min:0',
            'risk_free_rate' => 'required|numeric|min:0|max:1'
        ]);

        try {
            $smile = $this->blackScholesService->calculateVolatilitySmile(
                $validated['spot_price'],
                $validated['option_data'],
                $validated['time_to_expiry'],
                $validated['risk_free_rate']
            );

            return response()->json([
                'success' => true,
                'data' => $smile
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 批量計算 Greeks
     */
    public function batchGreeks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'options' => 'required|array|min:1',
            'options.*.spot_price' => 'required|numeric|min:0',
            'options.*.strike_price' => 'required|numeric|min:0',
            'options.*.time_to_expiry' => 'required|numeric|min:0',
            'options.*.risk_free_rate' => 'required|numeric|min:0|max:1',
            'options.*.volatility' => 'required|numeric|min:0|max:5',
            'options.*.option_type' => 'required|in:call,put'
        ]);

        try {
            $results = [];
            
            foreach ($validated['options'] as $index => $option) {
                $result = $this->blackScholesService->calculatePrice(
                    $option['spot_price'],
                    $option['strike_price'],
                    $option['time_to_expiry'],
                    $option['risk_free_rate'],
                    $option['volatility'],
                    $option['option_type']
                );
                
                $results[] = [
                    'index' => $index,
                    'price' => $result['price'],
                    'greeks' => $result['greeks']
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }
}