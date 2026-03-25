<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Option;
use App\Models\Prediction;
use App\Models\Stock;
use App\Services\PredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 預測模型 API 控制器
 *
 * 支援兩種預測標的：
 *   - 股票 (stock_symbol)
 *   - TXO 市場 (underlying)
 *
 * 路由：
 *   POST /api/predictions/run
 *   POST /api/predictions/lstm
 *   POST /api/predictions/arima
 *   POST /api/predictions/garch
 *   GET  /api/predictions/history
 *   GET  /api/predictions/{id}
 */
class PredictionController extends Controller
{
    protected PredictionService $predictionService;

    public function __construct(PredictionService $predictionService)
    {
        $this->predictionService = $predictionService;
    }

    // ==========================================
    // GET /api/predictions
    // 取得預測列表（支援多型篩選）
    // ==========================================

    public function index(Request $request): JsonResponse
    {
        $query = Prediction::with('predictable');

        // ✅ 用多型欄位篩選，移除舊的 stock_id
        if ($request->has('predictable_type')) {
            $query->where('predictable_type', $request->input('predictable_type'));
        }

        if ($request->has('predictable_id')) {
            $query->where('predictable_id', $request->input('predictable_id'));
        }

        // 便捷篩選：直接用 stock_symbol 查
        if ($request->has('stock_symbol')) {
            $stock = Stock::where('symbol', $request->input('stock_symbol'))->first();
            if ($stock) {
                $query->where('predictable_type', Stock::class)
                      ->where('predictable_id', $stock->id);
            }
        }

        if ($request->has('model_type')) {
            $query->where('model_type', $request->input('model_type'));
        }

        if ($request->has('start_date')) {
            $query->where('prediction_date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $query->where('prediction_date', '<=', $request->input('end_date'));
        }

        $sortBy    = $request->input('sort_by', 'prediction_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $predictions = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $predictions,
        ]);
    }

    // ==========================================
    // GET /api/predictions/history
    // 取得預測歷史（含標的資訊）
    // ==========================================

    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_symbol'    => 'nullable|string',
            'underlying'      => 'nullable|string',
            'model_type'      => 'nullable|in:lstm,arima,garch',
            'days'            => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $query = Prediction::with('predictable')
                ->orderBy('prediction_date', 'desc');

            // 股票篩選
            if ($request->has('stock_symbol')) {
                $stock = Stock::where('symbol', $request->input('stock_symbol'))->firstOrFail();
                $query->where('predictable_type', Stock::class)
                      ->where('predictable_id', $stock->id);
            }

            // TXO 市場篩選（Option 的 underlying）
            if ($request->has('underlying')) {
                $query->where('predictable_type', Option::class);
            }

            if ($request->has('model_type')) {
                $query->where('model_type', $request->input('model_type'));
            }

            // 最近 N 天
            if ($request->has('days')) {
                $query->where('prediction_date', '>=', now()->subDays((int) $request->input('days')));
            }

            $predictions = $query->paginate($request->input('per_page', 30));

            return response()->json([
                'success' => true,
                'data'    => $predictions,
            ]);
        } catch (\Exception $e) {
            Log::error('取得預測歷史失敗', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==========================================
    // GET /api/predictions/{id}
    // 取得單筆預測詳情
    // ==========================================

    public function show(int $id): JsonResponse
    {
        try {
            $prediction = Prediction::with('predictable')->findOrFail($id);

            // 計算誤差（如果已有實際資料）
            $error = $prediction->calculateError();

            return response()->json([
                'success' => true,
                'data'    => array_merge($prediction->toArray(), [
                    'error_analysis' => $error,
                    'trend'          => $prediction->trend,
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '找不到預測記錄: ' . $e->getMessage(),
            ], 404);
        }
    }

    // ==========================================
    // POST /api/predictions/run
    // 統一執行預測入口（支援股票 & TXO）
    // ==========================================

    public function run(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'stock_symbol'   => 'nullable|string',
            'underlying'     => 'nullable|string',
            'model_type'     => 'required|in:lstm,arima,garch',
            'prediction_days'=> 'nullable|integer|min:1|max:30',
            'parameters'     => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => '參數驗證失敗',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 至少需要提供一種標的
        if (!$request->has('stock_symbol') && !$request->has('underlying')) {
            return response()->json([
                'success' => false,
                'message' => '請提供 stock_symbol 或 underlying 參數',
            ], 422);
        }

        try {
            $modelType      = $request->input('model_type');
            $predictionDays = (int) $request->input('prediction_days', 1);
            $parameters     = $request->input('parameters', []);

            // --- TXO 市場預測 ---
            if ($request->has('underlying')) {
                return $this->handleTxoPrediction(
                    $request->input('underlying'),
                    $modelType,
                    $predictionDays,
                    $parameters
                );
            }

            // --- 股票預測 ---
            return $this->handleStockPrediction(
                $request->input('stock_symbol'),
                $modelType,
                $predictionDays,
                $parameters
            );
        } catch (\Exception $e) {
            Log::error('預測執行失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==========================================
    // POST /api/predictions/lstm
    // POST /api/predictions/arima
    // POST /api/predictions/garch
    // 個別模型捷徑
    // ==========================================

    public function lstm(Request $request): JsonResponse
    {
        $request->merge(['model_type' => 'lstm']);
        return $this->run($request);
    }

    public function arima(Request $request): JsonResponse
    {
        $request->merge(['model_type' => 'arima']);
        return $this->run($request);
    }

    public function garch(Request $request): JsonResponse
    {
        $request->merge(['model_type' => 'garch']);
        return $this->run($request);
    }

    // ==========================================
    // 私有輔助方法
    // ==========================================

    /**
     * 執行股票預測並回傳 JsonResponse
     */
    private function handleStockPrediction(
        string $symbol,
        string $modelType,
        int $predictionDays,
        array $parameters
    ): JsonResponse {
        $stock = Stock::where('symbol', $symbol)->firstOrFail();

        Log::info('開始股票預測', [
            'stock_id'   => $stock->id,
            'symbol'     => $stock->symbol,
            'model_type' => $modelType,
        ]);

        $result = match ($modelType) {
            'lstm'  => $this->predictionService->runLSTMPrediction($stock, $predictionDays, $parameters),
            'arima' => $this->predictionService->runARIMAPrediction($stock, $predictionDays, $parameters),
            'garch' => $this->predictionService->runGARCHPrediction($stock, $predictionDays, $parameters),
        };

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        $latestPrice  = $stock->latestPrice;
        $currentPrice = $latestPrice?->close;

        return response()->json([
            'success' => true,
            'message' => '預測完成',
            'data'    => [
                'target_info' => [
                    'type'   => 'stock',
                    'id'     => $stock->id,
                    'symbol' => $stock->symbol,
                    'name'   => $stock->name,
                ],
                'current_price'    => $currentPrice,
                'current_date'     => $latestPrice?->trade_date,
                'model_type'       => $modelType,
                'predictions'      => $result['predictions'],
                'historical_prices'=> $result['historical_prices'] ?? [],
                'metrics'          => $result['metrics'] ?? null,
                'model_info'       => $result['model_info'] ?? null,
            ],
        ]);
    }

    /**
     * 執行 TXO 市場預測並回傳 JsonResponse
     */
    private function handleTxoPrediction(
        string $underlying,
        string $modelType,
        int $predictionDays,
        array $parameters
    ): JsonResponse {
        Log::info('開始整體市場預測', [
            'underlying' => $underlying,
            'model_type' => $modelType,
        ]);

        $result = match ($modelType) {
            'lstm'  => $this->predictionService->runTxoMarketLSTMPrediction($underlying, $predictionDays, $parameters),
            'arima' => $this->predictionService->runTxoMarketARIMAPrediction($underlying, $predictionDays, $parameters),
            'garch' => $this->predictionService->runTxoMarketGARCHPrediction($underlying, $predictionDays, $parameters),
        };

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json([
            'success' => true,
            'message' => '預測完成',
            'data'    => [
                'target_info' => [
                    'type'       => 'market',
                    'underlying' => $underlying,
                    'name'       => 'TXO 台指選擇權',
                ],
                'current_price'    => $result['current_price'] ?? null,
                'current_date'     => $result['current_date'] ?? null,
                'model_type'       => $modelType,
                'predictions'      => $result['predictions'],
                'historical_prices'=> $result['historical_prices'] ?? [],
                'metrics'          => $result['metrics'] ?? null,
                'model_info'       => $result['model_info'] ?? null,
                'data_source'      => $result['data_source'] ?? 'TXO市場指數',
            ],
        ]);
    }
}
