<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use App\Models\Stock;
use App\Services\PredictionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 預測模型 API 控制器（更新版）
 * 整合真實的 Python 機器學習模型
 */
class PredictionController extends Controller
{
    /**
     * 預測服務實例
     */
    protected PredictionService $predictionService;

    /**
     * 建構函數
     */
    public function __construct(PredictionService $predictionService)
    {
        $this->predictionService = $predictionService;
    }

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
            'model_type' => 'required|in:lstm,arima,garch,monte_carlo',
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

            // 根據不同模型執行預測
            $result = match ($modelType) {
                'lstm' => $this->predictionService->runLSTMPrediction($stock, $predictionDays, $parameters),
                'arima' => $this->predictionService->runARIMAPrediction($stock, $predictionDays, $parameters),
                'garch' => $this->predictionService->runGARCHPrediction($stock, $predictionDays, $parameters),
                'monte_carlo' => $this->predictionService->runMonteCarloSimulation($stock, $predictionDays, $parameters),
            };

            if (!$result['success']) {
                return response()->json($result, 400);
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
                    'predictions' => $result['predictions'],
                    'metrics' => $result['metrics'] ?? null,
                    'model_info' => $result['model_info'] ?? null,
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
                'p' => $request->input('p', null),
                'd' => $request->input('d', null),
                'q' => $request->input('q', null),
                'auto_select' => true,
            ]
        ]);

        return $this->run($request);
    }

    /**
     * GARCH 波動率預測
     *
     * POST /api/predictions/garch
     */
    public function garch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'prediction_days' => 'nullable|integer|min:1|max:30',
            'p' => 'nullable|integer|min:1|max:3',
            'q' => 'nullable|integer|min:1|max:3',
            'dist' => 'nullable|in:normal,t,skewt',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        $request->merge([
            'model_type' => 'garch',
            'parameters' => [
                'p' => $request->input('p', 1),
                'q' => $request->input('q', 1),
                'dist' => $request->input('dist', 'normal'),
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
     * 比較多個模型的預測結果
     *
     * POST /api/predictions/compare
     */
    public function compare(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_id' => 'required|integer|exists:stocks,id',
            'models' => 'required|array|min:2',
            'models.*' => 'in:lstm,arima,garch,monte_carlo',
            'prediction_days' => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $stock = Stock::findOrFail($request->input('stock_id'));
            $models = $request->input('models');
            $predictionDays = $request->input('prediction_days', 7);

            $results = $this->predictionService->compareModels($stock, $models, $predictionDays);

            // 準備比較圖表資料
            $chartData = $this->prepareComparisonChartData($results);

            return response()->json([
                'success' => true,
                'message' => '模型比較完成',
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'results' => $results,
                    'chart_data' => $chartData,
                    'comparison_date' => now()->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('模型比較失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '模型比較失敗: ' . $e->getMessage()
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
     * 準備比較圖表資料
     *
     * @param array $results
     * @return array
     */
    private function prepareComparisonChartData(array $results): array
    {
        $chartData = [
            'labels' => [],
            'datasets' => []
        ];

        // 取得所有日期標籤
        foreach ($results as $modelType => $result) {
            if ($result['success'] && isset($result['predictions'])) {
                $chartData['labels'] = array_column($result['predictions'], 'target_date');
                break;
            }
        }

        // 準備各模型的資料集
        $colors = [
            'lstm' => 'rgb(75, 192, 192)',
            'arima' => 'rgb(255, 99, 132)',
            'garch' => 'rgb(54, 162, 235)',
            'monte_carlo' => 'rgb(255, 206, 86)',
        ];

        foreach ($results as $modelType => $result) {
            if ($result['success'] && isset($result['predictions'])) {
                $dataset = [
                    'label' => strtoupper($modelType),
                    'data' => array_column($result['predictions'], 'predicted_price'),
                    'borderColor' => $colors[$modelType] ?? 'rgb(201, 203, 207)',
                    'backgroundColor' => 'transparent',
                    'tension' => 0.1
                ];

                // 加入信賴區間
                if (isset($result['predictions'][0]['confidence_upper'])) {
                    $dataset['upper'] = array_column($result['predictions'], 'confidence_upper');
                    $dataset['lower'] = array_column($result['predictions'], 'confidence_lower');
                }

                $chartData['datasets'][] = $dataset;
            }
        }

        return $chartData;
    }
}
