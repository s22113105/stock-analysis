<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 預測模型 API 控制器
 */
class PredictionController extends Controller
{
    /**
     * 取得預測列表
     *
     * GET /api/predictions
     */
    public function index(Request $request): JsonResponse
    {
        $query = Prediction::with('stock');

        // 股票篩選
        if ($request->has('stock_id')) {
            $query->where('stock_id', $request->input('stock_id'));
        }

        // 模型類型篩選
        if ($request->has('model_type')) {
            $query->where('model_type', $request->input('model_type'));
        }

        // 日期範圍篩選
        if ($request->has('start_date')) {
            $query->where('prediction_date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $query->where('prediction_date', '<=', $request->input('end_date'));
        }

        // 排序
        $sortBy = $request->input('sort_by', 'prediction_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // 分頁
        $perPage = $request->input('per_page', 20);
        $predictions = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $predictions
        ]);
    }

    /**
     * 執行預測
     *
     * POST /api/predictions/run
     */
    public function run(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'model_type' => 'required|in:lstm,arima,monte_carlo',
            'prediction_days' => 'nullable|integer|min:1|max:30',
            'parameters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stockId = $request->input('stock_id');
            $modelType = $request->input('model_type');
            $predictionDays = $request->input('prediction_days', 7);
            $parameters = $request->input('parameters', []);

            $stock = Stock::findOrFail($stockId);

            // 檢查是否有足夠的歷史資料
            $historicalDataCount = $stock->prices()->count();
            if ($historicalDataCount < 30) {
                return response()->json([
                    'success' => false,
                    'message' => '歷史資料不足，至少需要 30 天的資料才能進行預測'
                ], 400);
            }

            // 根據不同模型執行預測
            $predictions = [];
            switch ($modelType) {
                case 'lstm':
                    $predictions = $this->runLSTMPrediction($stock, $predictionDays, $parameters);
                    break;
                case 'arima':
                    $predictions = $this->runARIMAPrediction($stock, $predictionDays, $parameters);
                    break;
                case 'monte_carlo':
                    $predictions = $this->runMonteCarloPrediction($stock, $predictionDays, $parameters);
                    break;
            }

            // 儲存預測結果
            foreach ($predictions as $prediction) {
                Prediction::create([
                    'stock_id' => $stockId,
                    'model_type' => $modelType,
                    'prediction_date' => now(),
                    'target_date' => $prediction['target_date'],
                    'predicted_price' => $prediction['predicted_price'],
                    'confidence_lower' => $prediction['confidence_lower'],
                    'confidence_upper' => $prediction['confidence_upper'],
                    'confidence_level' => $prediction['confidence_level'] ?? 0.95,
                    'parameters' => json_encode($parameters),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => '預測完成',
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'model_type' => $modelType,
                    'predictions' => $predictions,
                    'total_predictions' => count($predictions),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('預測執行錯誤', [
                'stock_id' => $request->input('stock_id'),
                'model_type' => $request->input('model_type'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得單一預測詳情
     *
     * GET /api/predictions/{id}
     */
    public function show(int $id): JsonResponse
    {
        $prediction = Prediction::with('stock')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $prediction
        ]);
    }

    /**
     * 刪除預測
     *
     * DELETE /api/predictions/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $prediction = Prediction::findOrFail($id);
            $prediction->delete();

            return response()->json([
                'success' => true,
                'message' => '預測已刪除'
            ]);
        } catch (\Exception $e) {
            Log::error('預測刪除錯誤', [
                'prediction_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '刪除失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * LSTM 預測
     *
     * POST /api/predictions/lstm
     */
    public function lstm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'prediction_days' => 'nullable|integer|min:1|max:30',
            'epochs' => 'nullable|integer|min:10|max:1000',
            'units' => 'nullable|integer|min:16|max:512',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $request->merge([
            'model_type' => 'lstm',
            'parameters' => [
                'epochs' => $request->input('epochs', 100),
                'units' => $request->input('units', 128),
            ]
        ]);

        return $this->run($request);
    }

    /**
     * ARIMA 預測
     *
     * POST /api/predictions/arima
     */
    public function arima(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'prediction_days' => 'nullable|integer|min:1|max:30',
            'p' => 'nullable|integer|min:0|max:5',
            'd' => 'nullable|integer|min:0|max:2',
            'q' => 'nullable|integer|min:0|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $request->merge([
            'model_type' => 'arima',
            'parameters' => [
                'p' => $request->input('p', 1),
                'd' => $request->input('d', 1),
                'q' => $request->input('q', 1),
            ]
        ]);

        return $this->run($request);
    }

    /**
     * Monte Carlo 模擬預測
     *
     * POST /api/predictions/monte-carlo
     */
    public function monteCarlo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'prediction_days' => 'nullable|integer|min:1|max:30',
            'simulations' => 'nullable|integer|min:100|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $request->merge([
            'model_type' => 'monte_carlo',
            'parameters' => [
                'simulations' => $request->input('simulations', 1000),
            ]
        ]);

        return $this->run($request);
    }

    /**
     * 執行 LSTM 預測（簡化版本）
     */
    private function runLSTMPrediction(Stock $stock, int $days, array $parameters): array
    {
        // 這是簡化版本，實際應該調用 Python 模型或機器學習服務
        $latestPrice = $stock->latestPrice;
        $basePrice = $latestPrice ? $latestPrice->close : 100;

        $predictions = [];
        for ($i = 1; $i <= $days; $i++) {
            // 簡單的隨機遊走模擬（實際應使用 LSTM 模型）
            $change = mt_rand(-200, 200) / 100; // -2% ~ +2%
            $predictedPrice = $basePrice * (1 + $change / 100);

            $predictions[] = [
                'target_date' => now()->addDays($i)->format('Y-m-d'),
                'predicted_price' => round($predictedPrice, 2),
                'confidence_lower' => round($predictedPrice * 0.95, 2),
                'confidence_upper' => round($predictedPrice * 1.05, 2),
                'confidence_level' => 0.95,
            ];

            $basePrice = $predictedPrice;
        }

        return $predictions;
    }

    /**
     * 執行 ARIMA 預測（簡化版本）
     */
    private function runARIMAPrediction(Stock $stock, int $days, array $parameters): array
    {
        // 這是簡化版本，實際應該使用統計模型
        $latestPrice = $stock->latestPrice;
        $basePrice = $latestPrice ? $latestPrice->close : 100;

        // 計算歷史趨勢
        $recentPrices = $stock->prices()
            ->orderBy('trade_date', 'desc')
            ->limit(30)
            ->pluck('close')
            ->toArray();

        $trend = 0;
        if (count($recentPrices) > 1) {
            $trend = ($recentPrices[0] - end($recentPrices)) / count($recentPrices);
        }

        $predictions = [];
        for ($i = 1; $i <= $days; $i++) {
            $predictedPrice = $basePrice + ($trend * $i);

            $predictions[] = [
                'target_date' => now()->addDays($i)->format('Y-m-d'),
                'predicted_price' => round($predictedPrice, 2),
                'confidence_lower' => round($predictedPrice * 0.97, 2),
                'confidence_upper' => round($predictedPrice * 1.03, 2),
                'confidence_level' => 0.95,
            ];
        }

        return $predictions;
    }

    /**
     * 執行 Monte Carlo 預測（簡化版本）
     */
    private function runMonteCarloPrediction(Stock $stock, int $days, array $parameters): array
    {
        // 這是簡化版本，實際應該執行多次模擬
        $latestPrice = $stock->latestPrice;
        $basePrice = $latestPrice ? $latestPrice->close : 100;

        // 計算歷史波動率
        $recentPrices = $stock->prices()
            ->orderBy('trade_date', 'desc')
            ->limit(30)
            ->pluck('close')
            ->toArray();

        $returns = [];
        for ($i = 0; $i < count($recentPrices) - 1; $i++) {
            $returns[] = ($recentPrices[$i] - $recentPrices[$i + 1]) / $recentPrices[$i + 1];
        }

        $volatility = !empty($returns) ? $this->standardDeviation($returns) : 0.02;

        $predictions = [];
        for ($i = 1; $i <= $days; $i++) {
            // 使用 GBM (Geometric Brownian Motion)
            $drift = 0.0001; // 假設日報酬率
            $random = $this->randomNormal(0, 1);
            $change = $drift + ($volatility * $random);

            $predictedPrice = $basePrice * exp($change);

            $predictions[] = [
                'target_date' => now()->addDays($i)->format('Y-m-d'),
                'predicted_price' => round($predictedPrice, 2),
                'confidence_lower' => round($predictedPrice * (1 - 2 * $volatility), 2),
                'confidence_upper' => round($predictedPrice * (1 + 2 * $volatility), 2),
                'confidence_level' => 0.95,
            ];

            $basePrice = $predictedPrice;
        }

        return $predictions;
    }

    /**
     * 計算標準差
     */
    private function standardDeviation(array $data): float
    {
        if (empty($data)) {
            return 0;
        }

        $mean = array_sum($data) / count($data);
        $variance = array_sum(array_map(function ($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $data)) / count($data);

        return sqrt($variance);
    }

    /**
     * 生成標準常態分佈隨機數
     */
    private function randomNormal(float $mean = 0, float $stdDev = 1): float
    {
        // Box-Muller Transform
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();

        $z = sqrt(-2 * log($u1)) * cos(2 * pi() * $u2);

        return $mean + ($stdDev * $z);
    }
}
