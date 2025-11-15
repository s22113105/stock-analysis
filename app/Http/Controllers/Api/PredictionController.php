<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prediction;
use App\Models\Stock;
use App\Models\Option;
use App\Services\PredictionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 預測模型 API 控制器（支援股票和選擇權）
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
        $query = Prediction::query();

        // 根據 predictable_type 篩選
        if ($request->has('predictable_type')) {
            $query->where('predictable_type', $request->input('predictable_type'));
        }

        // 根據 predictable_id 篩選
        if ($request->has('predictable_id')) {
            $query->where('predictable_id', $request->input('predictable_id'));
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
     * 執行預測（支援股票和選擇權）
     *
     * POST /api/predictions/run
     */
    public function run(Request $request): JsonResponse
    {
        // 驗證規則
        $validator = Validator::make($request->all(), [
            'stock_symbol' => 'required_without:option_id|string',
            'option_id' => 'required_without:stock_symbol|integer|exists:options,id',
            'model_type' => 'required|in:lstm,arima,garch',
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
            $modelType = $request->input('model_type');
            $predictionDays = $request->input('prediction_days', 1);
            $parameters = $request->input('parameters', []);

            // 判斷預測標的類型
            if ($request->has('option_id')) {
                // 選擇權預測
                $option = Option::with('latestPrice')->findOrFail($request->input('option_id'));

                Log::info('開始選擇權預測', [
                    'option_id' => $option->id,
                    'option_code' => $option->option_code,
                    'model_type' => $modelType
                ]);

                // 根據不同模型執行選擇權預測
                $result = match ($modelType) {
                    'lstm' => $this->predictionService->runOptionLSTMPrediction($option, $predictionDays, $parameters),
                    'arima' => $this->predictionService->runOptionARIMAPrediction($option, $predictionDays, $parameters),
                    'garch' => $this->predictionService->runOptionGARCHPrediction($option, $predictionDays, $parameters),
                };

                if (!$result['success']) {
                    return response()->json($result, 400);
                }

                // 取得當前價格
                $latestPrice = $option->latestPrice;
                $currentPrice = $latestPrice ? $latestPrice->close : null;

                return response()->json([
                    'success' => true,
                    'message' => '預測完成',
                    'data' => [
                        'target_info' => [
                            'type' => 'option',
                            'id' => $option->id,
                            'option_code' => $option->option_code,
                            'option_type' => $option->option_type,
                            'strike_price' => $option->strike_price,
                            'expiry_date' => $option->expiry_date,
                        ],
                        'current_price' => $currentPrice,
                        'current_date' => $latestPrice ? $latestPrice->trade_date : null,
                        'model_type' => $modelType,
                        'predictions' => $result['predictions'],
                        'historical_prices' => $result['historical_prices'] ?? [],
                        'metrics' => $result['metrics'] ?? null,
                        'model_info' => $result['model_info'] ?? null,
                    ]
                ]);

            } else {
                // 股票預測
                $stock = Stock::where('symbol', $request->input('stock_symbol'))->firstOrFail();

                Log::info('開始股票預測', [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'model_type' => $modelType
                ]);

                // 根據不同模型執行股票預測
                $result = match ($modelType) {
                    'lstm' => $this->predictionService->runLSTMPrediction($stock, $predictionDays, $parameters),
                    'arima' => $this->predictionService->runARIMAPrediction($stock, $predictionDays, $parameters),
                    'garch' => $this->predictionService->runGARCHPrediction($stock, $predictionDays, $parameters),
                };

                if (!$result['success']) {
                    return response()->json($result, 400);
                }

                // 取得當前價格
                $latestPrice = $stock->latestPrice;
                $currentPrice = $latestPrice ? $latestPrice->close : null;

                return response()->json([
                    'success' => true,
                    'message' => '預測完成',
                    'data' => [
                        'target_info' => [
                            'type' => 'stock',
                            'id' => $stock->id,
                            'symbol' => $stock->symbol,
                            'name' => $stock->name,
                        ],
                        'current_price' => $currentPrice,
                        'current_date' => $latestPrice ? $latestPrice->trade_date : null,
                        'model_type' => $modelType,
                        'predictions' => $result['predictions'],
                        'historical_prices' => $result['historical_prices'] ?? [],
                        'metrics' => $result['metrics'] ?? null,
                        'model_info' => $result['model_info'] ?? null,
                    ]
                ]);
            }
        } catch (\Exception $e) {
            Log::error('預測執行錯誤', [
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
     * LSTM 預測（快捷路由）
     *
     * POST /api/predictions/lstm
     */
    public function lstm(Request $request): JsonResponse
    {
        $request->merge(['model_type' => 'lstm']);
        return $this->run($request);
    }

    /**
     * ARIMA 預測（快捷路由）
     *
     * POST /api/predictions/arima
     */
    public function arima(Request $request): JsonResponse
    {
        $request->merge(['model_type' => 'arima']);
        return $this->run($request);
    }

    /**
     * GARCH 預測（快捷路由）
     *
     * POST /api/predictions/garch
     */
    public function garch(Request $request): JsonResponse
    {
        $request->merge(['model_type' => 'garch']);
        return $this->run($request);
    }

    /**
     * 比較多個模型
     *
     * POST /api/predictions/compare
     */
    public function compare(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_symbol' => 'required_without:option_id|string',
            'option_id' => 'required_without:stock_symbol|integer|exists:options,id',
            'models' => 'required|array',
            'models.*' => 'in:lstm,arima,garch',
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
            $models = $request->input('models');
            $predictionDays = $request->input('prediction_days', 7);
            $results = [];

            foreach ($models as $modelType) {
                try {
                    $request->merge(['model_type' => $modelType]);
                    $response = $this->run($request);
                    $responseData = json_decode($response->getContent(), true);

                    if ($responseData['success']) {
                        $results[$modelType] = $responseData['data'];
                    } else {
                        $results[$modelType] = [
                            'success' => false,
                            'error' => $responseData['message']
                        ];
                    }
                } catch (\Exception $e) {
                    $results[$modelType] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => '模型比較完成',
                'data' => [
                    'results' => $results,
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
        $prediction = Prediction::with('predictable')->findOrFail($id);

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
}
