<?php

namespace App\Services;

use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PredictionService 選擇權預測方法擴展
 *
 * 這個檔案包含選擇權預測的方法,應該被加入到 PredictionService.php 中
 */
trait PredictionServiceOptionMethods
{
    /**
     * 執行選擇權 LSTM 預測
     *
     * @param Option $option
     * @param int $predictionDays
     * @param array $parameters
     * @return array
     */
    public function runOptionLSTMPrediction(Option $option, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行選擇權 LSTM 預測', [
                'option_id' => $option->id,
                'option_code' => $option->option_code,
                'prediction_days' => $predictionDays
            ]);

            // 直接從資料庫取得歷史價格資料（至少需要100天）
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalOptionPricesFromDB($option, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,LSTM 模型需要至少 100 天的資料。目前只有 " . count($prices) . " 天的資料。"
                ];
            }

            Log::info('取得選擇權歷史資料', [
                'option_id' => $option->id,
                'data_points' => count($prices),
                'date_range' => [
                    'from' => $prices[0]['date'] ?? null,
                    'to' => end($prices)['date'] ?? null
                ]
            ]);

            // 準備輸入資料
            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'volumes' => array_column($prices, 'volume'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => $option->option_code,
                'epochs' => $parameters['epochs'] ?? 100,
                'units' => $parameters['units'] ?? 128,
                'lookback' => $parameters['lookback'] ?? 60,
                'dropout' => $parameters['dropout'] ?? 0.2,
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('lstm', $inputData);

            // 儲存預測結果到資料庫
            if ($result['success']) {
                $this->saveOptionPredictions($option->id, 'lstm', $result['predictions'], $parameters);

                // 記錄模型指標
                Log::info('選擇權 LSTM 預測完成', [
                    'option_id' => $option->id,
                    'option_code' => $option->option_code,
                    'metrics' => $result['metrics'] ?? [],
                    'predictions_count' => count($result['predictions'])
                ]);
            }

            // 加入歷史價格資料到回傳結果
            $result['historical_prices'] = $prices;

            return $result;
        } catch (\Exception $e) {
            Log::error('選擇權 LSTM 預測失敗', [
                'option_id' => $option->id,
                'option_code' => $option->option_code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 執行選擇權 ARIMA 預測
     *
     * @param Option $option
     * @param int $predictionDays
     * @param array $parameters
     * @return array
     */
    public function runOptionARIMAPrediction(Option $option, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行選擇權 ARIMA 預測', [
                'option_id' => $option->id,
                'option_code' => $option->option_code,
                'prediction_days' => $predictionDays
            ]);

            // 直接從資料庫取得歷史價格資料（至少需要30天）
            $historicalDays = $parameters['historical_days'] ?? 100;
            $prices = $this->getHistoricalOptionPricesFromDB($option, $historicalDays);

            if (count($prices) < 30) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,ARIMA 模型需要至少 30 天的資料。目前只有 " . count($prices) . " 天的資料。"
                ];
            }

            // 準備輸入資料
            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => $option->option_code,
                'p' => $parameters['p'] ?? null,
                'd' => $parameters['d'] ?? null,
                'q' => $parameters['q'] ?? null,
                'auto_select' => $parameters['auto_select'] ?? true,
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('arima', $inputData);

            // 儲存預測結果到資料庫
            if ($result['success']) {
                $this->saveOptionPredictions($option->id, 'arima', $result['predictions'], $parameters);

                // 記錄模型資訊
                Log::info('選擇權 ARIMA 預測完成', [
                    'option_id' => $option->id,
                    'option_code' => $option->option_code,
                    'model_info' => $result['model_info'] ?? [],
                    'diagnostics' => $result['diagnostics'] ?? []
                ]);
            }

            // 加入歷史價格資料到回傳結果
            $result['historical_prices'] = $prices;

            return $result;
        } catch (\Exception $e) {
            Log::error('選擇權 ARIMA 預測失敗', [
                'option_id' => $option->id,
                'option_code' => $option->option_code,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 執行選擇權 GARCH 波動率預測
     *
     * @param Option $option
     * @param int $predictionDays
     * @param array $parameters
     * @return array
     */
    public function runOptionGARCHPrediction(Option $option, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行選擇權 GARCH 預測', [
                'option_id' => $option->id,
                'option_code' => $option->option_code,
                'prediction_days' => $predictionDays
            ]);

            // 直接從資料庫取得歷史價格資料（至少需要100天）
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalOptionPricesFromDB($option, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,GARCH 模型需要至少 100 天的資料。目前只有 " . count($prices) . " 天的資料。"
                ];
            }

            // 準備輸入資料
            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => $option->option_code,
                'p' => $parameters['p'] ?? 1,
                'q' => $parameters['q'] ?? 1,
                'dist' => $parameters['dist'] ?? 'normal',
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('garch', $inputData);

            // 儲存波動率預測結果
            if ($result['success']) {
                $this->saveOptionPredictions($option->id, 'garch', $result['predictions'], $parameters);

                // 記錄風險指標
                Log::info('選擇權 GARCH 預測完成', [
                    'option_id' => $option->id,
                    'option_code' => $option->option_code,
                    'model_info' => $result['model_info'] ?? [],
                    'risk_metrics' => $result['risk_metrics'] ?? []
                ]);
            }

            // 加入歷史價格資料到回傳結果
            $result['historical_prices'] = $prices;

            return $result;
        } catch (\Exception $e) {
            Log::error('選擇權 GARCH 預測失敗', [
                'option_id' => $option->id,
                'option_code' => $option->option_code,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 從資料庫取得選擇權歷史價格資料
     *
     * @param Option $option
     * @param int $days
     * @return array
     */
    private function getHistoricalOptionPricesFromDB(Option $option, int $days = 100): array
    {
        // 使用 Eloquent ORM 從資料庫取得資料
        $prices = OptionPrice::where('option_id', $option->id)
            ->orderBy('trade_date', 'desc')
            ->limit($days)
            ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
            ->map(function ($price) {
                return [
                    'date' => $price->trade_date,
                    'open' => (float) $price->open,
                    'high' => (float) $price->high,
                    'low' => (float) $price->low,
                    'close' => (float) $price->close,
                    'volume' => (int) $price->volume,
                ];
            })
            ->reverse()  // 反轉順序,從舊到新
            ->values()
            ->toArray();

        // 記錄資料取得情況
        Log::info('從資料庫取得選擇權歷史價格', [
            'option_id' => $option->id,
            'option_code' => $option->option_code,
            'requested_days' => $days,
            'actual_days' => count($prices),
            'date_range' => count($prices) > 0 ? [
                'from' => $prices[0]['date'],
                'to' => end($prices)['date']
            ] : null
        ]);

        return $prices;
    }

    /**
     * 儲存選擇權預測結果到資料庫
     *
     * @param int $optionId
     * @param string $modelType
     * @param array $predictions
     * @param array $parameters
     */
    private function saveOptionPredictions(int $optionId, string $modelType, array $predictions, array $parameters): void
    {
        try {
            DB::beginTransaction();

            foreach ($predictions as $prediction) {
                Prediction::create([
                    'predictable_type' => Option::class,
                    'predictable_id' => $optionId,
                    'model_type' => $modelType,
                    'prediction_date' => now(),
                    'prediction_days' => 1,
                    'predicted_price' => $prediction['predicted_price'] ?? null,
                    'predicted_volatility' => $prediction['predicted_volatility'] ?? null,
                    'upper_bound' => $prediction['confidence_upper'] ?? null,
                    'lower_bound' => $prediction['confidence_lower'] ?? null,
                    'confidence_level' => $prediction['confidence_level'] ?? 0.95,
                    'parameters' => json_encode($parameters),
                ]);
            }

            DB::commit();

            Log::info('選擇權預測結果已儲存', [
                'option_id' => $optionId,
                'model_type' => $modelType,
                'predictions_count' => count($predictions)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('儲存選擇權預測結果失敗', [
                'option_id' => $optionId,
                'model_type' => $modelType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
