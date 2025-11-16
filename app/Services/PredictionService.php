<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;           // 🆕 必須加入
use App\Models\OptionPrice;      // 🆕 必須加入
use App\Models\Prediction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 預測服務類別
 * 整合 Python 機器學習模型進行股價和市場預測
 * 支援:
 * 1. 股票預測 (Stock)
 * 2. TXO 整體市場預測 (Underlying)
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

    // ========================================
    // 股票預測方法
    // ========================================

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
            Log::info('開始執行 LSTM 預測', [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'prediction_days' => $predictionDays
            ]);

            // 直接從資料庫取得歷史價格資料
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,LSTM 模型需要至少 100 天的資料。目前只有 " . count($prices) . " 天的資料。"
                ];
            }

            // 準備輸入資料
            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'volumes' => array_column($prices, 'volume'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol' => $stock->symbol,
                'epochs' => $parameters['epochs'] ?? 100,
                'units' => $parameters['units'] ?? 128,
                'lookback' => $parameters['lookback'] ?? 60,
                'dropout' => $parameters['dropout'] ?? 0.2,
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('lstm', $inputData);

            if ($result['success']) {
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('LSTM 預測失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 執行 ARIMA 預測
     */
    public function runARIMAPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            Log::info('開始執行 ARIMA 預測', [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol
            ]);

            $historicalDays = $parameters['historical_days'] ?? 100;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 30) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,ARIMA 模型需要至少 30 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol' => $stock->symbol,
                'p' => $parameters['p'] ?? null,
                'd' => $parameters['d'] ?? null,
                'q' => $parameters['q'] ?? null,
                'auto_select' => $parameters['auto_select'] ?? true,
            ];

            $result = $this->executePythonModel('arima', $inputData);

            if ($result['success']) {
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('ARIMA 預測失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 執行 GARCH 波動率預測
     */
    public function runGARCHPrediction(Stock $stock, int $predictionDays = 7, array $parameters = []): array
    {
        try {
            Log::info('開始執行 GARCH 預測', [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol
            ]);

            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalPricesFromDB($stock, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,GARCH 模型需要至少 100 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'stock_symbol' => $stock->symbol,
                'p' => $parameters['p'] ?? 1,
                'q' => $parameters['q'] ?? 1,
                'dist' => $parameters['dist'] ?? 'normal',
            ];

            $result = $this->executePythonModel('garch', $inputData);

            if ($result['success']) {
                $result['historical_prices'] = $prices;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('GARCH 預測失敗', [
                'stock_id' => $stock->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    // ========================================
    // TXO 整體市場預測方法
    // ========================================

    /**
     * 執行 TXO 整體市場 LSTM 預測
     * 使用主力契約(成交量最大的近月平價契約)作為代表
     */
    public function runUnderlyingLSTMPrediction(string $underlying, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行 TXO 整體 LSTM 預測', [
                'underlying' => $underlying,
                'prediction_days' => $predictionDays
            ]);

            // 找到主力契約
            $representativeOption = $this->findRepresentativeOption($underlying);

            if (!$representativeOption) {
                return [
                    'success' => false,
                    'message' => "找不到 {$underlying} 的代表性契約,請確認是否有資料"
                ];
            }

            Log::info('使用代表性契約', [
                'option_id' => $representativeOption->id,
                'option_code' => $representativeOption->option_code,
                'strike_price' => $representativeOption->strike_price
            ]);

            // 使用代表性契約的歷史資料
            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalOptionPricesFromDB($representativeOption, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,LSTM 模型需要至少 100 天的資料。目前只有 " . count($prices) . " 天的資料。"
                ];
            }

            // 準備輸入資料
            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'volumes' => array_column($prices, 'volume'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => $representativeOption->option_code,
                'epochs' => $parameters['epochs'] ?? 100,
                'units' => $parameters['units'] ?? 128,
                'lookback' => $parameters['lookback'] ?? 60,
                'dropout' => $parameters['dropout'] ?? 0.2,
            ];

            // 執行 Python 腳本
            $result = $this->executePythonModel('lstm', $inputData);

            if ($result['success']) {
                $result['data_source'] = "TXO主力契約: {$representativeOption->option_code}";
                $result['representative_option'] = [
                    'id' => $representativeOption->id,
                    'option_code' => $representativeOption->option_code,
                    'strike_price' => $representativeOption->strike_price,
                    'option_type' => $representativeOption->option_type,
                ];
                $result['historical_prices'] = $prices;

                $latestPrice = $representativeOption->latestPrice;
                $result['current_price'] = $latestPrice ? $latestPrice->close : null;
                $result['current_date'] = $latestPrice ? $latestPrice->trade_date : null;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('TXO 整體 LSTM 預測失敗', [
                'underlying' => $underlying,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 執行 TXO 整體市場 ARIMA 預測
     */
    public function runUnderlyingARIMAPrediction(string $underlying, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行 TXO 整體 ARIMA 預測', [
                'underlying' => $underlying
            ]);

            $representativeOption = $this->findRepresentativeOption($underlying);

            if (!$representativeOption) {
                return [
                    'success' => false,
                    'message' => "找不到 {$underlying} 的代表性契約"
                ];
            }

            $historicalDays = $parameters['historical_days'] ?? 100;
            $prices = $this->getHistoricalOptionPricesFromDB($representativeOption, $historicalDays);

            if (count($prices) < 30) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,ARIMA 模型需要至少 30 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => $representativeOption->option_code,
                'p' => $parameters['p'] ?? null,
                'd' => $parameters['d'] ?? null,
                'q' => $parameters['q'] ?? null,
                'auto_select' => $parameters['auto_select'] ?? true,
            ];

            $result = $this->executePythonModel('arima', $inputData);

            if ($result['success']) {
                $result['data_source'] = "TXO主力契約: {$representativeOption->option_code}";
                $result['historical_prices'] = $prices;

                $latestPrice = $representativeOption->latestPrice;
                $result['current_price'] = $latestPrice ? $latestPrice->close : null;
                $result['current_date'] = $latestPrice ? $latestPrice->trade_date : null;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('TXO 整體 ARIMA 預測失敗', [
                'underlying' => $underlying,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 執行 TXO 整體市場 GARCH 預測
     */
    public function runUnderlyingGARCHPrediction(string $underlying, int $predictionDays = 1, array $parameters = []): array
    {
        try {
            Log::info('開始執行 TXO 整體 GARCH 預測', [
                'underlying' => $underlying
            ]);

            $representativeOption = $this->findRepresentativeOption($underlying);

            if (!$representativeOption) {
                return [
                    'success' => false,
                    'message' => "找不到 {$underlying} 的代表性契約"
                ];
            }

            $historicalDays = $parameters['historical_days'] ?? 200;
            $prices = $this->getHistoricalOptionPricesFromDB($representativeOption, $historicalDays);

            if (count($prices) < 100) {
                return [
                    'success' => false,
                    'message' => "歷史資料不足,GARCH 模型需要至少 100 天的資料。"
                ];
            }

            $inputData = [
                'prices' => array_column($prices, 'close'),
                'dates' => array_column($prices, 'date'),
                'base_date' => Carbon::now()->format('Y-m-d'),
                'prediction_days' => $predictionDays,
                'option_code' => $representativeOption->option_code,
                'p' => $parameters['p'] ?? 1,
                'q' => $parameters['q'] ?? 1,
                'dist' => $parameters['dist'] ?? 'normal',
            ];

            $result = $this->executePythonModel('garch', $inputData);

            if ($result['success']) {
                $result['data_source'] = "TXO主力契約: {$representativeOption->option_code}";
                $result['historical_prices'] = $prices;

                $latestPrice = $representativeOption->latestPrice;
                $result['current_price'] = $latestPrice ? $latestPrice->close : null;
                $result['current_date'] = $latestPrice ? $latestPrice->trade_date : null;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('TXO 整體 GARCH 預測失敗', [
                'underlying' => $underlying,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '預測失敗: ' . $e->getMessage()
            ];
        }
    }

    // ========================================
    // 私有輔助方法
    // ========================================

    /**
     * 直接從資料庫取得股票歷史價格資料
     */
    private function getHistoricalPricesFromDB(Stock $stock, int $days = 100): array
    {
        $prices = StockPrice::where('stock_id', $stock->id)
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
            ->reverse()
            ->values()
            ->toArray();

        Log::info('從資料庫取得股票歷史價格', [
            'stock_id' => $stock->id,
            'requested_days' => $days,
            'actual_days' => count($prices)
        ]);

        return $prices;
    }

    /**
     * 🆕 直接從資料庫取得選擇權歷史價格資料
     */
    private function getHistoricalOptionPricesFromDB($option, int $days = 100): array
    {
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
            ->reverse()
            ->values()
            ->toArray();

        Log::info('從資料庫取得選擇權歷史價格', [
            'option_id' => $option->id,
            'option_code' => $option->option_code,
            'requested_days' => $days,
            'actual_days' => count($prices)
        ]);

        return $prices;
    }

    /**
     * 🆕 找到代表性選擇權契約
     * 策略: 選擇成交量最大的契約
     */
    private function findRepresentativeOption(string $underlying): ?Option
    {
        $option = Option::where('underlying', $underlying)
            ->where('is_active', true)
            ->where('expiry_date', '>=', now())
            ->whereHas('latestPrice', function ($query) {
                $query->whereNotNull('volume')
                    ->where('volume', '>', 0);
            })
            ->with('latestPrice')
            ->get()
            ->sortByDesc(function ($opt) {
                return $opt->latestPrice->volume ?? 0;
            })
            ->first();

        if (!$option) {
            $option = Option::where('underlying', $underlying)
                ->where('is_active', true)
                ->whereHas('prices')
                ->with('latestPrice')
                ->first();
        }

        return $option;
    }

    /**
     * 執行 Python 模型
     */
    private function executePythonModel(string $modelType, array $inputData): array
    {
        if (!isset(self::SUPPORTED_MODELS[$modelType])) {
            throw new \Exception("不支援的模型類型: {$modelType}");
        }

        $scriptPath = self::PYTHON_MODELS_PATH . self::SUPPORTED_MODELS[$modelType];
        $inputJson = json_encode($inputData, JSON_UNESCAPED_UNICODE);

        $tempFile = tempnam(sys_get_temp_dir(), 'prediction_input_');
        file_put_contents($tempFile, $inputJson);

        try {
            $command = "python3 {$scriptPath} '{$tempFile}'";
            $result = Process::timeout(120)->run($command);

            if (!$result->successful()) {
                Log::error('Python 腳本執行失敗', [
                    'model' => $modelType,
                    'error' => $result->errorOutput()
                ]);

                throw new \Exception("Python 模型執行失敗: " . $result->errorOutput());
            }

            $output = json_decode($result->output(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("無法解析 Python 輸出: " . json_last_error_msg());
            }

            return $output;
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
