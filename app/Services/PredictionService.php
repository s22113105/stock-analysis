<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\Prediction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Carbon\Carbon;

/**
 * 預測服務類別
 * 整合 Python 機器學習模型進行股價預測
 */
class PredictionService
{
    /**
     * Python 腳本路徑
     */
    private const PYTHON_MODELS_PATH = '/var/www/python/models/';

    /**
     * 支援的模型類型
     */
    private const SUPPORTED_MODELS = [
        'lstm' => 'lstm_model.py',
        'arima' => 'arima_model.py',
        'garch' => 'garch_model.py',
    ];

    /**
     * 執行 LSTM 預測
     *
     * @param Stock $stock
     * @param int $predictionDays
     * @param array $parameters
     * @return array
     */
    public function runLSTMPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            // 取得歷史價格資料（至少100天）
            $prices = $this->getHistoricalPrices($stock, 200);

            if (count($prices) < 100) {
                throw new \Exception('歷史資料不足，LSTM 模型需要至少 100 天的資料');
            }

            // 準備輸入資料
            $inputData = [
                'prices' => $prices,
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'epochs' => $parameters['epochs'] ?? 100,
                'units' => $parameters['units'] ?? 128,
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('lstm', $inputData);

            // 儲存預測結果到資料庫
            if ($result['success']) {
                $this->savePredictions($stock->id, 'lstm', $result['predictions'], $parameters);

                // 記錄模型指標
                Log::info('LSTM 預測完成', [
                    'stock_id' => $stock->id,
                    'metrics' => $result['metrics'] ?? []
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('LSTM 預測失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * 執行 ARIMA 預測
     *
     * @param Stock $stock
     * @param int $predictionDays
     * @param array $parameters
     * @return array
     */
    public function runARIMAPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            // 取得歷史價格資料（至少30天）
            $prices = $this->getHistoricalPrices($stock, 100);

            if (count($prices) < 30) {
                throw new \Exception('歷史資料不足，ARIMA 模型需要至少 30 天的資料');
            }

            // 準備輸入資料
            $inputData = [
                'prices' => $prices,
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'p' => $parameters['p'] ?? null,
                'd' => $parameters['d'] ?? null,
                'q' => $parameters['q'] ?? null,
                'auto_select' => $parameters['auto_select'] ?? true,
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('arima', $inputData);

            // 儲存預測結果到資料庫
            if ($result['success']) {
                $this->savePredictions($stock->id, 'arima', $result['predictions'], $parameters);

                // 記錄模型資訊
                Log::info('ARIMA 預測完成', [
                    'stock_id' => $stock->id,
                    'model_info' => $result['model_info'] ?? [],
                    'diagnostics' => $result['diagnostics'] ?? []
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('ARIMA 預測失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * 執行 GARCH 波動率預測
     *
     * @param Stock $stock
     * @param int $predictionDays
     * @param array $parameters
     * @return array
     */
    public function runGARCHPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            // 取得歷史價格資料（至少100天）
            $prices = $this->getHistoricalPrices($stock, 200);

            if (count($prices) < 100) {
                throw new \Exception('歷史資料不足，GARCH 模型需要至少 100 天的資料');
            }

            // 準備輸入資料
            $inputData = [
                'prices' => $prices,
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'p' => $parameters['p'] ?? 1,
                'q' => $parameters['q'] ?? 1,
                'dist' => $parameters['dist'] ?? 'normal',
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('garch', $inputData);

            // 儲存波動率預測結果
            if ($result['success']) {
                $this->saveVolatilityPredictions($stock->id, $result['predictions'], $parameters);

                // 記錄風險指標
                Log::info('GARCH 預測完成', [
                    'stock_id' => $stock->id,
                    'model_info' => $result['model_info'] ?? [],
                    'risk_metrics' => $result['risk_metrics'] ?? []
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('GARCH 預測失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * 執行 Monte Carlo 模擬
     *
     * @param Stock $stock
     * @param int $predictionDays
     * @param array $parameters
     * @return array
     */
    public function runMonteCarloSimulation(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            $simulations = $parameters['simulations'] ?? 1000;
            $prices = $this->getHistoricalPrices($stock, 100);

            if (count($prices) < 30) {
                throw new \Exception('歷史資料不足，需要至少 30 天的資料');
            }

            // 計算歷史報酬率統計
            $returns = [];
            for ($i = 1; $i < count($prices); $i++) {
                $returns[] = ($prices[$i] - $prices[$i - 1]) / $prices[$i - 1];
            }

            $meanReturn = array_sum($returns) / count($returns);
            $stdReturn = $this->calculateStdDev($returns);
            $currentPrice = end($prices);

            // 執行蒙地卡羅模擬
            $simulationResults = [];
            for ($sim = 0; $sim < $simulations; $sim++) {
                $path = [$currentPrice];
                $price = $currentPrice;

                for ($day = 0; $day < $predictionDays; $day++) {
                    // 生成隨機報酬率（常態分配）
                    $randomReturn = $this->generateNormalRandom($meanReturn, $stdReturn);
                    $price = $price * (1 + $randomReturn);
                    $path[] = $price;
                }

                $simulationResults[] = $path;
            }

            // 計算預測統計
            $predictions = [];
            for ($day = 1; $day <= $predictionDays; $day++) {
                $dayPrices = array_column($simulationResults, $day);

                $predictions[] = [
                    'target_date' => Carbon::now()->addDays($day)->format('Y-m-d'),
                    'predicted_price' => round(array_sum($dayPrices) / count($dayPrices), 2),
                    'confidence_lower' => round($this->percentile($dayPrices, 2.5), 2),
                    'confidence_upper' => round($this->percentile($dayPrices, 97.5), 2),
                    'confidence_level' => 0.95,
                ];
            }

            // 儲存預測結果
            $this->savePredictions($stock->id, 'monte_carlo', $predictions, $parameters);

            return [
                'success' => true,
                'predictions' => $predictions,
                'simulations' => $simulations,
                'paths_sample' => array_slice($simulationResults, 0, 10), // 返回部分路徑供視覺化
            ];
        } catch (\Exception $e) {
            Log::error('Monte Carlo 模擬失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * 執行 Python 模型
     *
     * @param string $modelType
     * @param array $inputData
     * @return array
     */
    private function executePythonModel(string $modelType, array $inputData): array
    {
        if (!isset(self::SUPPORTED_MODELS[$modelType])) {
            throw new \Exception("不支援的模型類型: {$modelType}");
        }

        $scriptPath = self::PYTHON_MODELS_PATH . self::SUPPORTED_MODELS[$modelType];
        $inputJson = json_encode($inputData);

        // 執行 Python 腳本
        $result = Process::run("python3 {$scriptPath} '{$inputJson}'");

        if (!$result->successful()) {
            Log::error('Python 腳本執行失敗', [
                'model' => $modelType,
                'error' => $result->errorOutput(),
                'output' => $result->output()
            ]);

            throw new \Exception("Python 模型執行失敗: " . $result->errorOutput());
        }

        // 解析輸出
        $output = json_decode($result->output(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("無法解析 Python 輸出: " . json_last_error_msg());
        }

        return $output;
    }

    /**
     * 取得歷史價格資料
     *
     * @param Stock $stock
     * @param int $days
     * @return array
     */
    private function getHistoricalPrices(Stock $stock, int $days = 100): array
    {
        $prices = $stock->prices()
            ->orderBy('trade_date', 'desc')
            ->limit($days)
            ->pluck('close')
            ->reverse()
            ->values()
            ->toArray();

        return $prices;
    }

    /**
     * 儲存預測結果到資料庫
     *
     * @param int $stockId
     * @param string $modelType
     * @param array $predictions
     * @param array $parameters
     */
    private function savePredictions(int $stockId, string $modelType, array $predictions, array $parameters): void
    {
        foreach ($predictions as $prediction) {
            Prediction::create([
                'stock_id' => $stockId,
                'model_type' => $modelType,
                'prediction_date' => Carbon::now(),
                'target_date' => $prediction['target_date'],
                'predicted_price' => $prediction['predicted_price'],
                'confidence_lower' => $prediction['confidence_lower'],
                'confidence_upper' => $prediction['confidence_upper'],
                'confidence_level' => $prediction['confidence_level'] ?? 0.95,
                'parameters' => json_encode($parameters),
            ]);
        }
    }

    /**
     * 儲存波動率預測結果
     *
     * @param int $stockId
     * @param array $predictions
     * @param array $parameters
     */
    private function saveVolatilityPredictions(int $stockId, array $predictions, array $parameters): void
    {
        foreach ($predictions as $prediction) {
            // 儲存到 volatilities 表或專門的預測表
            \DB::table('volatilities')->insert([
                'stock_id' => $stockId,
                'date' => $prediction['target_date'],
                'predicted_volatility' => $prediction['predicted_volatility'],
                'model_type' => 'garch',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * 計算標準差
     *
     * @param array $values
     * @return float
     */
    private function calculateStdDev(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        $variance /= count($values);
        return sqrt($variance);
    }

    /**
     * 生成常態分配隨機數
     *
     * @param float $mean
     * @param float $stdDev
     * @return float
     */
    private function generateNormalRandom(float $mean, float $stdDev): float
    {
        // Box-Muller 轉換
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();

        $z0 = sqrt(-2 * log($u1)) * cos(2 * pi() * $u2);

        return $mean + $stdDev * $z0;
    }

    /**
     * 計算百分位數
     *
     * @param array $values
     * @param float $percentile
     * @return float
     */
    private function percentile(array $values, float $percentile): float
    {
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);

        if (floor($index) == $index) {
            return $values[$index];
        }

        $lower = floor($index);
        $upper = ceil($index);
        $weight = $index - $lower;

        return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
    }

    /**
     * 比較不同模型的預測結果
     *
     * @param Stock $stock
     * @param array $modelTypes
     * @param int $predictionDays
     * @return array
     */
    public function compareModels(Stock $stock, array $modelTypes, int $predictionDays = 7): array
    {
        $results = [];

        foreach ($modelTypes as $modelType) {
            try {
                switch ($modelType) {
                    case 'lstm':
                        $result = $this->runLSTMPrediction($stock, $predictionDays);
                        break;
                    case 'arima':
                        $result = $this->runARIMAPrediction($stock, $predictionDays);
                        break;
                    case 'garch':
                        $result = $this->runGARCHPrediction($stock, $predictionDays);
                        break;
                    case 'monte_carlo':
                        $result = $this->runMonteCarloSimulation($stock, $predictionDays);
                        break;
                    default:
                        continue 2;
                }

                $results[$modelType] = $result;
            } catch (\Exception $e) {
                $results[$modelType] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}
